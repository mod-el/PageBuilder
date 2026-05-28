<?php namespace Model\PageBuilder\Renderer;
/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var Renderer $renderer */

$src = Renderer::escapeAttr($config['src'] ?? '');
$alt = Renderer::escapeAttr($config['alt'] ?? '');
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
echo '<img src="' . $src . '" alt="' . $alt . '" class="img-fluid' . $extra . '">';
