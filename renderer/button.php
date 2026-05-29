<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var Renderer $renderer */
/** @var callable $resolveField */

// Leaf bindings (contract §4.6): a slot in config.bindings resolves from the
// current data item via $resolveField; an unmapped slot keeps its static value.
// $resolveField returns the unescaped value, so escape here.
$bindings = (isset($config['bindings']) and is_array($config['bindings'])) ? $config['bindings'] : [];
$variant = $config['variant'] ?? 'primary';
$href = Renderer::escapeAttr(isset($bindings['href']) ? $resolveField($bindings['href']) : ($config['href'] ?? '#'));
$label = Renderer::escapeHtml(isset($bindings['label']) ? $resolveField($bindings['label']) : ($config['label'] ?? ''));
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
$newTab = !empty($config['newTab']) ? ' target="_blank" rel="noopener noreferrer"' : '';
echo '<a class="btn btn-' . $variant . $extra . '" href="' . $href . '"' . $newTab . '>' . $label . '</a>';
