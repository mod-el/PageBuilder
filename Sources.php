<?php namespace Model\PageBuilder;

use Model\Core\Core;

/**
 * Turns the bridge's global `sources` config (app/config/PageBuilder/config.php)
 * into the two things the editor needs:
 *
 *   - descriptors()  the `dataSources` array passed to the JS editor: per source,
 *                    a list of offerable fields (key/label/type[/source]). Fields
 *                    may be declared explicitly or auto-introspected from the ORM
 *                    element's metadata.
 *   - sample()       editor-preview-only sample data: a few real items per source,
 *                    with multilang fields shaped as {lang: value} maps and one
 *                    level of relations expanded (so nested collections preview).
 *
 * Both read the SAME `sources` map; see docs/dynamic-data.md §4.1 / §6. Sample is
 * never serialized into the document and never seen by the public renderer.
 *
 * Source config shape (keyed by an arbitrary source key referenced by bindings):
 *   'hotels' => ['element' => 'TravioService', 'where' => […], 'joins' => […], 'fields' => […]]
 *   'custom' => ['retriever' => fn() => […list…], 'fields' => […]]  // fields required
 *
 * Field descriptor shape (editor contract): {key, label, type, source?} where
 * type ∈ {text, number, image, relation}; a relation carries the target source key.
 *
 * Auto-introspection is best-effort and degrades gracefully (a source with no
 * derivable fields still resolves lists for bindings, it just offers no pickers);
 * declaring `fields` explicitly is the reliable path.
 */
class Sources
{
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

			$out[] = [
				'key' => $key,
				'label' => $src['label'],
				'fields' => $fields,
			];
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

	// Shape one item against its field descriptors. $depth limits relation
	// expansion (1 = expand relations once, then stop).
	private function shapeItem(\Model\ORM\Element|array $item, array $fields, array $langs, ModelDataProvider $provider, array $descByKey, int $depth): array
	{
		$row = [];
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
	 * Derive field descriptors from an ORM element's metadata. Combines:
	 *   - multilang fields (the *_texts columns) → type 'text'
	 *   - main-table scalar columns → 'number' (numeric) or 'text', FK/system cols skipped
	 *   - element $fields type overrides ('file' → 'image')
	 *   - relations (reflected) whose target maps to a declared source → 'relation'
	 * Best-effort: any failure yields an empty list (the source still works for
	 * list bindings, just without field pickers).
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

		// 4) relations whose target element maps to a declared source
		foreach ($this->reflectRelationships($el) as $relName => $opts) {
			$target = $opts['element'] ?? null;
			if (!is_string($target) or !isset($elementToKey[$target]))
				continue;
			$fields[] = [
				'key' => $relName,
				'label' => self::humanize($relName),
				'type' => 'relation',
				'source' => $elementToKey[$target],
			];
		}

		return $fields;
	}

	// Validate/normalize an explicitly-declared fields array into the descriptor
	// shape, defaulting label/type and dropping malformed entries.
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
