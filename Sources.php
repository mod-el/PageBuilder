<?php namespace Model\PageBuilder;

use Model\Core\Core;

/**
 * Turns the bridge's global `sources` config (app/config/PageBuilder/config.php)
 * into the two things the editor needs:
 *
 *   - descriptors()  the `dataSources` array passed to the JS editor: per source,
 *                    a list of offerable fields (key/label/type[/source]) plus
 *                    optional item-picker metadata (`searchable`, `labelField`).
 *                    Fields may be declared explicitly or auto-introspected from
 *                    the ORM element's metadata.
 *   - sample()       editor-preview-only sample data: a few real items per source,
 *                    with `id`, multilang fields shaped as {lang: value} maps and
 *                    one level of relations expanded (so nested collections
 *                    preview).
 *   - search() / resolveItems()
 *                    host-fed item picker endpoints for searchable sources and
 *                    saved-document hydration.
 *
 * Both read the SAME `sources` map; see docs/dynamic-data.md §4.1 / §6. Sample is
 * never serialized into the document and never seen by the public renderer.
 *
 * Source config shape (keyed by an arbitrary source key referenced by bindings):
 *   'hotels' => ['element' => 'TravioService', 'where' => […], 'joins' => […], 'fields' => […], 'searchable' => true, 'labelField' => ['name'], 'filters' => […]]
 *   'custom' => ['retriever' => fn(array $filters, ?int $limit) => […list…], 'fields' => […]]  // fields required; filters include `q` for search, `id` for resolveItem, and any author list-filters (see below)
 *
 * `filters` whitelists the fields an author may equality-filter a list binding on
 * (docs/dynamic-data.md §4.2): each entry is either a bare field key ('stars') or
 * key => ['label' => …, 'options' => [value => label], 'source' => 'destinations']
 * (options turn the editor input into a dropdown; `source` links the filter to
 * another declared source — a conceptual foreign key — so the editor offers that
 * source's item picker and stores the picked id; the key must then be the scalar
 * FK column holding that id. `source` wins over `options`; an unknown source key
 * is dropped). Normalized to key => ['label' => ?string, 'source' => ?string,
 * 'options' => ?array]. Author-picked values reach ModelDataProvider as binding
 * params.filters, are validated against this whitelist (validFilters) and
 * AND-composed with the source's `where`; retriever sources receive them in
 * their first argument and are responsible for applying them.
 *
 * Field descriptor shape (editor contract): {key, label, type, source?, internal?}
 * where type ∈ {text, number, image, date, datetime, time, relation}; a relation
 * carries the target source key. date/datetime/time are plain scalars (raw value),
 * surfaced distinctly so the editor can offer date-format options on chips.
 * `internal` marks a synthesized relation-target source (see below) —
 * editor-only, never serialized, hidden from the top-level source pickers.
 *
 * Relations are introspected from the ORM element even when their target element
 * is NOT a declared source: such a relation points at a *synthesized* internal
 * source carrying just the target's scalar fields, so nested-pick ("the URL of the
 * first image", docs/dynamic-data.md §4.6) works against any relation. Both kinds of
 * ModEl relation are covered: element-backed (`SYNTH_PREFIX . elementClass`, scalars
 * from the target Element) and table-backed (`SYNTH_PREFIX . 'tbl_' . table`, scalars
 * from the table's columns + the relation's `fields` overrides). Synth sources hold
 * scalars only — no nested relations — which bounds the synthesis to one level
 * (matching nested-pick's "one level").
 *
 * Auto-introspection is best-effort and degrades gracefully (a source with no
 * derivable fields still resolves lists for bindings, it just offers no pickers);
 * declaring `fields` explicitly is the reliable path.
 */
class Sources
{
	// Key prefix for synthesized relation-target sources (a relation whose target
	// element is not a declared source). Internal: never serialized into a document,
	// filtered out of the editor's top-level source pickers.
	private const SYNTH_PREFIX = '__pbrel_';

	private Core $model;

	public function __construct(Core $model)
	{
		$this->model = $model;
	}

