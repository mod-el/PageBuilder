<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var Renderer $renderer */
/** @var callable $resolveField */

$padding = $config['padding'] ?? null;
$paddingCls = 'p-3';
if ($padding !== null) {
	$cls = Renderer::spacingClasses($padding, 'p');
	if ($cls !== '')
		$paddingCls = $cls;
	elseif (is_array($padding))
		$paddingCls = 'p-0';
}
$directionCls = Renderer::directionClasses($config['direction'] ?? null);

// Inline style + container class. Field order mirrors the JS render() exactly
// (render-parity invariant). Image URL is double-quoted on purpose: `"` encodes
// to &quot; in both renderers, whereas `'` diverges (&#39; JS vs &apos; PHP). The
// whole style string is escaped once below, so the resolved (unescaped) bg URL is
// concatenated raw here — no per-URL escape (it would double-encode `&`).
// A max-width counts only when it resolves to a concrete length: 'auto'
// (and empty/invalid) leaves the container full-width.
$maxWidth = Renderer::dimensionValue($config['maxWidth'] ?? null);
if ($maxWidth === 'auto')
	$maxWidth = '';
$isStack = ($config['direction'] ?? null) === 'stack';
$styleParts = [];
// `stack` overlays children: parent is a single-cell grid, each layer targets
// that cell (grid-area:1/1, applied per layer below). Prepended so style order
// stays byte-identical to the JS render (render-parity invariant).
if ($isStack)
	$styleParts[] = 'display:grid';
// Leaf binding (contract §4.6): backgroundImage may resolve from the current data
// item via $resolveField when bound in config.bindings; a present binding feeds
// the bg even with an empty static value, mirroring image.src.
$bindings = (isset($config['bindings']) and is_array($config['bindings'])) ? $config['bindings'] : [];
$bgBound = (isset($bindings['backgroundImage']) and $bindings['backgroundImage'] !== '');
$bgType = $config['backgroundType'] ?? 'none';
if ($bgType === 'color' and !empty($config['backgroundColor']))
	$styleParts[] = 'background-color:' . $config['backgroundColor'];
elseif ($bgType === 'image' and ($bgBound or !empty($config['backgroundImage']))) {
	$bgUrl = $bgBound ? $resolveField($bindings['backgroundImage']) : $config['backgroundImage'];
	$styleParts[] = 'background-image:url("' . $bgUrl . '")';
	$styleParts[] = 'background-size:cover';
	$styleParts[] = 'background-position:center';
	$styleParts[] = 'background-repeat:no-repeat';
}
if ($maxWidth !== '')
	$styleParts[] = 'max-width:' . $maxWidth;
$style = implode(';', $styleParts);
$styleAttr = $style !== '' ? ' style="' . Renderer::escapeAttr($style) . '"' : '';
$containerCls = $maxWidth !== '' ? ' container' : '';
// A centered container's auto horizontal margins win only if no explicit
// horizontal margin class is present, so drop it (vertical margin is kept).
$effectiveExtra = $maxWidth !== '' ? Renderer::dropHorizontalMargin($extraClasses) : $extraClasses;
$extra = $effectiveExtra !== '' ? ' ' . $effectiveExtra : '';
// In stack mode each child is wrapped in a layer pinned to the grid cell so all
// layers overlap (mirror of the JS container render).
if ($isStack) {
	$inner = '';
	foreach ($children as $child)
		$inner .= '<div class="pb-layer" style="grid-area:1/1">' . $child . '</div>';
} else {
	$inner = implode('', $children);
}
echo '<div class="pb-container' . $containerCls . ' ' . $paddingCls . ' ' . $directionCls . $extra . '"' . $styleAttr . '>' . $inner . '</div>';
