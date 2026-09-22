<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var string $extraStyles */
/** @var Renderer $renderer */
/** @var callable $resolveField */

// Escape-hatch: emit config.html verbatim, its field chips resolved RAW (each
// chip replaced by its value as it is: no span, no escaping — how a record's own
// HTML gets in). Admin-only trust. Mirror of the JS component. (The bound `html`
// slot of 0.8.0-0.8.3 is gone: one bare chip prints the same.)
$inner = Renderer::resolveChips($config['html'] ?? '', $resolveField, true);
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
// Own typography first (0.10.0), then the common inline style (mirror of the JS component).
$typo = Renderer::typographyStyle($config);
$style = implode(';', array_values(array_filter([$typo, $extraStyles], static fn($s) => $s !== '')));
$styleAttr = $style !== '' ? ' style="' . Renderer::escapeAttr($style) . '"' : '';
echo '<div class="pb-raw' . $extra . '"' . $styleAttr . '>' . $inner . '</div>';
