<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var string $extraStyles */
/** @var Renderer $renderer */
/** @var callable $resolveField */

// The icon is just an <i> carrying the author-supplied class. Decorative -> aria-hidden.
$cls = $config['iconClass'] ?? '';
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
// Inline sizing/colour, fixed order for byte-parity with the JS render.
$styleParts = [];
$size = Renderer::dimensionValue($config['size'] ?? null);
if ($size !== '')
	$styleParts[] = 'font-size:' . $size;
if (!empty($config['color']))
	$styleParts[] = 'color:' . $config['color'];
// Common inline style (border-radius) last — own sizing/colour first (mirror of JS).
if ($extraStyles !== '')
	$styleParts[] = $extraStyles;
$style = implode(';', $styleParts);
$styleAttr = $style !== '' ? ' style="' . Renderer::escapeAttr($style) . '"' : '';
echo '<i class="' . Renderer::escapeAttr($cls) . $extra . '" aria-hidden="true"' . $styleAttr . '></i>';
