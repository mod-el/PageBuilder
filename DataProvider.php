<?php namespace Model\PageBuilder;

/**
 * Data-provider interface for dynamic / data-bound content (ModEl-namespaced
 * copy of php-renderer/DataProvider.php). The bridge implements it against
 * ModEl element queries + field/multilang resolution. See docs/dynamic-data.md.
 *
 * Mirror of the JS SampleDataProvider's two methods (src/core/data-provider.js).
 */
interface DataProvider
{
	/**
	 * Resolve a binding to a list of items.
	 *
	 * @param array $binding {source,params} | {relation} | {query}
	 * @param array $params  the resolved params map (the framework passes binding.params)
	 * @param mixed $scope   the current item (null at root)
	 * @param string $lang   active language
	 * @return array         list of items (possibly empty; never null)
	 */
	public function query(array $binding, array $params, $scope, string $lang): array;

	/**
	 * Resolve one field of one item to a scalar/HTML value for the lang. Does
	 * NOT escape — the renderer / template escapes.
	 *
	 * @param mixed $item   one item from a queried list (or the current scope)
	 * @param string $field field key
	 * @param string $lang  active language
	 * @return mixed
	 */
	public function resolve($item, string $field, string $lang);

	/**
	 * Resolve one source item by id. Returns null when not found. The concrete
	 * item may be an array or a host object (for example an ORM Element).
	 *
	 * @param string $source configured source key
	 * @param mixed $id      host-owned id
	 * @param string $lang   active language
	 * @return mixed|null
	 */
	public function resolveItem(string $source, $id, string $lang);
}
