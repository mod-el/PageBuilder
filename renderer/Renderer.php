<?php namespace Model\PageBuilder\Renderer;

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
class Renderer
{
	private string $templatesPath;
	private string $defaultLang;
	private array $registry;

	private const SIDE_PREFIX = [
		'top'    => 't',
		'bottom' => 'b',
		'left'   => 's',
		'right'  => 'e',
		'x'      => 'x',
		'y'      => 'y',
	];

	private const BREAKPOINTS = ['xs', 'sm', 'md', 'lg', 'xl', 'xxl'];

	public function __construct(string $templatesPath, string $defaultLang = 'it', ?array $registry = null)
	{
		$this->templatesPath = rtrim($templatesPath, "/\\");
		$this->defaultLang = $defaultLang;
		$this->registry = $registry ?? require __DIR__ . '/registry.php';
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

	private function renderNode(array $node, string $lang): string
	{
		$type = (isset($node['type']) && is_string($node['type'])) ? $node['type'] : '';
		if (!isset($this->registry[$type]))
			return '<!-- pb: unknown type "' . self::escapeHtml($type) . '" -->';

		$meta = $this->registry[$type];

		$children = [];
		if (isset($node['children']) and is_array($node['children'])) {
			foreach ($node['children'] as $child) {
				if (is_array($child))
					$children[] = $this->renderNode($child, $lang);
			}
		}

		$rawConfig = (isset($node['config']) && is_array($node['config'])) ? $node['config'] : [];
		$config = $this->resolveMultilang($rawConfig, $meta['multilang'] ?? [], $lang);

		$supportsCommon = ($meta['supportsCommon'] ?? true) !== false;
		$extraClasses = $supportsCommon ? self::computeExtraClasses($rawConfig) : '';

		return $this->loadTemplate($type, $config, $children, $extraClasses);
	}

	private function loadTemplate(string $type, array $config, array $children, string $extraClasses): string
	{
		$path = $this->templatesPath . '/' . $type . '.php';
		if (!file_exists($path))
			throw new RuntimeException("template not found for type \"$type\" at $path");

		$renderer = $this;
		$run = static function (string $__path, array $config, array $children, string $extraClasses, Renderer $renderer): void {
			include $__path;
		};

		ob_start();
		try {
			$run($path, $config, $children, $extraClasses, $renderer);
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

	public static function directionClasses(?string $direction): string
	{
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
