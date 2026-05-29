<?php
/**
 * Built-in v1 component meta for the PHP renderer.
 *
 * `multilang`     — names of config fields stored as `{lang: value}` maps that must be
 *                   resolved before reaching the template.
 * `supportsCommon` — when false, the renderer does NOT pass `extraClasses` (computed
 *                   from `margin` + `class`) to the template. Only `column` opts out.
 * `iterates`      — when true, the renderer resolves the node's common `binding` to a
 *                   list and renders the authored children once per item (scope = item),
 *                   passing the per-item HTML array to the template. Defaults to false.
 */
return [
	'container' => ['multilang' => [],          'supportsCommon' => true],
	'columns'   => ['multilang' => [],          'supportsCommon' => true],
	'column'    => ['multilang' => [],          'supportsCommon' => false],
	'text'      => ['multilang' => ['content'], 'supportsCommon' => true],
	'image'     => ['multilang' => ['alt'],     'supportsCommon' => true],
	'button'    => ['multilang' => ['label'],   'supportsCommon' => true],
	'raw-html'  => ['multilang' => [],          'supportsCommon' => true],
	'repeat'    => ['multilang' => [],          'supportsCommon' => true, 'iterates' => true],
];
