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

		$this->options['type'] = 'textarea';
		parent::renderWithLang($attributes, $lang);
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
