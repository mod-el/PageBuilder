<?php
/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */

$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
echo '<div class="pb-fragment' . $extra . '">' . implode('', $children) . '</div>';
