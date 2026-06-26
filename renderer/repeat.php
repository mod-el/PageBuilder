<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */

// `repeat` is an iterating component: the renderer has already rendered the
// authored children once per item, so $children is the per-item HTML array.
// Byte-identical to the JS render() preview output (render-parity invariant).
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
// Flex layout — same controls the container exposes (no `stack` overlay). Class
// order mirrors the JS render (base → direction → gap → align → extra).
$directionCls = Renderer::directionClasses($config['direction'] ?? null);
$alignParts = [];
if (!empty($config['justifyContent']))
	$alignParts[] = 'justify-content-' . $config['justifyContent'];
if (!empty($config['alignItems']))
	$alignParts[] = 'align-items-' . $config['alignItems'];
$alignCls = implode(' ', $alignParts);
$alignPart = $alignCls !== '' ? ' ' . $alignCls : '';
$gapN = isset($config['gap']) ? (int) $config['gap'] : 0;
$gapCls = ($gapN >= 1 and $gapN <= 5) ? 'gap-' . $gapN : '';
$gapPart = $gapCls !== '' ? ' ' . $gapCls : '';
echo '<div class="pb-repeat ' . $directionCls . $gapPart . $alignPart . $extra . '">' . implode('', $children) . '</div>';
