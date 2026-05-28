<?php namespace Model\PageBuilder\Renderer;
/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var Renderer $renderer */

$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
echo '<div class="pb-text' . $extra . '">' . ($config['content'] ?? '') . '</div>';
