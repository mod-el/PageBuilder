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

echo '<div id="' . $id . '" class="carousel slide' . $fade . $extra . '"' . $ride . $styleAttr . '>' . $indicatorsHtml . '<div class="carousel-inner">' . $items . '</div>' . $controlsHtml . '</div>';
