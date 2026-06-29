<?php

use Model\PageBuilder\Renderer;

/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var string $extraStyles */
/** @var Renderer $renderer */
/** @var callable $resolveField */

// Conditional container (mirror of the JS preview branch). Resolve the bound
// field (the config.bindings.field leaf-binding slot wins — scalar key or
// nested-pick — else the literal config.field key) and emit only the child
// "space" whose configured value matches, falling back to the trailing Default
// space. Index-based: children[i] <-> values[i], children[count(values)] = Default.
$bindings = (isset($config['bindings']) and is_array($config['bindings'])) ? $config['bindings'] : [];
$ref = (isset($bindings['field']) and $bindings['field'] !== '') ? $bindings['field'] : ($config['field'] ?? '');
$value = $resolveField($ref);
$vals = (isset($config['values']) and is_array($config['values'])) ? array_values($config['values']) : [];
$idx = count($vals);
foreach ($vals as $i => $val) {
	if ((string)$val === (string)$value) {
		$idx = $i;
		break;
	}
}
$chosen = $children[$idx] ?? '';
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
$styleAttr = $extraStyles !== '' ? ' style="' . Renderer::escapeAttr($extraStyles) . '"' : '';
echo '<div class="pb-switch' . $extra . '"' . $styleAttr . '>' . $chosen . '</div>';
