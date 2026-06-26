<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var Renderer $renderer */
/** @var callable $resolveField */

// Both libraries render as a class on an <i>: FA needs a style prefix + `fa-<name>`,
// Bootstrap Icons needs `bi bi-<name>`. The icon is decorative -> aria-hidden.
$library = $config['library'] ?? 'fontawesome';
$name = $config['name'] ?? '';
$cls = $library === 'bootstrap'
	? 'bi bi-' . $name
	: ($config['style'] ?? 'fas') . ' fa-' . $name;
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
// Inline sizing/colour, fixed order for byte-parity with the JS render.
$styleParts = [];
$size = Renderer::dimensionValue($config['size'] ?? null);
if ($size !== '')
	$styleParts[] = 'font-size:' . $size;
if (!empty($config['color']))
	$styleParts[] = 'color:' . $config['color'];
$style = implode(';', $styleParts);
$styleAttr = $style !== '' ? ' style="' . Renderer::escapeAttr($style) . '"' : '';
echo '<i class="' . Renderer::escapeAttr($cls) . $extra . '" aria-hidden="true"' . $styleAttr . '></i>';
