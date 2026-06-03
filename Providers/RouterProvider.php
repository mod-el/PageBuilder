<?php namespace Model\PageBuilder\Providers;

use Model\Router\AbstractRouterProvider;

class RouterProvider extends AbstractRouterProvider
{
	public static function getRoutes(): array
	{
		return [
			// One route; the action is the URL extension segment (page-builder/
			// sample-data, page-builder/render-node), dispatched in the controller
			// via $this->model->getRequest(1) (segment 0 is the route itself).
			[
				'pattern' => 'page-builder',
				'controller' => 'PageBuilder',
			],
		];
	}
}
