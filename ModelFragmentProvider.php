<?php namespace Model\PageBuilder;

use Model\Core\Core;

require_once __DIR__ . '/FragmentProvider.php';
require_once __DIR__ . '/Fragments.php';

class ModelFragmentProvider implements FragmentProvider
{
	private Fragments $fragments;

	public function __construct(Core $model, string $element = 'PageBuilderFragment')
	{
		$this->fragments = new Fragments($model, $element);
	}

	public function get(string $id): ?array
	{
		return $this->fragments->get($id);
	}
}
