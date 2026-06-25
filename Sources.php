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
 *   'hotels' => ['element' => 'TravioService', 'where' => […], 'joins' => […], 'fields' => […], 'searchable' => true, 'labelField' => ['name']]
 *   'custom' => ['retriever' => fn(array $filters, ?int $limit) => […list…], 'fields' => […]]  // fields required; filters include `q` for search and `id` for resolveItem
 *
 * Field descriptor shape (editor contract): {key, label, type, source?, internal?}
 * where type ∈ {text, number, image, relation}; a relation carries the target
 * source key. `internal` marks a synthesized relation-target source (see below) —
 * editor-only, never serialized, hidden from the top-level source pickers.
 *
 * Relations are introspected from the ORM element even when their target element
 * is NOT a declared source: such a relation points at a *synthesized* internal
 * source (key `SYNTH_PREFIX . elementClass`) carrying just the target's scalar
 * fields, so nested-pick ("the URL of the first image", docs/dynamic-data.md §4.6)
 * works against any relation. Synth sources hold scalars only — no nested relations
 * — which bounds the synthesis to one level (matching nested-pick's "one level").
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
		$out = [];
		foreach ($sources as $key => $src) {
			if (!is_string($key) or $key === '' or !is_array($src))
				continue;

			$hasElement = (isset($src['element']) and is_string($src['element']) and $src['element'] !== '');
			$hasRetriever = (isset($src['retriever']) and is_callable($src['retriever']));
			if (!$hasElement and !$hasRetriever)
				continue;
			if ($hasRetriever and !$hasElement and (!isset($src['fields']) or !is_array($src['fields'])))
				continue; // a retriever source can't be introspected — fields are mandatory

			$src['where'] = (isset($src['where']) and is_array($src['where'])) ? $src['where'] : [];
			$src['joins'] = (isset($src['joins']) and is_array($src['joins'])) ? $src['joins'] : [];
			$src['searchable'] = !empty($src['searchable']);
			$src['labelField'] = self::normalizeLabelField($src['labelField'] ?? null);
			if (!isset($src['label']) or !is_string($src['label']))
				$src['label'] = ucfirst($key);

			$out[$key] = $src;
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

		$out = [];
		foreach ($sources as $key => $src) {
			$fields = (isset($src['fields']) and is_array($src['fields']))
				? self::normalizeFields($src['fields'])
				: $this->introspectFields($src['element'] ?? '', $elementToKey);

			$entry = [
				'key' => $key,
				'label' => $src['label'],
				'fields' => $fields,
			];
			if (!empty($src['searchable']))
				$entry['searchable'] = true;
			if (!empty($src['labelField']))
				$entry['labelField'] = count($src['labelField']) === 1 ? $src['labelField'][0] : $src['labelField'];
			$out[] = $entry;
		}

		// Synthesize an internal source for every relation target that is not a
		// declared source, so its scalar fields are offerable as nested-pick
		// sub-fields. Synth sources carry scalars only → no further synth keys, so a
		// single pass suffices.
		$existingKeys = array_map(static fn($d) => $d['key'], $out);
		foreach (self::collectSynthTargets($out, $existingKeys) as $synthKey => $elementClass) {
			$out[] = [
				'key' => $synthKey,
				'label' => self::humanize($elementClass),
				'fields' => $this->introspectScalarFields($elementClass),
				'internal' => true,
			];
		}
		return $out;
	}

	/**
	 * Pure: scan built descriptors for relation `source` keys that are synthesized
	 * (SYNTH_PREFIX) and not already present, returning [synthKey => elementClass],
	 * deduped (two relations to the same element collapse to one). No model access —
	 * unit-testable.
	 */
	public static function collectSynthTargets(array $descriptors, array $existingKeys): array
	{
		$existing = array_fill_keys($existingKeys, true);
		$prefixLen = strlen(self::SYNTH_PREFIX);
		$out = [];
		foreach ($descriptors as $d) {
			if (empty($d['fields']) or !is_array($d['fields']))
				continue;
			foreach ($d['fields'] as $f) {
				if (($f['type'] ?? null) !== 'relation')
					continue;
				$src = $f['source'] ?? null;
				if (!is_string($src) or strncmp($src, self::SYNTH_PREFIX, $prefixLen) !== 0)
					continue;
				if (isset($existing[$src]) or isset($out[$src]))
					continue;
				$out[$src] = substr($src, $prefixLen);
			}
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
			$shaped = [];
			foreach ($items as $item)
				$shaped[] = $this->shapeItem($item, $descByKey[$key] ?? [], $langs, $provider, $descByKey, 1);
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
	 * declared source for its target element, or a synthesized internal key
	 * (SYNTH_PREFIX . target) that descriptors() materializes. Best-effort: any failure
	 * yields an empty list (the source still works for list bindings, just without
	 * field pickers).
	 */
	public function introspectFields(string $elementClass, array $elementToKey = []): array
	{
		if ($elementClass === '')
			return [];

		try {
			$el = $this->model->_ORM->create($elementClass);
		} catch (\Throwable $e) {
			return [];
		}

		$fields = $this->scalarFieldsOf($el);

		// Relations: emit EVERY relationship with a target element. When the target
		// is a declared source, point at it (keeps its configured label/fields);
		// otherwise point at a synthesized internal source (built by descriptors()).
		// Table-only relations (no `element`) are skipped — nothing to introspect.
		foreach ($this->reflectRelationships($el) as $relName => $opts) {
			$target = $opts['element'] ?? null;
			if (!is_string($target) or $target === '')
				continue;
			$sourceKey = $elementToKey[$target] ?? (self::SYNTH_PREFIX . $target);
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
				case 'text':
				case 'textarea':
				case 'ckeditor':
				case 'select':
				case 'radio':
				case 'date':
				case 'time':
				case 'datetime':
				case 'color':
					return 'text';
			}
		}

		if ($columnType !== null) {
			$numeric = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'float', 'double'];
			if (in_array(strtolower($columnType), $numeric, true))
				return 'number';
			return 'text';
		}

		return null;
	}

	private static function humanize(string $key): string
	{
		return ucfirst(str_replace(['_', '-'], ' ', $key));
	}
}
