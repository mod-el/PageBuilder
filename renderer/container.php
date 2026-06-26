<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var string $extraStyles */
/** @var Renderer $renderer */
/** @var callable $resolveField */

// Default is no padding (p-0): an absent/empty value falls back to p-0.
$paddingCls = Renderer::spacingClasses($config['padding'] ?? null, 'p');
if ($paddingCls === '')
	$paddingCls = 'p-0';
$directionCls = Renderer::directionClasses($config['direction'] ?? null);
// Bootstrap flex alignment utilities (container-only; mirror of JS render).
$alignParts = [];
if (!empty($config['justifyContent']))
	$alignParts[] = 'justify-content-' . $config['justifyContent'];
if (!empty($config['alignItems']))
	$alignParts[] = 'align-items-' . $config['alignItems'];
$alignCls = implode(' ', $alignParts);
$alignPart = $alignCls !== '' ? ' ' . $alignCls : '';
// Bootstrap gap utility (0..5) for the space between children (flex or grid).
// Default 0 emits nothing (gap-0 is Bootstrap's default); only 1..5 add a class
// (mirror of JS render).
$gapN = isset($config['gap']) ? (int) $config['gap'] : 0;
$gapCls = ($gapN >= 1 and $gapN <= 5) ? 'gap-' . $gapN : '';
$gapPart = $gapCls !== '' ? ' ' . $gapCls : '';

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
// A concrete height, mirroring max-width: 'auto' (and empty/invalid) emits nothing.
$height = Renderer::dimensionValue($config['height'] ?? null);
if ($height !== '' and $height !== 'auto')
	$styleParts[] = 'height:' . $height;
// Common inline style (border-radius) last — own style first, mirror of JS render.
if ($extraStyles !== '')
	$styleParts[] = $extraStyles;
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
	// Each layer gets a concrete, DOM-order z-index + position:relative so it forms
	// its OWN stacking context — otherwise a positioned descendant (e.g. a Bootstrap
	// .carousel) paints above a later layer's static content (mirror of JS render).
	// Pointer-events pass-through: structural wrappers (`.pb-layer` + nested
	// `.pb-container`) fill the grid cell, so without this an upper layer swallows
	// every click — even over transparent gaps — blocking content behind it (e.g. a
	// carousel's controls under an overlaid form). The wrappers are made transparent
	// to the pointer; their real content (`> :not(.pb-container)`) re-enables it.
	// Emitted as a self-contained <style> (display:none, not a grid item); the stack
	// design ships no external CSS so the rule travels with the markup (mirror of JS).
	$inner = '<style>.pb-stack .pb-layer,.pb-stack .pb-container{pointer-events:none}.pb-stack .pb-layer>:not(.pb-container),.pb-stack .pb-container>:not(.pb-container){pointer-events:auto}</style>';
	$layerIndex = 0;
	foreach ($children as $child) {
		$inner .= '<div class="pb-layer" style="grid-area:1/1;position:relative;z-index:' . $layerIndex . '">' . $child . '</div>';
		$layerIndex++;
	}
} else {
	$inner = implode('', $children);
}
echo '<div class="pb-container' . $containerCls . ' ' . $paddingCls . ' ' . $directionCls . $gapPart . $alignPart . $extra . '"' . $styleAttr . '>' . $inner . '</div>';
