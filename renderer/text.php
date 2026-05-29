<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var Renderer $renderer */
/** @var callable $resolveField */

// Inline field chips: replace each `<span data-pb-field="KEY">…</span>` with the
// resolved + escaped field value (static-only content passes straight through).
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
$content = Renderer::resolveChips($config['content'] ?? '', $resolveField);
echo '<div class="pb-text' . $extra . '">' . $content . '</div>';
