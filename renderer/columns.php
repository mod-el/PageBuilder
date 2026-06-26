<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var string $extraStyles */
/** @var Renderer $renderer */

$cols = $config['cols'] ?? null;
$n = Renderer::colCount($cols);
$parts = '';
for ($i = 0; $i < $n; $i++) {
	$cls = Renderer::colClasses($cols, $i);
	$child = $children[$i] ?? '';
	$parts .= '<div class="' . $cls . '">' . $child . '</div>';
}
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
$styleAttr = $extraStyles !== '' ? ' style="' . Renderer::escapeAttr($extraStyles) . '"' : '';
echo '<div class="row' . $extra . '"' . $styleAttr . '>' . $parts . '</div>';
