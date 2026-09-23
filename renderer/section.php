<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var string $extraStyles */
/** @var Renderer $renderer */

// A run of pages with its own repeating header / footer (mirror of the JS
// section render): children[0] = section-header (→ <thead>, repeated by print
// engines at the top of every page), [1] = section-body, [2] = section-footer
// (→ <tfoot>, repeated after the content; on the last page it sits right under
// the content, not at the page bottom). An empty part emits no row.
$cell = static fn(string $html): string => '<td style="padding:0;vertical-align:top">' . $html . '</td>';
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
// Own style first, then the common inline style (fixed order, mirror of JS).
$style = 'width:100%;border-collapse:collapse' . ($extraStyles !== '' ? ';' . $extraStyles : '');
$head = ($children[0] ?? '') !== '' ? '<thead style="display:table-header-group"><tr>' . $cell($children[0]) . '</tr></thead>' : '';
$body = '<tbody><tr>' . $cell($children[1] ?? '') . '</tr></tbody>';
$foot = ($children[2] ?? '') !== '' ? '<tfoot style="display:table-footer-group"><tr>' . $cell($children[2]) . '</tr></tfoot>' : '';
echo '<table class="pb-section' . $extra . '" style="' . Renderer::escapeAttr($style) . '">' . $head . $body . $foot . '</table>';
