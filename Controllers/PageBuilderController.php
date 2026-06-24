<?php namespace Model\PageBuilder\Controllers;

use Model\Core\Controller;

/**
 * Editor support endpoints for the page-builder field, under one route
 * (`page-builder`, Providers/RouterProvider.php). The action is the URL extension
 * segment after the route, read via `$this->model->getRequest(1)` (segment 0 is
 * the route itself):
 *
 *   GET  page-builder/sample-data  → { "sources": { "<key>": [ …sample items… ] } }
 *   GET  page-builder/search?source=…&q=… → { "items": [ … ] }
 *   GET  page-builder/resolve-items?source=…&ids=… → { "items": [ … ] }
 *   GET  page-builder/fragments → { "fragments": [ … ] }
 *   POST page-builder/fragments → { "id": "…" }       (body: { name, category, source, doc })
 *   DELETE page-builder/fragments?id=… → { "ok": true }
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
		try {
			switch ($this->model->getRequest(1)) {
				case 'sample-data':
					return ['sources' => $this->model->_PageBuilder->sampleData()];
				case 'search':
					$source = isset($_GET['source']) ? (string)$_GET['source'] : '';
					$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
					$limit = (isset($_GET['limit']) and is_numeric($_GET['limit'])) ? (int)$_GET['limit'] : 10;
					return ['items' => $this->model->_PageBuilder->searchItems($source, $q, $limit)];
				case 'resolve-items':
					$source = isset($_GET['source']) ? (string)$_GET['source'] : '';
					$idsRaw = $_GET['ids'] ?? [];
					if (is_string($idsRaw))
						$ids = array_values(array_filter(array_map('trim', explode(',', $idsRaw)), static fn($id) => $id !== ''));
					elseif (is_array($idsRaw))
						$ids = array_values(array_filter($idsRaw, static fn($id) => is_string($id) or is_numeric($id)));
					else
						$ids = [];
					return ['items' => $this->model->_PageBuilder->resolveItems($source, $ids)];
				case 'fragments':
					return ['fragments' => $this->model->_PageBuilder->fragments()];
			}
			return $this->notFound();
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
		try {
			if ($this->model->getRequest(1) === 'fragments') {
				$raw = file_get_contents('php://input');
				$payload = is_string($raw) ? json_decode($raw, true) : null;
				if (!is_array($payload))
					throw new \Exception('invalid payload: expected { name, category, source, doc }');
				$name = isset($payload['name']) ? trim((string)$payload['name']) : '';
				$category = isset($payload['category']) ? trim((string)$payload['category']) : '';
				$source = (isset($payload['source']) and is_string($payload['source']) and $payload['source'] !== '') ? trim($payload['source']) : null;
				$doc = (isset($payload['doc']) and is_array($payload['doc'])) ? $payload['doc'] : null;
				if ($name === '' or $doc === null)
					throw new \Exception('invalid payload: name and doc are required');
				$id = $this->model->_PageBuilder->saveFragment($name, $category, $source, $doc);
				if ($id === null)
					throw new \Exception('fragment save failed');
				return ['id' => $id];
			}

			if ($this->model->getRequest(1) !== 'render-node')
				return $this->notFound();

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
