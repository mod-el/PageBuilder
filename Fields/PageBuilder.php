<?php namespace Model\PageBuilder\Fields;

use Model\Form\Field;

class PageBuilder extends Field
{
	protected function renderWithLang(array $attributes, ?string $lang = null): void
	{
		if ($this->options['form'] and $this->options['form']->options['print']) {
			echo $this->getText(['lang' => $lang]);
			return;
		}

		if (isset($attributes['class']))
			$attributes['class'] .= ' pagebuilder_field';
		else
			$attributes['class'] = 'pagebuilder_field';

		$attributes['data-pb-languages'] = json_encode($this->detectLanguages());

		// Dynamic-data authoring: expose the configured data sources to the editor
		// as `dataSources` descriptors (fields only — init.js fetches sample data
		// from the page-builder/sample-data route and merges it). Absent/empty config → no
		// attribute, so the editor mounts exactly as before.
		$descriptors = $this->dataSourceDescriptors();
		if (!empty($descriptors))
			$attributes['data-pb-datasources'] = json_encode($descriptors);

		// Custom server-rendered components: serialize their editor descriptors so
		// init.js can register them before mounting. Absent/empty → no attribute.
		$components = $this->componentDescriptors();
		if (!empty($components))
			$attributes['data-pb-components'] = json_encode($components);

		// Fragment-definition editing: the field declares a sibling field holding the
		// fragment's declared data source (see PageBuilderFragment). init.js reads that
		// sibling's value and feeds it to the editor as the root scope source, so the
		// body's field/chip pickers offer that source while authoring.
		if (!empty($this->options['scopeSourceField']))
			$attributes['data-pb-scope-source-field'] = (string)$this->options['scopeSourceField'];

		$this->options['type'] = 'textarea';
		parent::renderWithLang($attributes, $lang);
	}

	/**
	 * Client-side form-build path (used by the admin's JS form builder, mirrored
	 * by FieldPageBuilder.js). Must carry the same data-pb-* attributes that
	 * renderWithLang() emits server-side, or a JS-constructed field mounts with no
	 * languages and no dynamic-data sources.
	 */
	public function getJavascriptDescription(): array
	{
		$response = parent::getJavascriptDescription();

		if (!isset($response['attributes']) or !is_array($response['attributes']))
			$response['attributes'] = [];

		$response['attributes']['data-pb-languages'] = json_encode($this->detectLanguages());

		$descriptors = $this->dataSourceDescriptors();
		if (!empty($descriptors))
			$response['attributes']['data-pb-datasources'] = json_encode($descriptors);

		$components = $this->componentDescriptors();
		if (!empty($components))
			$response['attributes']['data-pb-components'] = json_encode($components);

		if (!empty($this->options['scopeSourceField']))
			$response['attributes']['data-pb-scope-source-field'] = (string)$this->options['scopeSourceField'];

		return $response;
	}

	private function dataSourceDescriptors(): array
	{
		try {
			if (!$this->model->isLoaded('PageBuilder'))
				$this->model->load('PageBuilder');
			$config = $this->model->_PageBuilder->retrieveConfig();
			$sources = (isset($config['sources']) and is_array($config['sources'])) ? $config['sources'] : [];
			if (empty($sources))
				return [];
			$helper = new \Model\PageBuilder\Sources($this->model);
			return $helper->descriptors($sources);
		} catch (\Throwable $e) {
			return [];
		}
	}

	private function componentDescriptors(): array
	{
		try {
			if (!$this->model->isLoaded('PageBuilder'))
				$this->model->load('PageBuilder');
			return $this->model->_PageBuilder->componentDescriptors();
		} catch (\Throwable $e) {
			return [];
		}
	}

	public function getText(array $options = []): string
	{
		return '';
	}

	public function getMinWidth(): int
	{
		return 800;
	}

	public function getEstimatedWidth(array $options): int
	{
		return round(800 / $options['column-width']);
	}

	private function detectLanguages(): array
	{
		if (class_exists('\\Model\\Multilang\\Ml')) {
			$langs = \Model\Multilang\Ml::getLangs();
			if (is_array($langs) and !empty($langs))
				return array_values($langs);
		}
		return ['it'];
	}
}