	/**
	 * Fill defaults and drop invalid entries. A valid source has either an
	 * `element` (ORM class) or a `retriever` (callable); a retriever-backed source
	 * must declare `fields` (nothing to introspect). Pure — no model access.
	 */
	public static function normalize(array $sources): array
	{
		// First pass: the surviving source keys, so a filter's `source` link can be
		// validated regardless of declaration order (forward references work).
		$validKeys = [];
		foreach ($sources as $key => $src) {
			if (self::isValidSource($key, $src))
				$validKeys[] = $key;
		}

		$out = [];
		foreach ($sources as $key => $src) {
			if (!self::isValidSource($key, $src))
				continue;

			$src['where'] = (isset($src['where']) and is_array($src['where'])) ? $src['where'] : [];
			$src['joins'] = (isset($src['joins']) and is_array($src['joins'])) ? $src['joins'] : [];
			$src['searchable'] = !empty($src['searchable']);
			$src['labelField'] = self::normalizeLabelField($src['labelField'] ?? null);
			$src['filters'] = self::normalizeFilters($src['filters'] ?? null, $validKeys);
			if (!isset($src['label']) or !is_string($src['label']))
				$src['label'] = ucfirst($key);

			$out[$key] = $src;
		}
		return $out;
	}

	// A valid source has either an `element` (ORM class) or a `retriever`
	// (callable); a retriever-backed source must declare `fields` (nothing to
	// introspect). Pure — no model access.
	private static function isValidSource($key, $src): bool
	{
		if (!is_string($key) or $key === '' or !is_array($src))
			return false;
		$hasElement = (isset($src['element']) and is_string($src['element']) and $src['element'] !== '');
		$hasRetriever = (isset($src['retriever']) and is_callable($src['retriever']));
		if (!$hasElement and !$hasRetriever)
			return false;
		if ($hasRetriever and !$hasElement and (!isset($src['fields']) or !is_array($src['fields'])))
			return false;
		return true;
	}

	/**
	 * Normalize the `filters` whitelist to key => ['label' => ?string, 'source' =>
	 * ?string, 'options' => ?array (value => label)]. Accepts bare field keys
	 * ('stars') or key => opts entries; malformed entries dropped. A `source` link
	 * (the filter's values are the ids of another declared source — a conceptual
	 * foreign key) is kept only when it names a key in $sourceKeys and WINS over
	 * `options` (mutually exclusive; an invalid link is dropped and the entry
	 * degrades to its options/typed-input behavior). Pure — no model access.
	 */
	private static function normalizeFilters($filters, array $sourceKeys = []): array
	{
		if (!is_array($filters))
			return [];
		$out = [];
		foreach ($filters as $key => $opts) {
			if (is_int($key) and is_string($opts) and $opts !== '') {
				$out[$opts] = [];
				continue;
			}
			if (!is_string($key) or $key === '' or !is_array($opts))
				continue;
			$entry = [];
			if (isset($opts['label']) and is_string($opts['label']) and $opts['label'] !== '')
				$entry['label'] = $opts['label'];
			if (isset($opts['source']) and is_string($opts['source']) and in_array($opts['source'], $sourceKeys, true))
				$entry['source'] = $opts['source'];
			if (!isset($entry['source']) and isset($opts['options']) and is_array($opts['options'])) {
				$options = [];
				foreach ($opts['options'] as $value => $label) {
					if (is_scalar($value) and is_scalar($label))
						$options[$value] = (string)$label;
				}
				if (!empty($options))
					$entry['options'] = $options;
			}
			$out[$key] = $entry;
		}
		return $out;
	}

	/**
	 * The subset of author-picked filters allowed by the source's whitelist: keys
	 * must be whitelisted plain field names (never `_url` — synthetic, not a
	 * column), values scalar. This is the ONLY gate between document content and
	 * the ORM where clause — never widen it to raw keys. Expects a normalized
	 * source entry. Pure — no model access.
	 */
	public static function validFilters(array $src, array $filters): array
	{
		$whitelist = (isset($src['filters']) and is_array($src['filters'])) ? $src['filters'] : [];
		$out = [];
		foreach ($filters as $key => $value) {
			if (!is_string($key) or $key === '_url' or !isset($whitelist[$key]) or !is_scalar($value))
				continue;
			$out[$key] = $value;
		}
		return $out;
	}

