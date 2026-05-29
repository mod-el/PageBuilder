<?php namespace Model\PageBuilder\Renderer;
/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */

// `repeat` is an iterating component: the renderer has already rendered the
// authored children once per item, so $children is the per-item HTML array.
// Byte-identical to the JS render() preview output (render-parity invariant).
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
echo '<div class="pb-repeat' . $extra . '">' . implode('', $children) . '</div>';
