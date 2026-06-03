<?php namespace Model\PageBuilder;

use InvalidArgumentException;
use RuntimeException;

/**
 * Page-builder PHP renderer (ModEl-namespaced copy of php-renderer/Renderer.php).
 *
 * Walks the canonical JSON document produced by the JS editor and emits HTML
 * equivalent to JS preview-mode output (no editor chrome).
 *
 * Usage:
 *   $renderer = new \Model\PageBuilder\Renderer\Renderer(__DIR__ . '/templates', 'it');
 *   $html     = $renderer->render($doc, ['lang' => 'en']);
 */
require_once __DIR__ . '/DataProvider.php';

class Renderer
{
	private string $templatesPath;
	private string $defaultLang;
	private array $registry;
	private ?DataProvider $data;
	// Per-type template overrides (type => absolute file path), checked before the
	// default templatesPath. Lets a host register custom components whose templates
	// live outside the built-in directory (see CLAUDE.md "custom components").
	private array $templateMap;

	private const SIDE_PREFIX = [
		'top'    => 't',
		'bottom' => 'b',
		'left'   => 's',
		'right'  => 'e',
		'x'      => 'x',
		'y'      => 'y',
	];

	private const BREAKPOINTS = ['xs', 'sm', 'md', 'lg', 'xl', 'xxl'];

	// Units accepted by the `dimension` config widget. Mirror of _common.js
	// DIMENSION_UNITS — both must list the same units so an unknown unit is
	// rejected identically on each side (render parity).
	private const DIMENSION_UNITS = ['px', '%', 'rem', 'em', 'vw', 'vh', 'auto'];

	public function __construct(string $templatesPath, string $defaultLang = 'it', ?array $registry = null, ?DataProvider $data = null, ?array $templateMap = null)
	{
		$this->templatesPath = rtrim($templatesPath, "/\\");
		$this->defaultLang = $defaultLang;
		$this->registry = $registry ?? require __DIR__ . '/registry.php';
		$this->data = $data;
		$this->templateMap = $templateMap ?? [];
	}

	// Resolve a binding to a list via the active provider. No provider → empty
	// list (the contract's "no fallbacks for unavailable data" stance).
	public function query(array $binding, array $params, $scope, string $lang): array
	{
		if ($this->data === null)
			return [];
		return $this->data->query($binding, $params, $scope, $lang);
	}

	// Resolve one field of the current scope item via the active provider. No
	// provider or no scope → '' (mirrors JS ctx.resolveField). Does NOT escape.
	public function resolveField(string $field, $scope, string $lang)
	{
		if ($this->data === null or $scope === null)
			return '';
		return $this->data->resolve($scope, $field, $lang);
	}

	public function render(array $doc, array $opts = []): string
	{
		if (!array_key_exists('version', $doc) or $doc['version'] !== 1)
			throw new InvalidArgumentException('unsupported document version (expected 1)');
		if (!array_key_exists('root', $doc) or !is_array($doc['root']))
			throw new InvalidArgumentException('document root must be an array');

		$lang = (isset($opts['lang']) && is_string($opts['lang'])) ? $opts['lang'] : $this->defaultLang;

		$out = '';
		foreach ($doc['root'] as $node) {
			if (is_array($node))
				$out .= $this->renderNode($node, $lang);
		}
		return $out;
	}

	// $scope is the current data item (null at root); $items the nearest resolved
	// list. A node's common `binding` (contract §4.2) is resolved here to a list:
	// an `iterates` component renders its authored children once per item (scope =
	// item), any other bound node exposes the list to its subtree as $items, and
	// an unbound node inherits the nearest ancestor's list. Mirror of the JS
	// renderNode walk (src/core/editor.js) — preview output stays byte-identical.
	private function renderNode(array $node, string $lang, $scope = null, ?array $items = null): string
	{
		$type = (isset($node['type']) && is_string($node['type'])) ? $node['type'] : '';
		if (!isset($this->registry[$type]))
			return '<!-- pb: unknown type "' . self::escapeHtml($type) . '" -->';

		$meta = $this->registry[$type];
		$rawConfig = (isset($node['config']) && is_array($node['config'])) ? $node['config'] : [];
		$supportsCommon = ($meta['supportsCommon'] ?? true) !== false;

		// Resolve a common binding to a list (no provider → empty). childItems is
		// the node's own list if bound, else the inherited ancestor list. A binding
		// naming no source/relation/query is treated as absent (inherit, not empty).
		$binding = ($supportsCommon and isset($rawConfig['binding']) and is_array($rawConfig['binding'])) ? $rawConfig['binding'] : null;
		if ($binding !== null and !(isset($binding['source']) or isset($binding['relation']) or isset($binding['query'])))
			$binding = null;
		$boundList = null;
		if ($binding !== null) {
			$params = (isset($binding['params']) and is_array($binding['params'])) ? $binding['params'] : [];
			$boundList = $this->query($binding, $params, $scope, $lang);
		}
		$childItems = $boundList !== null ? $boundList : $items;

		$kids = (isset($node['children']) and is_array($node['children'])) ? $node['children'] : [];
		$children = [];
		if (($meta['iterates'] ?? false) === true) {
			$list = $boundList !== null ? $boundList : ($items ?? []);
			foreach ($list as $item) {
				$buf = '';
				foreach ($kids as $child) {
					if (is_array($child))
						$buf .= $this->renderNode($child, $lang, $item, $list);
				}
				$children[] = $buf;
			}
		} else {
			foreach ($kids as $child) {
				if (is_array($child))
					$children[] = $this->renderNode($child, $lang, $scope, $childItems);
			}
		}

		$config = $this->resolveMultilang($rawConfig, $meta['multilang'] ?? [], $lang);
		$extraClasses = $supportsCommon ? self::computeExtraClasses($rawConfig) : '';

		return $this->loadTemplate($type, $config, $children, $extraClasses, $lang, $scope, $childItems);
	}

