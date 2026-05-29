<?php namespace Model\PageBuilder\Providers;

use Model\Router\AbstractRouterProvider;

class RouterProvider extends AbstractRouterProvider
{
	public static function getRoutes(): array
	{
		return [
			[
				'pattern' => 'page-builder-sample-data',
				'controller' => 'PageBuilderSampleData',
			],
		];
	}
}
