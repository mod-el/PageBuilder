<?php namespace Model\PageBuilder\Providers;

use Model\Admin\AbstractAdminProvider;

/**
 * Registers the fragment-library admin page into the admin menu. Discovered via
 * providers-finder, like the Router/Assets/Db providers.
 */
class AdminProvider extends AbstractAdminProvider
{
	public static function getAdditionalPages(): array
	{
		return [
			[
				'name' => 'Frammenti',
				'page' => 'PageBuilderFragments',
				'rule' => 'page-builder-fragments',
			],
		];
	}
}
