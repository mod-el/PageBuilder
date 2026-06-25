<?php namespace Model\PageBuilder;

use Model\Core\Core;

require_once __DIR__ . '/DataProvider.php';

/**
 * ModEl implementation of the page-builder DataProvider (docs/dynamic-data.md §5).
 * Resolves bindings to ModEl ORM element lists and fields to scalar/HTML values:
 *
 *   query({source})    → $model->_ORM->all(element, where, …) or a retriever closure
 *   query({relation})  → the scope element's ORM relation (e.g. a hotel's rooms)
 *   resolve(item,…)    → multilang field via the `lang:field` accessor, or an
 *                        image field resolved to its public URL
 *
 * Constructed from the bridge's normalized global `sources` config + the Core
 * model. No provider / unknown source → empty (the contract's "no fallbacks for
 * unavailable data" stance, matching the JS SampleDataProvider).
 */
class ModelDataProvider implements DataProvider
{
	private array $sources;

	public function __construct(array $sources, private Core $model, private string $defaultLang = 'it')
	{
		$this->sources = Sources::normalize($sources);
	}

	public function query(array $binding, array $params, $scope, string $lang): array
	{
		$limit = (isset($params['limit']) and is_numeric($params['limit'])) ? (int)$params['limit'] : null;

		if (isset($binding['source']) and is_string($binding['source']))
			return $this->querySource($binding['source'], $limit);

		if (isset($binding['relation']) and is_string($binding['relation']))
			return $this->queryRelation($scope, $binding['relation'], $limit);

		// { query } (named queries) is deferred; anything else is treated as absent
		return [];
	}

	public function resolve($item, string $field, string $lang)
	{
		if (is_object($item) and method_exists($item, 'offsetGet')) {
			// ORM Element. An image/file field resolves to its public URL.
			$settingsFields = (isset($item->settings['fields']) and is_array($item->settings['fields'])) ? $item->settings['fields'] : [];
			if (($settingsFields[$field]['type'] ?? null) === 'file') {
				$path = null;
				try {
					$path = $item->getFilePath($field);
				} catch (\Throwable $e) {
				}
				if (!is_string($path) or $path === '')
					return '';
				return (defined('PATH') ? PATH : '') . $path;
			}

			// Multilang: the `lang:field` accessor returns the value for that exact
			// language (null when the field isn't multilang or has no such lang);
			// fall back to the plain accessor (current-lang / scalar value).
			$val = $item[$lang . ':' . $field];
			if ($val === null or $val === '')
				$val = $item[$field];
			return $val ?? '';
		}

		if (is_array($item)) {
			if (!array_key_exists($field, $item))
				return '';
			$val = $item[$field];
			if (is_array($val))
				return $this->resolveValue($val, $lang);
			return $val;
		}

		return '';
	}

	public function resolveItem(string $source, $id, string $lang)
	{
		if (!isset($this->sources[$source]))
			return null;
		$src = $this->sources[$source];

		if (isset($src['retriever']) and is_callable($src['retriever'])) {
			try {
				$items = $this->toList($src['retriever'](['id' => $id], 1));
			} catch (\Throwable $e) {
				return null;
			}
			foreach ($items as $item) {
				if ($this->idsEqual($this->itemId($item), $id))
					return $item;
			}
			return null;
		}

		if (!isset($src['element']))
			return null;

		$where = $src['where'] ?? [];
		$where['id'] = $id;
		$options = ['stream' => false, 'limit' => 1];
		if (!empty($src['joins']))
			$options['joins'] = $src['joins'];

		$item = $this->model->_ORM->one($src['element'], $where, $options);
		return ($item and $item->exists()) ? $item : null;
	}

	// Resolve a {source} binding to a list of elements (or a retriever's items).
	private function querySource(string $key, ?int $limit): array
	{
		if (!isset($this->sources[$key]))
			return [];
		$src = $this->sources[$key];

		if (isset($src['retriever']) and is_callable($src['retriever'])) {
			$items = $src['retriever']([], $limit); // First argument is reserved for filters
			$items = $this->toList($items);
			return ($limit !== null) ? array_slice($items, 0, max(0, $limit)) : $items;
		}

		if (!isset($src['element']))
			return [];

		$options = ['stream' => false];
		if (!empty($src['joins']))
			$options['joins'] = $src['joins'];
		if (!empty($src['group_by']))
			$options['group_by'] = $src['group_by'];
		if ($limit !== null)
			$options['limit'] = max(0, $limit);

		try {
			$items = $this->model->_ORM->all($src['element'], $src['where'] ?? [], $options);
		} catch (\Throwable $e) {
			return [];
		}
		return $this->toList($items);
	}

	// Resolve a {relation} binding against the current scope (an ORM element whose
	// relation is read as a property, or an array item from a retriever/sample).
	private function queryRelation($scope, string $relation, ?int $limit): array
	{
		$items = [];
		if (is_object($scope)) {
			try {
				$items = $this->toList($scope->{$relation});
			} catch (\Throwable $e) {
				$items = [];
			}
		} elseif (is_array($scope) and isset($scope[$relation])) {
			$items = $this->toList($scope[$relation]);
		}
		return ($limit !== null) ? array_slice($items, 0, max(0, $limit)) : $items;
	}

	// Coerce an ORM result (array, generator/collection, or null) into a plain list.
	private function toList($value): array
	{
		if (is_array($value))
			return array_values($value);
		if ($value instanceof \Traversable)
			return array_values(iterator_to_array($value));
		return [];
	}

	private function itemId($item)
	{
		if (is_array($item))
			return $item['id'] ?? null;
		if (is_object($item) and method_exists($item, 'offsetGet')) {
			try {
				return $item['id'];
			} catch (\Throwable $e) {
			}
		}
		if (is_object($item) and isset($item->id))
			return $item->id;
		return null;
	}

	private function idsEqual($a, $b): bool
	{
		if ($a === null or $b === null)
			return false;
		return (string)$a === (string)$b;
	}

	// Multilang fallback for array items (retriever/sample maps): requested lang →
	// default lang → first non-null → ''. Mirrors the renderer's resolveValue.
	private function resolveValue(array $value, string $lang)
	{
		if (array_key_exists($lang, $value) and $value[$lang] !== null)
			return $value[$lang];
		if (array_key_exists($this->defaultLang, $value) and $value[$this->defaultLang] !== null)
			return $value[$this->defaultLang];
		foreach ($value as $v) {
			if ($v !== null)
				return $v;
		}
		return '';
	}
}