	/**
	 * Build the editor `dataSources` array (no sample — the endpoint adds that).
	 * Relations are emitted only when their target element maps to a declared
	 * source, so the JS field pickers (which key relations by source) stay valid.
	 */
	public function descriptors(array $sources): array
	{
		$sources = self::normalize($sources);

		// element class (short name) → source key, for mapping relations to a source
		$elementToKey = [];
		foreach ($sources as $key => $src) {
			if (isset($src['element']))
				$elementToKey[$src['element']] = $key;
		}

		// Synthesized internal sources for relation targets that are not declared
		// sources, deduped by synth key across all sources (built inside
		// introspectFields, which has each relationship's element/table/fields opts).
		$synth = [];

		$out = [];
		foreach ($sources as $key => $src) {
			$fields = (isset($src['fields']) and is_array($src['fields']))
				? self::normalizeFields($src['fields'])
				: $this->introspectFields($src['element'] ?? '', $elementToKey, $synth);

			$entry = [
				'key' => $key,
				'label' => $src['label'],
				'fields' => $fields,
			];
			if (!empty($src['searchable']))
				$entry['searchable'] = true;
			if (!empty($src['labelField']))
				$entry['labelField'] = count($src['labelField']) === 1 ? $src['labelField'][0] : $src['labelField'];
			$filters = $this->filterDescriptors($src, $fields);
			if (!empty($filters))
				$entry['filters'] = $filters;
			$out[] = $entry;
		}

		// Append the synthesized sources (skip any that collide with a real source key).
		$existing = array_fill_keys(array_map(static fn($d) => $d['key'], $out), true);
		foreach ($synth as $synthKey => $desc) {
			if (!isset($existing[$synthKey]))
				$out[] = $desc;
		}
		return $out;
	}

