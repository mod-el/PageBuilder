<?php namespace Model\PageBuilder\Renderer;
/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var Renderer $renderer */

if (empty($children))
	return;
echo '<div class="' . Renderer::directionClasses($config['direction'] ?? null) . '">' . implode('', $children) . '</div>';
