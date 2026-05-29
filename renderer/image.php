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
$src = Renderer::escapeAttr(isset($bindings['src']) ? $resolveField($bindings['src']) : ($config['src'] ?? ''));
$alt = Renderer::escapeAttr(isset($bindings['alt']) ? $resolveField($bindings['alt']) : ($config['alt'] ?? ''));
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
// img-fluid caps at container width while keeping intrinsic size; align-self-start
// stops the flex container parent from stretching it to full width (parity with JS).
echo '<img src="' . $src . '" alt="' . $alt . '" class="img-fluid align-self-start' . $extra . '">';
