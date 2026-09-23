<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var string $extraStyles */
/** @var Renderer $renderer */

// A band at the bottom of every printed page (mirror of the JS page-part
// preview render; see page-header.php).
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
$style = 'position:fixed;bottom:0;left:0;right:0;height:' . Renderer::pagePartHeight($config) . 'px;box-sizing:border-box' . ($extraStyles !== '' ? ';' . $extraStyles : '');
echo '<div class="pb-page-footer' . $extra . '" style="' . Renderer::escapeAttr($style) . '">' . implode('', $children) . '</div>';