	private function loadTemplate(string $type, array $config, array $children, string $extraClasses, string $lang, $scope = null, ?array $items = null): string
	{
		$path = $this->templateMap[$type] ?? ($this->templatesPath . '/' . $type . '.php');
		if (!file_exists($path))
			throw new RuntimeException("template not found for type \"$type\" at $path");

		$renderer = $this;
		// Bound to the active provider + current scope/lang (contract §4.3 mirror
		// of JS ctx.resolveField). Templates call $resolveField('name') to read a
		// field of the current data item, then escape the result themselves.
		$resolveField = static function (string $field) use ($renderer, $scope, $lang) {
			return $renderer->resolveField($field, $scope, $lang);
		};
		$run = static function (string $__path, array $config, array $children, string $extraClasses, string $lang, $scope, ?array $items, Renderer $renderer, callable $resolveField): void {
			include $__path;
		};

		ob_start();
		try {
			$run($path, $config, $children, $extraClasses, $lang, $scope, $items, $renderer, $resolveField);
		} catch (\Throwable $e) {
			ob_end_clean();
			throw $e;
		}
		return ob_get_clean();
	}

	private function resolveMultilang(array $config, array $multilangFields, string $lang): array
	{
		if (empty($multilangFields))
			return $config;
		foreach ($multilangFields as $field) {
			if (!array_key_exists($field, $config))
				continue;
			$val = $config[$field];
			if (!is_array($val))
				continue;
			$config[$field] = $this->resolveValue($val, $lang);
		}
		return $config;
	}

	private function resolveValue(array $value, string $lang)
	{
		if (array_key_exists($lang, $value) and $value[$lang] !== null)
			return $value[$lang];
		if (array_key_exists($this->defaultLang, $value) and $value[$this->defaultLang] !== null)
			return $value[$this->defaultLang];
		foreach ($value as $v) {
			if ($v !== null)
				return $v;
		}
		return '';
	}

