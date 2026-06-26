<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var string $extraStyles */

$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
$styleAttr = $extraStyles !== '' ? ' style="' . Renderer::escapeAttr($extraStyles) . '"' : '';
echo '<div class="pb-fragment' . $extra . '"' . $styleAttr . '>' . implode('', $children) . '</div>';
