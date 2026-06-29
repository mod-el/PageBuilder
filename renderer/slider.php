<?php

use Model\PageBuilder\Renderer;

/** @var array    $config */
/** @var string[] $children */     // per-item slide HTML (one entry per bound data item)
/** @var string   $extraClasses */
/** @var string   $extraStyles */
/** @var string   $nodeId */

// Native Bootstrap 5 carousel. Byte-identical to the JS render() preview output
// (render-parity invariant). Controls/indicators target the carousel by a unique
// id `pb-<nodeId>` ($nodeId mirrors JS ctx.nodeId). The host supplies Bootstrap 5
// JS/CSS — nothing ships here. Like `repeat`, this is an iterating component, so
// $children is already the per-item HTML array.
$extra = $extraClasses !== '' ? ' ' . $extraClasses : '';
$styleAttr = $extraStyles !== '' ? ' style="' . Renderer::escapeAttr($extraStyles) . '"' : '';
$id = 'pb-' . Renderer::escapeAttr($nodeId);
$fade = !empty($config['crossfade']) ? ' carousel-fade' : '';

$rawInterval = $config['interval'] ?? null;
$intervalNum = ($rawInterval === null or $rawInterval === '') ? 5000 : $rawInterval;
$interval = (is_numeric($intervalNum) and (int)$intervalNum > 0) ? (int)$intervalNum : 0;
$ride = $interval > 0 ? ' data-bs-ride="carousel" data-bs-interval="' . $interval . '"' : '';

$controls = ($config['controls'] ?? true) !== false;
$indicators = ($config['indicators'] ?? false) === true;
$dots = ($config['dots'] ?? false) === true;
$n = count($children);

$indicatorsHtml = '';
if ($indicators and $n > 0) {
	$btns = '';
	for ($i = 0; $i < $n; $i++)
		$btns .= '<button type="button" data-bs-target="#' . $id . '" data-bs-slide-to="' . $i . '"' . ($i === 0 ? ' class="active" aria-current="true"' : '') . ' aria-label="Slide ' . ($i + 1) . '"></button>';
	$indicatorsHtml = '<div class="carousel-indicators">' . $btns . '</div>';
}

$items = '';
for ($i = 0; $i < $n; $i++)
	$items .= '<div class="carousel-item' . ($i === 0 ? ' active' : '') . '">' . $children[$i] . '</div>';

$controlsHtml = '';
if ($controls and $n > 0)
	$controlsHtml = '<button class="carousel-control-prev" type="button" data-bs-target="#' . $id . '" data-bs-slide="prev"><span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Precedente</span></button><button class="carousel-control-next" type="button" data-bs-target="#' . $id . '" data-bs-slide="next"><span class="carousel-control-next-icon" aria-hidden="true"></span><span class="visually-hidden">Successivo</span></button>';

// Pagination dots in a separate centered row below the slides (distinct from the
// overlaid $indicators). Active(filled)/inactive(outlined) is a CSS rule because
// Bootstrap toggles `.active` as the carousel moves; only the colour is dynamic,
// carried as an inline custom property so the scoped <style> is a constant template.
$dotsHtml = '';
if ($dots and $n > 0) {
	$dotsColor = (isset($config['dotsColor']) and is_string($config['dotsColor']) and $config['dotsColor'] !== '') ? $config['dotsColor'] : '#0d6efd';
	$btns = '';
	for ($i = 0; $i < $n; $i++)
		$btns .= '<button type="button" data-bs-target="#' . $id . '" data-bs-slide-to="' . $i . '"' . ($i === 0 ? ' class="active" aria-current="true"' : '') . ' aria-label="Slide ' . ($i + 1) . '"></button>';
	$dotsHtml = '<div class="carousel-indicators pb-carousel-dots" style="--pb-dots-color:' . Renderer::escapeAttr($dotsColor) . '">' . $btns . '</div><style>#' . $id . ' .pb-carousel-dots{position:static;margin:.75rem 0 0}#' . $id . ' .pb-carousel-dots [data-bs-slide-to]{box-sizing:border-box;width:10px;height:10px;border-radius:50%;border:1px solid var(--pb-dots-color);background-color:transparent;opacity:1;margin:0 4px}#' . $id . ' .pb-carousel-dots [data-bs-slide-to].active{background-color:var(--pb-dots-color)}</style>';
}

echo '<div id="' . $id . '" class="carousel slide' . $fade . $extra . '"' . $ride . $styleAttr . '>' . $indicatorsHtml . '<div class="carousel-inner">' . $items . '</div>' . $controlsHtml . $dotsHtml . '</div>';
