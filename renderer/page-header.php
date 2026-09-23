<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var string $extraStyles */
/** @var Renderer $renderer */

// A band at the top of every printed page (mirror of the JS page-part preview
// render): position:fixed at its edge, a fixed px height the host reserves on
// every page (docs/host-integration.md §7).
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
$style = 'position:fixed;top:0;left:0;right:0;height:' . Renderer::pagePartHeight($config) . 'px;box-sizing:border-box' . ($extraStyles !== '' ? ';' . $extraStyles : '');
echo '<div class="pb-page-header' . $extra . '" style="' . Renderer::escapeAttr($style) . '">' . implode('', $children) . '</div>';
