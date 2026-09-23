<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var Renderer $renderer */

// The footer part of a `section` (mirror of section/part.js): nothing when
// empty (the section then emits no <tfoot>).
if (empty($children))
	return;
echo '<div class="pb-section-footer">' . implode('', $children) . '</div>';
