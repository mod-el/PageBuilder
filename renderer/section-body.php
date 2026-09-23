<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var Renderer $renderer */

// The content part of a `section` (mirror of section/part.js).
if (empty($children))
	return;
echo '<div class="pb-section-body">' . implode('', $children) . '</div>';
