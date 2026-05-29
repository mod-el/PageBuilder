<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var Renderer $renderer */

// Escape-hatch: emit config.html verbatim. Admin-only trust.
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
echo '<div class="pb-raw' . $extra . '">' . ($config['html'] ?? '') . '</div>';
