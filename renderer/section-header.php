<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var Renderer $renderer */

// The header part of a `section` (mirror of section/part.js): a thin wrapper,
// nothing when empty (the section then emits no <thead>).
if (empty($children))
	return;
echo '<div class="pb-section-header">' . implode('', $children) . '</div>';
