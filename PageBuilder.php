<?php namespace Model\PageBuilder;

use Model\Core\Module;
use Model\PageBuilder\Renderer\Renderer;

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
			$this->renderer = new Renderer(__DIR__ . '/renderer/templates', $this->currentLang());
		return $this->renderer;
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
