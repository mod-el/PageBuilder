<?php namespace Model\PageBuilder;

use Model\Core\Module;
use Model\PageBuilder\Renderer;
use Model\PageBuilder\ModelDataProvider;
use Model\PageBuilder\Sources;
use Model\PageBuilder\Components;

/**
 * Page-builder ModEl bridge module.
 *
 * Drop this folder into a ModEl project's `app/model/PageBuilder/` and declare
 * a column's field type as `page-builder` (against a `json` DB column).
 *
 * Public-side rendering:
 *   echo $this->model->_PageBuilder->render($element['contenuto']);
 *
 * The value may be either a JSON string (raw column read) or a pre-decoded
 * array (ModEl's `json` column type returns arrays).
 */
class PageBuilder extends Module
{
	private ?Renderer $renderer = null;
	private ?Renderer $editorRenderer = null;

	// $forEditor selects the editor-preview renderer, where a custom component's
	// optional `placeholder_template` stands in for its real `template` (lighter /
	// neutral markup while authoring). Only the render-node route passes it true;
	// public rendering always uses the real templates.
	public function render($value, ?string $lang = null, bool $forEditor = false): string
	{
		if ($value === null or $value === '')
			return '';

		if (is_string($value)) {
			$decoded = json_decode($value, true);
			if (!is_array($decoded))
				return '';
			$value = $decoded;
		}

		if (!is_array($value))
			return '';

		$lang ??= $this->currentLang();
		return $this->getRenderer($forEditor)->render($value, ['lang' => $lang]);
	}

	public function getRenderer(bool $forEditor = false): Renderer
	{
		$cached = $forEditor ? $this->editorRenderer : $this->renderer;
		if ($cached !== null)
			return $cached;

		$components = $this->components();
		// No custom components → null registry/templateMap, so the Renderer loads
		// the built-in registry.php and behaves exactly as before.
		$registry = null;
		$templateMap = null;
		if (!empty($components)) {
			$registry = array_merge(require __DIR__ . '/registry.php', Components::registryMeta($components));
			// Editor preview prefers placeholder_template where a component declares one.
			$templateMap = Components::templateMap($components, $forEditor);
		}
		$renderer = new Renderer(__DIR__ . '/renderer', $this->currentLang(), $registry, $this->buildProvider(), $templateMap);

		if ($forEditor)
			$this->editorRenderer = $renderer;
		else
			$this->renderer = $renderer;
		return $renderer;
	}

	// Custom components from two sources, merged: packages that ship an
	// AbstractPageBuilderProvider (discovered via providers-finder) and the host's
	// global `components` config. The host config wins on a key collision, so a
	// host can override or disable a provider-supplied type by redeclaring it.
	// Empty when neither source contributes anything.
	private function components(): array
	{
		$config = $this->retrieveConfig();
		$configComponents = (isset($config['components']) and is_array($config['components'])) ? $config['components'] : [];

		$providerComponents = [];
		// providers-finder is a framework dep; guard + swallow so a broken or absent
		// provider never breaks the editor (graceful degradation, like Sources).
		if (class_exists('\\Model\\ProvidersFinder\\Providers')) {
			try {
				foreach (\Model\ProvidersFinder\Providers::find('PageBuilderProvider') as $p) {
					$fromProvider = $p['provider']::components();
					if (is_array($fromProvider))
						$providerComponents = array_merge($providerComponents, $fromProvider);
				}
			} catch (\Throwable $e) {
				// best-effort discovery
			}
		}

		return array_merge($providerComponents, $configComponents);
	}

	/**
	 * Editor descriptors for the host's custom components (serializable subset of
	 * each definition; init.js registers them with `serverRender:true`). Empty when
	 * none configured, so the editor mounts exactly as before.
	 */
	public function componentDescriptors(): array
	{
		return Components::descriptors($this->components());
	}

	/**
	 * Build the dynamic-data provider from the module's global `sources` config
	 * (app/config/PageBuilder/config.php). No sources configured → no provider, so
	 * static documents render exactly as before and any stray binding renders empty
	 * (the contract's "no fallbacks for unavailable data" stance).
	 */
	private function buildProvider(): ?ModelDataProvider
	{
		$config = $this->retrieveConfig();
		$sources = (isset($config['sources']) and is_array($config['sources'])) ? $config['sources'] : [];
		if (empty($sources))
			return null;
		return new ModelDataProvider($sources, $this->model, $this->currentLang());
	}

	private function currentLang(): string
	{
		if (class_exists('\\Model\\Multilang\\Ml')) {
			$lang = \Model\Multilang\Ml::getLang();
			if (is_string($lang) and $lang !== '')
				return $lang;
		}
		return 'it';
	}

	/**
	 * Editor-preview sample data, keyed by source: `{ "<key>": [ …items… ] }`.
	 * Built from the global `sources` config (see docs/dynamic-data.md §4.1 / §6)
	 * and served to the editor field by PageBuilderController (page-builder/
	 * sample-data). Sample
	 * data is preview-only — never stored in the document nor seen by the public
	 * renderer. Only configured sources are exposed, so it can't dump arbitrary
	 * data. Empty when no sources are configured.
	 */
	public function sampleData(): array
	{
		$config = $this->retrieveConfig();
		$sources = (isset($config['sources']) and is_array($config['sources'])) ? $config['sources'] : [];
		if (empty($sources))
			return [];

		$perSource = (isset($config['sample-data-limit']) and is_numeric($config['sample-data-limit'])) ? (int)$config['sample-data-limit'] : 4;

		$helper = new Sources($this->model);
		return $helper->sample($sources, $this->languages(), $perSource);
	}

	public function searchItems(string $source, string $q, int $limit = 10): array
	{
		$config = $this->retrieveConfig();
		$sources = (isset($config['sources']) and is_array($config['sources'])) ? $config['sources'] : [];
		if (empty($sources))
			return [];
		$helper = new Sources($this->model);
		return $helper->search($sources, $source, $q, $this->languages(), $limit);
	}

	public function resolveItems(string $source, array $ids): array
	{
		$config = $this->retrieveConfig();
		$sources = (isset($config['sources']) and is_array($config['sources'])) ? $config['sources'] : [];
		if (empty($sources))
			return [];
		$helper = new Sources($this->model);
		return $helper->resolveItems($sources, $source, $ids, $this->languages());
	}

	private function languages(): array
	{
		if (class_exists('\\Model\\Multilang\\Ml')) {
			$langs = \Model\Multilang\Ml::getLangs();
			if (is_array($langs) and !empty($langs))
				return array_values($langs);
		}
		return ['it'];
	}
}
