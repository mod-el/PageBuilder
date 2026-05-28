<?php namespace Model\PageBuilder\Renderer;
/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var Renderer $renderer */

$variant = $config['variant'] ?? 'primary';
$href = Renderer::escapeAttr($config['href'] ?? '#');
$label = Renderer::escapeHtml($config['label'] ?? '');
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
echo '<a class="btn btn-' . $variant . $extra . '" href="' . $href . '">' . $label . '</a>';