	/**
	 * Build the editor-facing filter descriptors for one source: [{key, label,
	 * type, source?, options?}]. Label falls back to the field descriptor's, then a
	 * humanized key; type comes from the field descriptor (default text). Keys
	 * whose field is a relation/image, or the synthetic `_url`, are dropped —
	 * equality on them is meaningless (and `_url` is not a column) — UNLESS the
	 * filter links a `source`: the key is then the scalar FK column holding that
	 * source's id (introspection hides FK columns, so the field is usually absent
	 * or shadowed by a same-named relation), so the drop is bypassed and a missing/
	 * relation/image type falls back to number (ModEl ids; the editor keeps
	 * non-numeric ids as strings). `source` and `options` never coexist (normalize).
	 */
	private function filterDescriptors(array $src, array $fields): array
	{
		if (empty($src['filters']))
			return [];

		$fieldByKey = [];
		foreach ($fields as $f)
			$fieldByKey[$f['key']] = $f;

		$out = [];
		foreach ($src['filters'] as $key => $opts) {
			if ($key === '_url')
				continue;
			$field = $fieldByKey[$key] ?? null;
			$type = $field['type'] ?? 'text';
			$linked = $opts['source'] ?? null;
			if ($type === 'relation' or $type === 'image') {
				if ($linked === null)
					continue;
				$type = 'number';
			}
			if ($linked !== null and $field === null)
				$type = 'number';

			$entry = [
				'key' => $key,
				'label' => $opts['label'] ?? $field['label'] ?? self::humanize($key),
				'type' => $type,
			];
			if ($linked !== null)
				$entry['source'] = $linked;
			if (!empty($opts['options'])) {
				$options = [];
				foreach ($opts['options'] as $value => $label)
					$options[] = ['value' => $value, 'label' => $label];
				$entry['options'] = $options;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Build editor-preview sample data: {sourceKey: [items]}. Each item is a plain
	 * array keyed by field; multilang text fields become {lang: value} maps,
	 * `image` fields resolve to a URL string, and `relation` fields expand one
	 * level into nested item arrays (deeper nesting is cut to avoid cycles).
	 */
	public function sample(array $sources, array $langs, int $perSource = 4): array
	{
		$sources = self::normalize($sources);
		if (empty($langs))
			$langs = ['it'];

		$descriptors = $this->descriptors($sources);
		$descByKey = [];
		foreach ($descriptors as $d)
			$descByKey[$d['key']] = $d['fields'];

		$provider = new ModelDataProvider($sources, $this->model);

		$out = [];
		foreach ($sources as $key => $src) {
			$items = $provider->query(['source' => $key], ['limit' => $perSource], null, $langs[0]);

			// Source-linked filter keys are FK columns that introspection hides from
			// the field descriptors (foreign_keys → skipped), yet the editor preview
			// equality-matches sample rows on them — inject the raw scalar alongside
			// the shaped fields (a same-named expanded relation, if any, wins).
			$linkedFilterKeys = [];
			foreach ($src['filters'] as $fk => $fopts) {
				if (isset($fopts['source']))
					$linkedFilterKeys[] = $fk;
			}

			$shaped = [];
			foreach ($items as $item) {
				$row = $this->shapeItem($item, $descByKey[$key] ?? [], $langs, $provider, $descByKey, 1);
				foreach ($linkedFilterKeys as $fk) {
					if (array_key_exists($fk, $row))
						continue;
					$val = $provider->resolve($item, $fk, $langs[0]);
					if (is_scalar($val))
						$row[$fk] = $val;
				}
				$shaped[] = $row;
			}
			$out[$key] = $shaped;
		}
		return $out;
	}

	public function search(array $sources, string $source, string $q, array $langs, int $limit = 10): array
	{
		$sources = self::normalize($sources);
		if (!isset($sources[$source]))
			return [];
		if (empty($langs))
			$langs = ['it'];

		$limit = max(1, min(50, $limit));
		$descriptors = $this->descriptors($sources);
		$descByKey = [];
		foreach ($descriptors as $d)
			$descByKey[$d['key']] = $d['fields'];

		$provider = new ModelDataProvider($sources, $this->model);
		$src = $sources[$source];
		$items = [];
		if (isset($src['retriever']) and is_callable($src['retriever'])) {
			try {
				$items = $this->toList($src['retriever'](['q' => $q], $limit));
			} catch (\Throwable $e) {
				$items = [];
			}
		} else {
			// Generic fallback: fetch a bounded candidate set and filter labels in PHP.
			// Hosts with large sources should provide a retriever/searchable config
			// tuned to their data model.
			$items = $provider->query(['source' => $source], ['limit' => max($limit * 5, $limit)], null, $langs[0]);
		}

		$out = [];
		foreach ($items as $item) {
			$row = $this->shapeItem($item, $descByKey[$source] ?? [], $langs, $provider, $descByKey, 0);
			if ($row['id'] === null)
				continue;
			$row['label'] = $this->labelForRow($row, $src, $descByKey[$source] ?? [], $langs);
			if ($q !== '' and stripos($row['label'], $q) === false and !$this->idMatches($row['id'] ?? null, $q))
				continue;
			$out[] = $row;
			if (count($out) >= $limit)
				break;
		}
		return $out;
	}

	/**
	 * Full item list for a (non-searchable) source's picker dropdown. Unlike
	 * sample() this is NOT bounded by sample-data-limit — the dropdown is meant to
	 * offer every item. Relations are not expanded (depth 0): the dropdown only
	 * needs id + label. $limit is an optional safety cap (null = all); large sources
	 * should be declared `searchable` instead.
	 */
	public function listItems(array $sources, string $source, array $langs, ?int $limit = null): array
	{
		$sources = self::normalize($sources);
		if (!isset($sources[$source]))
			return [];
		if (empty($langs))
			$langs = ['it'];

		$descriptors = $this->descriptors($sources);
		$descByKey = [];
		foreach ($descriptors as $d)
			$descByKey[$d['key']] = $d['fields'];

		$provider = new ModelDataProvider($sources, $this->model);
		$src = $sources[$source];
		if (isset($src['retriever']) and is_callable($src['retriever'])) {
			try {
				$items = $this->toList($src['retriever']([], $limit));
			} catch (\Throwable $e) {
				$items = [];
			}
		} else {
			$items = $provider->query(['source' => $source], $limit !== null ? ['limit' => $limit] : [], null, $langs[0]);
		}

		$out = [];
		foreach ($items as $item) {
			$row = $this->shapeItem($item, $descByKey[$source] ?? [], $langs, $provider, $descByKey, 0);
			if ($row['id'] === null)
				continue;
			$row['label'] = $this->labelForRow($row, $src, $descByKey[$source] ?? [], $langs);
			$out[] = $row;
		}
		return $out;
	}

	public function resolveItems(array $sources, string $source, array $ids, array $langs): array
	{
		$sources = self::normalize($sources);
		if (!isset($sources[$source]))
			return [];
		if (empty($langs))
			$langs = ['it'];

		$descriptors = $this->descriptors($sources);
		$descByKey = [];
		foreach ($descriptors as $d)
			$descByKey[$d['key']] = $d['fields'];

		$provider = new ModelDataProvider($sources, $this->model);
		$out = [];
		foreach ($ids as $id) {
			if (!is_string($id) and !is_numeric($id))
				continue;
			$item = $provider->resolveItem($source, $id, $langs[0]);
			if ($item === null)
				continue;
			$row = $this->shapeItem($item, $descByKey[$source] ?? [], $langs, $provider, $descByKey, 0);
			if ($row['id'] === null)
				continue;
			$row['label'] = $this->labelForRow($row, $sources[$source], $descByKey[$source] ?? [], $langs);
			$out[] = $row;
		}
		return $out;
	}

	// Shape one item against its field descriptors. $depth limits relation
	// expansion (1 = expand relations once, then stop).
	private function shapeItem(\Model\ORM\Element|array $item, array $fields, array $langs, ModelDataProvider $provider, array $descByKey, int $depth): array
	{
		$row = ['id' => $this->itemId($item)];
		foreach ($fields as $f) {
			$fk = $f['key'];
			$type = $f['type'] ?? 'text';

			if ($type === 'relation') {
				$row[$fk] = [];
				if ($depth > 0 and isset($f['source'])) {
					$sub = $provider->query(['relation' => $fk], [], $item, $langs[0]);
					$subFields = $descByKey[$f['source']] ?? [];
					foreach ($sub as $subItem)
						$row[$fk][] = $this->shapeItem($subItem, $subFields, $langs, $provider, $descByKey, $depth - 1);
				}
			} elseif ($type === 'text') {
				// multilang-friendly: a {lang: value} map (collapses to identical
				// values for non-multilang fields, which the editor handles fine)
				$map = [];
				foreach ($langs as $lang)
					$map[$lang] = (string)$provider->resolve($item, $fk, $lang);
				$row[$fk] = $map;
			} else { // number / image — not multilang
				$row[$fk] = $provider->resolve($item, $fk, $langs[0]);
			}
		}
		return $row;
	}

	/**
	 * Derive field descriptors from an ORM element's metadata. Combines the element's
	 * scalar fields (scalarFieldsOf: multilang, main-table columns, $fields overrides)
	 * with EVERY reflected relationship → type 'relation'. A relation's `source` is the
	 * declared source for its target element, or a synthesized internal source
	 * accumulated into `$synth` (keyed by synth key): element-backed relations
	 * introspect the target Element's scalars; table-backed relations (ModEl defaults
	 * `element` to the base `'Element'` and carries a `table`) introspect that table's
	 * columns + the relation's `fields` overrides. Best-effort: any failure yields an
	 * empty list (the source still works for list bindings, just without field pickers).
	 */
	public function introspectFields(string $elementClass, array $elementToKey = [], array &$synth = []): array
	{
		if ($elementClass === '')
			return [];

		try {
			$el = $this->model->_ORM->create($elementClass);
		} catch (\Throwable $e) {
			return [];
		}

		$fields = $this->scalarFieldsOf($el);

		// Relations: emit EVERY relationship. Point its `source` at a declared source
		// when the target element is one (keeps its configured label/fields), else at a
		// synthesized internal source carrying just the target's scalar fields.
		foreach ($this->reflectRelationships($el) as $relName => $opts) {
			$element = $opts['element'] ?? null;
			// ModEl sets `element` to the base 'Element' for a table-only relation, so
			// only a non-base class name counts as a real element target.
			$isRealElement = (is_string($element) and $element !== '' and $element !== 'Element');

			if ($isRealElement and isset($elementToKey[$element])) {
				$sourceKey = $elementToKey[$element];
			} elseif ($isRealElement) {
				$sourceKey = self::SYNTH_PREFIX . $element;
				if (!isset($synth[$sourceKey])) {
					$synth[$sourceKey] = [
						'key' => $sourceKey,
						'label' => self::humanize($element),
						'fields' => $this->introspectScalarFields($element),
						'internal' => true,
					];
				}
			} else {
				// Table-backed relation: introspect the table's columns directly. The
				// relation's `fields` option supplies type overrides (no Element class).
				$table = $opts['table'] ?? null;
				if (!is_string($table) or $table === '')
					continue; // nothing to introspect → don't offer a dead relation
				$sourceKey = self::SYNTH_PREFIX . 'tbl_' . $table;
				if (!isset($synth[$sourceKey])) {
					$overrides = is_array($opts['fields'] ?? null) ? $opts['fields'] : [];
					$synth[$sourceKey] = [
						'key' => $sourceKey,
						'label' => self::humanize($table),
						'fields' => $this->scalarFieldsOfTable($table, $overrides),
						'internal' => true,
					];
				}
			}

			$fields[] = [
				'key' => $relName,
				'label' => self::humanize($relName),
				'type' => 'relation',
				'source' => $sourceKey,
			];
		}

		return $fields;
	}

	// Scalar fields of an element (steps 1-3 of introspection: multilang, main-table
	// columns, element $fields overrides) WITHOUT relations. Used for synthesized
	// relation-target sources, so nested-pick stays one level deep.
	public function introspectScalarFields(string $elementClass): array
	{
		if ($elementClass === '')
			return [];
		try {
			$el = $this->model->_ORM->create($elementClass);
		} catch (\Throwable $e) {
			return [];
		}
		return $this->scalarFieldsOf($el);
	}

	private function scalarFieldsOf(\Model\ORM\Element $el): array
	{
		$table = $el->settings['table'] ?? null;
		$elementFields = is_array($el->settings['fields'] ?? null) ? $el->settings['fields'] : [];
		$fields = $this->scalarFieldsOfTable($table, $elementFields);

		// A controller-backed element exposes a public URL via getUrl(); offer it as a
		// synthetic bindable scalar so authors can link to the record (e.g. a button href).
		// Resolved at render time in ModelDataProvider::resolve.
		if (self::hasController($el))
			$fields[] = ['key' => '_url', 'label' => 'URL', 'type' => 'text'];

		return $fields;
	}

	// True when the element declares a non-empty static $controller (i.e. it is routable
	// and getUrl() can yield a public URL). Guarded — best-effort, like the rest of
	// introspection.
	private static function hasController(\Model\ORM\Element $el): bool
	{
		try {
			return $el::$controller !== null and $el::$controller !== '';
		} catch (\Throwable $e) {
			return false;
		}
	}

	// Steps 1-3 of introspection given a table name + a field-type override map (an
	// element's settings['fields'], or a table-based relation's `fields` option, which
	// is the only source of types when there's no Element class). Relations are NOT
	// included here, keeping synthesized sources one level deep.
	private function scalarFieldsOfTable(?string $table, array $elementFields): array
	{
		$db = null;
		try {
			$db = \Model\Db\Db::getConnection();
		} catch (\Throwable $e) {
		}

		$multilangFields = [];
		if ($db and $table and class_exists('\\Model\\Multilang\\Ml')) {
			try {
				$mlTables = \Model\Multilang\Ml::getTables($db);
				if (isset($mlTables[$table]['fields']) and is_array($mlTables[$table]['fields']))
					$multilangFields = $mlTables[$table]['fields'];
			} catch (\Throwable $e) {
			}
		}

		$fields = [];
		$seen = [];

		// 1) multilang text fields first (name/title/… are the useful display ones)
		foreach ($multilangFields as $fk) {
			if (isset($seen[$fk]))
				continue;
			$seen[$fk] = true;
			$fields[] = ['key' => $fk, 'label' => self::humanize($fk), 'type' => 'text'];
		}

		// 2) main-table scalar columns, skipping primary key + foreign keys
		if ($db and $table) {
			try {
				$tableModel = $db->getTable($table);
				$primary = is_array($tableModel->primary ?? null) ? $tableModel->primary : [];
				foreach (($tableModel->columns ?? []) as $col => $def) {
					if (isset($seen[$col]) or in_array($col, $primary, true))
						continue;
					if (!empty($def['foreign_keys']))
						continue; // FK pointer — better surfaced as a relation
					$override = $elementFields[$col]['type'] ?? null;
					$type = self::mapType($override, $def['type'] ?? null);
					if ($type === null)
						continue;
					$seen[$col] = true;
					$fields[] = ['key' => $col, 'label' => self::humanize($col), 'type' => $type];
				}
			} catch (\Throwable $e) {
			}
		}

		// 3) element $fields with explicit file/image type not already covered
		foreach ($elementFields as $fk => $def) {
			if (isset($seen[$fk]) or !is_array($def))
				continue;
			$type = self::mapType($def['type'] ?? null, null);
			if ($type === null)
				continue;
			$seen[$fk] = true;
			$fields[] = ['key' => $fk, 'label' => self::humanize($fk), 'type' => $type];
		}

		return $fields;
	}

	// Validate/normalize an explicitly-declared fields array into the descriptor
	// shape, defaulting label/type and dropping malformed entries.
	private static function normalizeLabelField($value): array
	{
		if (is_string($value) and $value !== '')
			return [$value];
		if (is_array($value)) {
			$out = [];
			foreach ($value as $field) {
				if (is_string($field) and $field !== '')
					$out[] = $field;
			}
			return array_values(array_unique($out));
		}
		return [];
	}

	private static function normalizeFields(array $fields): array
	{
		$out = [];
		foreach ($fields as $f) {
			if (!is_array($f) or !isset($f['key']) or !is_string($f['key']))
				continue;
			$entry = [
				'key' => $f['key'],
				'label' => (isset($f['label']) and is_string($f['label'])) ? $f['label'] : self::humanize($f['key']),
				'type' => (isset($f['type']) and is_string($f['type'])) ? $f['type'] : 'text',
			];
			if ($entry['type'] === 'relation' and isset($f['source']) and is_string($f['source']))
				$entry['source'] = $f['source'];
			$out[] = $entry;
		}
		return $out;
	}

	private function labelFields(array $src, array $fields): array
	{
		if (!empty($src['labelField']))
			return $src['labelField'];
		foreach ($fields as $f) {
			if (($f['type'] ?? 'text') === 'text')
				return [$f['key']];
		}
		return ['id'];
	}

	private function labelForRow(array $row, array $src, array $fields, array $langs): string
	{
		$parts = [];
		$lang = $langs[0] ?? 'it';
		foreach ($this->labelFields($src, $fields) as $field) {
			$value = $this->valueForLang($row[$field] ?? null, $lang);
			if ($value !== '')
				$parts[] = $value;
		}
		if (!empty($parts))
			return implode(' - ', $parts);
		return (string)($row['id'] ?? '');
	}

	private function valueForLang($value, string $lang): string
	{
		if (is_array($value)) {
			if (array_key_exists($lang, $value) and $value[$lang] !== null)
				return (string)$value[$lang];
			if (array_key_exists('it', $value) and $value['it'] !== null)
				return (string)$value['it'];
			foreach ($value as $v) {
				if ($v !== null)
					return (string)$v;
			}
			return '';
		}
		return $value === null ? '' : (string)$value;
	}

	private function itemId(\Model\ORM\Element|array $item)
	{
		if (is_array($item))
			return $item['id'] ?? null;
		try {
			return $item['id'];
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function idMatches($id, string $q): bool
	{
		return $id !== null and $q !== '' and stripos((string)$id, $q) !== false;
	}

	private function toList($value): array
	{
		if (is_array($value))
			return array_values($value);
		if ($value instanceof \Traversable)
			return array_values(iterator_to_array($value));
		return [];
	}

	// Read the protected ORM `relationships` map (name => options). No public
	// enumerator exists, so reflection — guarded; failure yields no relations.
	private function reflectRelationships(\Model\ORM\Element $el): array
	{
		try {
			$rp = new \ReflectionProperty($el, 'relationships');
			$rp->setAccessible(true);
			$rels = $rp->getValue($el);
			return is_array($rels) ? $rels : [];
		} catch (\Throwable $e) {
			return [];
		}
	}

	// Map an element field-type override and/or a DB column type to a descriptor
	// type. Returns null to skip the field (e.g. password, unknown).
	private static function mapType(?string $elementType, ?string $columnType): ?string
	{
		if ($elementType !== null) {
			switch ($elementType) {
				case 'file':
					return 'image';
				case 'password':
					return null;
				case 'number':
					return 'number';
				// Surfaced distinctly so the chip editor can offer date-format options.
				case 'date':
					return 'date';
				case 'time':
					return 'time';
				case 'datetime':
					return 'datetime';
				case 'text':
				case 'textarea':
				case 'ckeditor':
				case 'select':
				case 'radio':
				case 'color':
					return 'text';
			}
		}

		if ($columnType !== null) {
			$col = strtolower($columnType);
			$numeric = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'float', 'double'];
			if (in_array($col, $numeric, true))
				return 'number';
			if ($col === 'date')
				return 'date';
			if ($col === 'time')
				return 'time';
			if ($col === 'datetime' or $col === 'timestamp')
				return 'datetime';
			return 'text';
		}

		return null;
	}

	private static function humanize(string $key): string
	{
		return ucfirst(str_replace(['_', '-'], ' ', $key));
	}
}
