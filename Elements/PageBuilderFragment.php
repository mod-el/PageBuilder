<?php namespace Model\PageBuilder\Elements;

use Model\ORM\Element;

/**
 * A single reusable fragment in the global library. Read/written through the
 * Fragments ORM adapter (and ModelFragmentProvider on the render side); editable
 * in normal ModEl admin, where `doc` mounts the very same page-builder editor.
 *
 * `doc` stores a full document `{version:1, root:[...]}` as JSON. The
 * `page-builder` field type backs onto a textarea, so the JSON round-trips
 * unchanged when the record is edited by hand.
 *
 * `source` is the optional data source the fragment is designed against (e.g.
 * "hotels"); `doc` declares `scopeSourceField => 'source'` so its mounted editor
 * reads that sibling value and offers the source's fields while authoring the
 * fragment body (and locks the single-item picker on dropped instances).
 */
class PageBuilderFragment extends Element
{
	public static ?string $table = 'page_builder_fragments';

	public static array $fields = [
		'source' => [
			'type' => 'select',
			'nullable' => true,
		],
		'doc' => [
			'type' => 'page-builder',
			'scopeSourceField' => 'source',
		],
	];

	/**
	 * Populate the `source` select with the configured data sources (key => label).
	 * They are config-driven (PageBuilder module config), not a DB enum/FK, so they
	 * can't be a static option list. `init()` runs in the Element constructor *before*
	 * the static `$fields` are read into the form, and `$this->model` is already set,
	 * so mutating the static here lands the options in the rendered select.
	 */
	public function init(): void
	{
		try {
			$config = $this->model->_PageBuilder->retrieveConfig();
			$sources = (isset($config['sources']) and is_array($config['sources'])) ? $config['sources'] : [];
			if (!$sources)
				return;

			$helper = new \Model\PageBuilder\Sources($this->model);
			$options = ['' => ''];
			foreach ($helper->descriptors($sources) as $descriptor) {
				if (isset($descriptor['key']))
					$options[$descriptor['key']] = $descriptor['label'] ?? $descriptor['key'];
			}
			if (count($options) > 1)
				self::$fields['source']['options'] = $options;
		} catch (\Throwable $e) {
		}
	}
}