	public static function escapeHtml($s): string
	{
		return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	public static function escapeAttr($s): string
	{
		return self::escapeHtml($s);
	}

	// Mirror of _common.js dimensionValue. Resolves a `dimension` config value to
	// a CSS length token (`1200px`, `50%`, `auto`) or '' when absent/invalid.
	// Reads { value, unit } | { unit: 'auto' } | a legacy bare number (-> px).
	// Empty / value <= 0 / unknown unit -> '' (the field is then not emitted).
	// The numeric value is cast through (float) so integers print without a
	// trailing `.0`, matching the JS `${n}` formatting (render parity).
	public static function dimensionValue($v): string
	{
		if ($v === null or $v === '')
			return '';
		if (is_array($v)) {
			$unit = $v['unit'] ?? null;
			if ($unit === 'auto')
				return 'auto';
			if (!in_array($unit, self::DIMENSION_UNITS, true))
				return '';
			$n = isset($v['value']) ? (float)$v['value'] : 0;
			return $n > 0 ? (string)$n . $unit : '';
		}
		// Legacy: a plain number (or numeric string) is interpreted as px.
		if (is_int($v) or is_float($v) or (is_string($v) and is_numeric($v))) {
			$n = (float)$v;
			return $n > 0 ? (string)$n . 'px' : '';
		}
		return '';
	}

	public static function spacingClasses($value, string $axis): string
	{
		if ($value === null)
			return '';

		if (is_array($value) and self::isResponsiveShape($value)) {
			$parts = [];
			foreach (self::BREAKPOINTS as $bp) {
				if (!array_key_exists($bp, $value))
					continue;
				$sub = self::spacingClassesScalar($value[$bp], $axis, $bp);
				if ($sub !== '')
					$parts[] = $sub;
			}
			return implode(' ', $parts);
		}

		return self::spacingClassesScalar($value, $axis, null);
	}

	private static function spacingClassesScalar($value, string $axis, ?string $bp): string
	{
		$n = self::toIntOrNull($value);
		if ($n !== null and $n >= 0 and $n <= 5)
			return ($bp === null or $bp === 'xs') ? "$axis-$n" : "$axis-$bp-$n";

		if (is_array($value)) {
			$parts = [];
			foreach (self::SIDE_PREFIX as $side => $prefix) {
				if (!array_key_exists($side, $value))
					continue;
				$sv = self::toIntOrNull($value[$side]);
				if ($sv === null or $sv < 0 or $sv > 5)
					continue;
				$head = ($bp === null or $bp === 'xs') ? "$axis$prefix" : "$axis$prefix-$bp";
				$parts[] = "$head-$sv";
			}
			return implode(' ', $parts);
		}

		return '';
	}

	private static function isResponsiveShape(array $value): bool
	{
		foreach (self::BREAKPOINTS as $bp) {
			if (array_key_exists($bp, $value))
				return true;
		}
		return false;
	}

	public static function computeExtraClasses(array $config): string
	{
		$m = self::spacingClasses($config['margin'] ?? null, 'm');
		$c = (isset($config['class']) && is_string($config['class'])) ? trim($config['class']) : '';
		$parts = [];
		if ($m !== '')
			$parts[] = $m;
		if ($c !== '')
			$parts[] = $c;
		return implode(' ', $parts);
	}

	// Mirror of _common.js dropHorizontalMargin. A centered Bootstrap `container`
	// centers via auto horizontal margins; an explicit horizontal margin utility
	// overrides them, so strip it while keeping the vertical one (uniform `m-N`
	// collapses to `my-N`). Both renderers must transform identically.
	public static function dropHorizontalMargin(string $classes): string
	{
		if ($classes === '')
			return $classes;
		$out = [];
		foreach (explode(' ', $classes) as $token) {
			if ($token === '')
				continue;
			if (preg_match('/^m[xse]-(?:(?:sm|md|lg|xl|xxl)-)?[0-5]$/', $token))
				continue;
			if (preg_match('/^m-((?:sm|md|lg|xl|xxl)-)?([0-5])$/', $token, $m))
				$out[] = 'my-' . $m[1] . $m[2];
			else
				$out[] = $token;
		}
		return implode(' ', $out);
	}

	// Mirror of _common.js chipEscape. Escapes ONLY & < > " (NOT ') so JS-preview
	// and PHP output stay byte-identical: ' is the one char whose JS (&#39;) and
	// PHP (&apos;) encodings diverge, and it is valid literal text inside an
	// element. `&` is replaced first so later passes never double-encode.
	public static function chipEscape($s): string
	{
		return str_replace(
			['&', '<', '>', '"'],
			['&amp;', '&lt;', '&gt;', '&quot;'],
			(string)($s ?? '')
		);
	}

	// Mirror of _common.js resolveChips. Replaces each
	// `<span … data-pb-field="KEY" …>…</span>` chip with a clean
	// `<span data-pb-field="KEY">resolved</span>`: the inner becomes
	// chipEscape($resolve(KEY)) and editor-only attributes (the editor adds
	// contenteditable="false" to make the chip atomic) are dropped. The opening
	// tag is RECONSTRUCTED from the captured KEY so JS and PHP stay byte-identical.
	// $resolve returns the unescaped value ('' when no provider/scope). Static-
	// only fast path: content with no chip is returned untouched.
	public static function resolveChips(string $html, callable $resolve): string
	{
		if (strpos($html, 'data-pb-field=') === false)
			return $html;
		return preg_replace_callback(
			'/<span\b[^>]*?\bdata-pb-field="([^"]*)"[^>]*>(.*?)<\/span>/s',
			static function (array $m) use ($resolve): string {
				return '<span data-pb-field="' . $m[1] . '">' . self::chipEscape($resolve($m[1])) . '</span>';
			},
			$html
		);
	}

	public static function directionClasses(?string $direction): string
	{
		// `stack` is a marker class only; the overlay layout is driven by inline
		// styles the container template emits (mirror of _common.js directionClasses).
		if ($direction === 'stack')
			return 'pb-stack';
		return $direction === 'horizontal' ? 'd-flex flex-row' : 'd-flex flex-column';
	}

	public static function colCount($cols): int
	{
		if (self::isList($cols))
			return count($cols);
		if (is_array($cols)) {
			foreach (self::BREAKPOINTS as $bp) {
				if (isset($cols[$bp]) and self::isList($cols[$bp]))
					return count($cols[$bp]);
			}
		}
		return 0;
	}

	public static function colClasses($cols, int $i): string
	{
		if (self::isList($cols)) {
			$w = $cols[$i] ?? null;
			return $w === null ? '' : "col-$w";
		}
		if (is_array($cols)) {
			$parts = [];
			foreach (self::BREAKPOINTS as $bp) {
				if (!isset($cols[$bp]) or !self::isList($cols[$bp]))
					continue;
				$arr = $cols[$bp];
				if (!array_key_exists($i, $arr))
					continue;
				$w = $arr[$i];
				if ($w === null)
					continue;
				$parts[] = $bp === 'xs' ? "col-$w" : "col-$bp-$w";
			}
			return implode(' ', $parts);
		}
		return '';
	}

	private static function isList($v): bool
	{
		if (!is_array($v))
			return false;
		if ($v === [])
			return true;
		return array_is_list($v);
	}

	private static function toIntOrNull($v): ?int
	{
		if (is_int($v))
			return $v;
		if (is_string($v) and $v !== '' and ctype_digit($v))
			return (int)$v;
		return null;
	}
}
