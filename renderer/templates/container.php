<?php namespace Model\PageBuilder\Renderer;
/** @var array  $config */
/** @var string[] $children */
/** @var string $extraClasses */
/** @var Renderer $renderer */

$padding = $config['padding'] ?? null;
$paddingCls = 'p-3';
if ($padding !== null) {
	$cls = Renderer::spacingClasses($padding, 'p');
	if ($cls !== '')
		$paddingCls = $cls;
	elseif (is_array($padding))
		$paddingCls = 'p-0';
}
$directionCls = Renderer::directionClasses($config['direction'] ?? null);

// Inline style + container class. Field order mirrors the JS render() exactly
// (render-parity invariant). Image URL is double-quoted on purpose: `"` encodes
// to &quot; in both renderers, whereas `'` diverges (&#39; JS vs &apos; PHP).
$maxWidth = (int)($config['maxWidth'] ?? 0);
if ($maxWidth < 0)
	$maxWidth = 0;
$styleParts = [];
$bgType = $config['backgroundType'] ?? 'none';
if ($bgType === 'color' and !empty($config['backgroundColor']))
	$styleParts[] = 'background-color:' . $config['backgroundColor'];
elseif ($bgType === 'image' and !empty($config['backgroundImage'])) {
	$styleParts[] = 'background-image:url("' . $config['backgroundImage'] . '")';
	$styleParts[] = 'background-size:cover';
	$styleParts[] = 'background-position:center';
	$styleParts[] = 'background-repeat:no-repeat';
}
if ($maxWidth > 0)
	$styleParts[] = 'max-width:' . $maxWidth . 'px';
$style = implode(';', $styleParts);
$styleAttr = $style !== '' ? ' style="' . Renderer::escapeAttr($style) . '"' : '';
$containerCls = $maxWidth > 0 ? ' container' : '';
// A centered container's auto horizontal margins win only if no explicit
// horizontal margin class is present, so drop it (vertical margin is kept).
$effectiveExtra = $maxWidth > 0 ? Renderer::dropHorizontalMargin($extraClasses) : $extraClasses;
$extra = $effectiveExtra !== '' ? ' ' . $effectiveExtra : '';
echo '<div class="pb-container' . $containerCls . ' ' . $paddingCls . ' ' . $directionCls . $extra . '"' . $styleAttr . '>' . implode('', $children) . '</div>';
