<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var Renderer $renderer */
/** @var callable $resolveField */

// Leaf bindings (contract §4.6): a slot in config.bindings resolves from the
// current data item via $resolveField; an unmapped slot keeps its static value.
// $resolveField returns the unescaped value, so escape here.
$bindings = (isset($config['bindings']) and is_array($config['bindings'])) ? $config['bindings'] : [];
$src = Renderer::escapeAttr(isset($bindings['src']) ? $resolveField($bindings['src']) : ($config['src'] ?? ''));
$alt = Renderer::escapeAttr(isset($bindings['alt']) ? $resolveField($bindings['alt']) : ($config['alt'] ?? ''));
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
// Optional explicit sizing (unit-aware), fixed order for byte-parity with the
// JS render. All empty → no style attr, so the default img-fluid (max-width:100%)
// governs.
$styleParts = [];
foreach ([['width', 'width'], ['height', 'height'], ['maxWidth', 'max-width'], ['maxHeight', 'max-height']] as $pair) {
	$dim = Renderer::dimensionValue($config[$pair[0]] ?? null);
	if ($dim !== '')
		$styleParts[] = $pair[1] . ':' . $dim;
}
$style = implode(';', $styleParts);
$styleAttr = $style !== '' ? ' style="' . Renderer::escapeAttr($style) . '"' : '';
// img-fluid caps at container width while keeping intrinsic size.
echo '<img src="' . $src . '" alt="' . $alt . '" class="img-fluid' . $extra . '"' . $styleAttr . '>';
