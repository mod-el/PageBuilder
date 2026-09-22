<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var string $extraStyles */
/** @var Renderer $renderer */
/** @var callable $resolveField */

// Inline field chips: replace each `<span data-pb-field="KEY">…</span>` with the
// resolved + escaped field value, line breaks kept as <br> (static-only content
// passes straight through). Mirror of the JS component. (The bound `content`
// slot of 0.8.0-0.8.3 is gone: a text that is one value is a paragraph with one chip.)
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
// Own typography first (0.10.0), then the common inline style (mirror of the JS component).
$typo = Renderer::typographyStyle($config);
$style = implode(';', array_values(array_filter([$typo, $extraStyles], static fn($s) => $s !== '')));
$styleAttr = $style !== '' ? ' style="' . Renderer::escapeAttr($style) . '"' : '';
$content = Renderer::resolveChips($config['content'] ?? '', $resolveField);
echo '<div class="pb-text' . $extra . '"' . $styleAttr . '>' . $content . '</div>';
