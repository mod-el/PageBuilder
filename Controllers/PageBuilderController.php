<?php namespace Model\PageBuilder\Controllers;

use Model\Core\Controller;

/**
 * Editor support endpoints for the page-builder field, under one route
 * (`page-builder`, Providers/RouterProvider.php). The action is the URL extension
 * segment after the route, read via `$this->model->getRequest(1)` (segment 0 is
 * the route itself):
 *
 *   GET  page-builder/sample-data  → { "sources": { "<key>": [ …sample items… ] } }
 *   POST page-builder/render-node  → { "html": "…" }   (body: { node, lang })
 *
 * Both rely on the admin-path auth posture (same as files/upload.php) — no
 * element/field params are accepted, so neither can be used to dump arbitrary data.
 */
class PageBuilderController extends Controller
{
	// GET actions. `sample-data`: editor-preview sample data for the configured
	// dynamic-data sources (fetched once at field mount, merged into the
	// `dataSources` descriptors). Preview-only — never stored, never seen by the
	// public renderer.
	public function get()
	{
		if ($this->model->getRequest(1) !== 'sample-data')
			return $this->notFound();

		try {
			return ['sources' => $this->model->_PageBuilder->sampleData()];
		} catch (\Throwable $e) {
			http_response_code(500);
			return ['error' => ['message' => $e->getMessage()]];
		}
	}

	// POST actions. `render-node`: render one node to preview HTML for a custom
	// server-rendered component (the editor has no JS render for those; the PHP
	// template is the source of truth, so preview === public output). The node is
	// wrapped in a one-node document and rendered via the Renderer in editor mode,
	// so a component's optional `placeholder_template` stands in for its real one.
	public function post()
	{
		if ($this->model->getRequest(1) !== 'render-node')
			return $this->notFound();

		try {
			$raw = file_get_contents('php://input');
			$payload = is_string($raw) ? json_decode($raw, true) : null;
			if (!is_array($payload) or !isset($payload['node']) or !is_array($payload['node']))
				throw new \Exception('invalid payload: expected { node, lang }');

			$lang = (isset($payload['lang']) and is_string($payload['lang']) and $payload['lang'] !== '') ? $payload['lang'] : null;
			$doc = ['version' => 1, 'root' => [$payload['node']]];

			return ['html' => $this->model->_PageBuilder->render($doc, $lang, true)];
		} catch (\Throwable $e) {
			http_response_code(500);
			return ['error' => ['message' => $e->getMessage()]];
		}
	}

	private function notFound(): array
	{
		http_response_code(404);
		return ['error' => ['message' => 'unknown page-builder action']];
	}
}
