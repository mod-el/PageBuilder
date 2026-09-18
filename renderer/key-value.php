<?php

use Model\PageBuilder\Renderer;

/** @var array    $config */
/** @var string   $extraClasses */
/** @var string   $extraStyles */
/** @var string   $lang */
/** @var Renderer $renderer */
/** @var callable $resolveField */

// Label / value block: `horizontal` → two-column table, `vertical` → <dl>.
// Values resolve against the current scope via $resolveField. Byte-identical to
// the JS render() preview output (render-parity invariant).
$rows = Renderer::fieldRows($config['items'] ?? null);
if (empty($rows))
	return;
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
$styleAttr = $extraStyles !== '' ? ' style="' . Renderer::escapeAttr($extraStyles) . '"' : '';
$label = static function (array $row) use ($renderer, $lang): string {
	return Renderer::escapeHtml($renderer->resolveLangValue($row['label'] ?? null, $lang));
};

if (($config['layout'] ?? 'horizontal') === 'vertical') {
	$inner = '';
	foreach ($rows as $row)
		$inner .= '<dt>' . $label($row) . '</dt><dd>' . Renderer::fieldRowValue($row, $resolveField) . '</dd>';
	echo '<dl class="pb-key-value' . $extra . '"' . $styleAttr . '>' . $inner . '</dl>';
	return;
}
$width = Renderer::dimensionValue($config['labelWidth'] ?? null);
$thOpen = $width !== '' ? '<th scope="row" style="width:' . Renderer::escapeAttr($width) . '">' : '<th scope="row">';
$inner = '';
foreach ($rows as $row)
	$inner .= '<tr>' . $thOpen . $label($row) . '</th><td>' . Renderer::fieldRowValue($row, $resolveField) . '</td></tr>';
echo '<table class="pb-key-value' . $extra . '"' . $styleAttr . '><tbody>' . $inner . '</tbody></table>';
