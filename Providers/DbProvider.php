<?php namespace Model\PageBuilder\Providers;

use Model\Db\AbstractDbProvider;

/**
 * Registers the bundled migrations so the global fragment library table
 * (`page_builder_fragments`, backing the `PageBuilderFragment` Element) is
 * created automatically on `model migrate`. Discovered via providers-finder, like
 * the Router/Assets providers.
 */
class DbProvider extends AbstractDbProvider
{
	public static function getMigrationsPaths(): array
	{
		return [
			[
				// Relative to the module root, mirroring the Assets/Router path
				// convention (`model/PageBuilder/...`). The folder is dropped into
				// the host as `app/model/PageBuilder/migrations`.
				'path' => 'model/PageBuilder/migrations',
			],
		];
	}
}
