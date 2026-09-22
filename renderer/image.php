<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var string $extraStyles */
/** @var Renderer $renderer */
/** @var callable $resolveField */

// Leaf bindings (contract §4.6): a slot in config.bindings resolves from the
// current data item via $resolveField; an unmapped slot keeps its static value.
// $resolveField returns the unescaped value, so escape here.
$bindings = (isset($config['bindings']) and is_array($config['bindings'])) ? $config['bindings'] : [];
// A bound src (a non-empty slot, like the JS truthy test) that resolves empty
// falls back to `fallbackSrc` (0.10.0); a static src never does.
$srcBound = (isset($bindings['src']) and $bindings['src'] !== '');
$boundSrc = $srcBound ? $resolveField($bindings['src']) : '';
$src = Renderer::escapeAttr($srcBound
	? (($boundSrc !== '' and $boundSrc !== null) ? $boundSrc : ($config['fallbackSrc'] ?? ''))
	: ($config['src'] ?? ''));
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
// `objectFit` (0.10.0): how the picture fills the sizes above, after them.
$fit = $config['objectFit'] ?? '';
if (is_string($fit) and in_array($fit, ['cover', 'contain', 'fill'], true))
	$styleParts[] = 'object-fit:' . $fit;
// Common inline style (border-radius) last — own sizing first, mirror of JS render.
if ($extraStyles !== '')
	$styleParts[] = $extraStyles;
$style = implode(';', $styleParts);
$styleAttr = $style !== '' ? ' style="' . Renderer::escapeAttr($style) . '"' : '';
// img-fluid caps at container width while keeping intrinsic size.
echo '<img src="' . $src . '" alt="' . $alt . '" class="img-fluid' . $extra . '"' . $styleAttr . '>';
