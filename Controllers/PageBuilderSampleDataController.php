<?php namespace Model\PageBuilder\Controllers;

use Model\Core\Controller;

/**
 * Editor-preview sample-data endpoint for the page-builder field.
 *
 * Returns `{ "sources": { "<key>": [ …sample items… ] } }` for the data sources
 * declared in the PageBuilder module config (app/config/PageBuilder/config.php).
 * The editor field fetches this once at mount (files/init.js) and merges the
 * items into the `dataSources` descriptors so dynamic content previews with real
 * values (see docs/dynamic-data.md §4.1 / §6). Sample data is preview-only — it
 * is never stored in the document nor seen by the public renderer.
 *
 * Routed as `page-builder-sample-data` (Providers/RouterProvider.php). Only the
 * configured sources are ever exposed (no element/field params), so it can't be
 * used to dump arbitrary data. Auth posture matches files/upload.php (it relies
 * on the admin path).
 */
class PageBuilderSampleDataController extends Controller
{
	public function get()
	{
		try {
			return ['sources' => $this->model->_PageBuilder->sampleData()];
		} catch (\Throwable $e) {
			http_response_code(500);
			return ['error' => ['message' => $e->getMessage()]];
		}
	}
}
