<?php

use Model\PageBuilder\Renderer;

/** @var array    $config */
/** @var string[] $children */     // unused: the table has no authored children
/** @var string   $extraClasses */
/** @var string   $extraStyles */
/** @var string   $lang */
/** @var array|null $items */       // the node's list (own binding or inherited)
/** @var Renderer $renderer */

// Data table: one <tr> per item of $items, one cell per configured column,
// resolved per row through $renderer->resolveField($field, $item, $lang).
// Byte-identical to the JS render() preview output (render-parity invariant).
$columns = Renderer::fieldRows($config['columns'] ?? null);
if (empty($columns))
	return;
$rows = is_array($items) ? $items : [];
if (empty($rows) and ($config['hideIfEmpty'] ?? false) === true)
	return;

$alignOf = static function (array $col): string {
	$align = $col['align'] ?? '';
	return in_array($align, Renderer::TEXT_ALIGNS, true) ? $align : '';
};

$head = '';
$cellOpen = [];
foreach ($columns as $col) {
	$styles = [];
	$width = Renderer::dimensionValue($col['width'] ?? null);
	if ($width !== '')
		$styles[] = 'width:' . $width;
	$align = $alignOf($col);
	if ($align !== '')
		$styles[] = 'text-align:' . $align;
	$styleAttr = $styles ? ' style="' . Renderer::escapeAttr(implode(';', $styles)) . '"' : '';
	$head .= '<th' . $styleAttr . '>' . Renderer::escapeHtml($renderer->resolveLangValue($col['title'] ?? null, $lang)) . '</th>';
	$cellOpen[] = $align !== '' ? '<td style="text-align:' . $align . '">' : '<td>';
}

$body = '';
foreach ($rows as $item) {
	$resolve = static function ($field) use ($renderer, $item, $lang) {
		return $renderer->resolveField($field, $item, $lang);
	};
	$body .= '<tr>';
	foreach ($columns as $i => $col)
		$body .= $cellOpen[$i] . Renderer::fieldRowValue($col, $resolve) . '</td>';
	$body .= '</tr>';
}

$cls = 'pb-table table';
if (($config['striped'] ?? false) === true)
	$cls .= ' table-striped';
if (($config['bordered'] ?? false) === true)
	$cls .= ' table-bordered';
if (($config['compact'] ?? false) === true)
	$cls .= ' table-sm';
if ($extraClasses !== '')
	$cls .= ' ' . $extraClasses;
$styleAttr = $extraStyles !== '' ? ' style="' . Renderer::escapeAttr($extraStyles) . '"' : '';
echo '<table class="' . $cls . '"' . $styleAttr . '><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
