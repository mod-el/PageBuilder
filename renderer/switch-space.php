<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var Renderer $renderer */

// One drop-space of a `switch` (mirror of column.php). Thin passthrough wrapper;
// the parent switch decides which space is emitted.
if (empty($children))
	return;
echo '<div>' . implode('', $children) . '</div>';
