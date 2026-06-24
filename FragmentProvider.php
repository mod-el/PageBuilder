<?php namespace Model\PageBuilder;

/**
 * Optional fragment library resolver for linked `fragment` component instances.
 *
 * Implementations return either a full PageBuilder document (`{version, root}`)
 * or the root Node[] directly. The renderer normalizes both and treats null as a
 * missing/deleted fragment.
 */
interface FragmentProvider
{
	public function get(string $id): ?array;
}
