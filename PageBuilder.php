<?php namespace Model\PageBuilder;

use Model\Core\Module;
use Model\PageBuilder\Renderer\Renderer;
use Model\PageBuilder\ModelDataProvider;
use Model\PageBuilder\Sources;

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

	public function render($value, ?string $lang = null): string
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
		return $this->getRenderer()->render($value, ['lang' => $lang]);
	}

	public function getRenderer(): Renderer
	{
		if ($this->renderer === null)
			$this->renderer = new Renderer(__DIR__ . '/renderer/templates', $this->currentLang(), null, $this->buildProvider());
		return $this->renderer;
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
	 * and served to the editor field by PageBuilderSampleDataController. Sample
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

	private function languages(): array
	{
		if (class_exists('\\Model\\Multilang\\Ml')) {
			$langs = \Model\Multilang\Ml::getLangs();
			if (is_array($langs) and !empty($langs))
				return array_values($langs);
		}
		return ['it'];
	}

	public function headings(): void
	{
		?>
		<link rel="stylesheet" href="<?= PATH ?>model/PageBuilder/files/page-builder.min.css">
		<script src="<?= PATH ?>model/PageBuilder/files/page-builder.min.js"></script>
		<script type="module">
			import {
				checkPageBuilder,
				getPageBuilderValue,
				setPageBuilderValue,
				getPageBuilderInstance
			} from "<?= PATH ?>model/PageBuilder/files/init.js";

			window.checkPageBuilder = checkPageBuilder;
			window.getPageBuilderValue = getPageBuilderValue;
			window.setPageBuilderValue = setPageBuilderValue;
			window.getPageBuilderInstance = getPageBuilderInstance;
		</script>
		<?php
	}
}
