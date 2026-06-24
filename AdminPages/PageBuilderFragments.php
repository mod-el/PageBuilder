<?php namespace Model\PageBuilder\AdminPages;

use Model\Admin\AdminPage;

/**
 * Admin page for the global fragment library — CRUD over the
 * `PageBuilderFragment` Element (table `page_builder_fragments`). The `doc` field
 * is the `page-builder` Form field type, so editing a fragment mounts the very
 * same editor used everywhere else; `name`/`category` drive the editor's
 * "Frammenti" palette category. Registered in the admin menu by the sibling
 * `Providers/AdminProvider`.
 */
class PageBuilderFragments extends AdminPage
{
	public function options(): array
	{
		return [
			'element' => 'PageBuilderFragment',
			'order_by' => 'category, name',
			'fields' => [
				'name',
				'category',
			],
		];
	}
}
