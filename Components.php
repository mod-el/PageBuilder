<?php namespace Model\PageBuilder;

/**
 * Turns the bridge's global `components` config (app/config/PageBuilder/config.php)
 * into the three things the host-side plumbing needs for custom, server-rendered
 * components (see CLAUDE.md "custom components"):
 *
 *   - descriptors()   the data the JS editor needs to register the component in the
 *                     palette + build its config form (type/label/category/icon/
 *                     configSchema/defaultConfig/flags). Serializable subset only.
 *                     init.js adds `serverRender:true` for LEAF components (preview
 *                     HTML fetched from the render-node route); a CONTAINER
 *                     (`acceptsChildren`) is registered as-is and rendered in-canvas
 *                     by the editor's default container render.
 *   - registryMeta()  per-type meta for the PHP Renderer (multilang fields,
 *                     supportsCommon) merged on top of the built-in registry.php.
 *   - templateMap()   type => absolute template path, so the Renderer loads each
 *                     custom component's template from wherever the host declared it.
 *
 * All three read the SAME `components` map. The PHP template a component points at
 * is the single source of truth for rendering: the editor preview and the public
 * output both come from it, so render-parity is automatic.
 *
 * Component config shape (keyed by the component `type`, a kebab-case string):
 *   'pricing-card' => [
 *       'label'          => 'Scheda prezzo',
 *       'category'       => 'Avanzato',                  // optional palette grouping
 *       'icon'           => 'fa fa-tag',                  // optional
 *       'configSchema'   => [ ['key'=>'title','type'=>'text','multilang'=>true], … ],
 *       'defaultConfig'  => ['price' => 0],               // optional
 *       'supportsCommon' => true,                          // optional, default true
 *       'minWidth'       => 200,                            // optional
 *       'template'       => __DIR__ . '/components/pricing-card.php',  // required, must exist
 *   ]
 *
 * A component may be a LEAF (no children, server-rendered via the render-node
 * route) or a CONTAINER (`acceptsChildren`, usually with `iterates`) that reuses
 * the common `binding` + iteration path to render its authored children once per
 * bound item (like the built-in `repeat`), wrapped server-side by its template.
 * `configSchema` must be serializable — `options` must be arrays and `when`
 * predicates are unsupported (closures can't cross to JS; they are stripped here).
 */
class Components
{
	/**
	 * Drop invalid entries and fill defaults. A valid component declares a `template`
	 * whose file exists (a typo'd path silently drops the component, like Sources'
	 * graceful degradation, rather than breaking the whole editor). Pure.
	 */
	public static function normalize(array $components): array
	{
		$out = [];
		foreach ($components as $type => $def) {
			if (!is_string($type) or $type === '' or !is_array($def))
				continue;
			if (!isset($def['template']) or !is_string($def['template']) or !is_file($def['template']))
				continue;
			if (!isset($def['label']) or !is_string($def['label']) or $def['label'] === '')
				$def['label'] = self::humanize($type);
			$out[$type] = $def;
		}
		return $out;
	}

	/**
	 * Build the editor descriptor list (the data side of a component definition).
	 * `serverRender:true` is added client-side by init.js, not here.
	 */
	public static function descriptors(array $components): array
	{
		$components = self::normalize($components);

		$out = [];
		foreach ($components as $type => $def) {
			$acceptsChildren = ($def['acceptsChildren'] ?? false) === true;
			$entry = [
				'type' => $type,
				'label' => $def['label'],
				// Leaf components stay leaf; a container declares acceptsChildren so the
				// editor draws drop zones + (when iterating) per-item children.
				'acceptsChildren' => $acceptsChildren,
				'supportsCommon' => ($def['supportsCommon'] ?? true) !== false,
				'configSchema' => self::publicSchema($def['configSchema'] ?? []),
			];
			// `iterates` makes the editor render the authored children once per bound
			// item (reusing the common `binding` + iteration path, like `repeat`).
			if (($def['iterates'] ?? false) === true)
				$entry['iterates'] = true;
			if (isset($def['allowedChildren']) and is_array($def['allowedChildren']))
				$entry['allowedChildren'] = array_values($def['allowedChildren']);
			if (isset($def['allowedParents']) and is_array($def['allowedParents']))
				$entry['allowedParents'] = array_values($def['allowedParents']);
			if (isset($def['category']) and is_string($def['category']))
				$entry['category'] = $def['category'];
			if (isset($def['icon']) and is_string($def['icon']))
				$entry['icon'] = $def['icon'];
			if (isset($def['minWidth']) and is_numeric($def['minWidth']))
				$entry['minWidth'] = (int)$def['minWidth'];
			if (isset($def['defaultConfig']) and is_array($def['defaultConfig']))
				$entry['defaultConfig'] = self::stripClosures($def['defaultConfig']);
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Per-type meta for the Renderer: which config fields are multilang (so they get
	 * resolved to a string before the template), whether common config applies, and
	 * whether the type iterates (renders authored children once per bound item, so
	 * the Renderer passes the per-item HTML array as $children to the template).
	 */
	public static function registryMeta(array $components): array
	{
		$components = self::normalize($components);

		$out = [];
		foreach ($components as $type => $def) {
			$multilang = [];
			foreach (($def['configSchema'] ?? []) as $field) {
				if (is_array($field) and isset($field['key']) and is_string($field['key']) and !empty($field['multilang']))
					$multilang[] = $field['key'];
			}
			$meta = [
				'multilang' => $multilang,
				'supportsCommon' => ($def['supportsCommon'] ?? true) !== false,
			];
			if (($def['iterates'] ?? false) === true)
				$meta['iterates'] = true;
			$out[$type] = $meta;
		}
		return $out;
	}

	/** type => absolute template path, for Renderer's per-type template override. */
	public static function templateMap(array $components): array
	{
		$components = self::normalize($components);

		$out = [];
		foreach ($components as $type => $def)
			$out[$type] = $def['template'];
		return $out;
	}

	// Keep only the serializable parts of a configSchema: drop `when` predicates and
	// any closure-valued option (they can't be json_encoded for the JS editor).
	private static function publicSchema(array $schema): array
	{
		$out = [];
		foreach ($schema as $field) {
			if (!is_array($field) or !isset($field['key']) or !is_string($field['key']))
				continue;
			$clean = [];
			foreach ($field as $k => $v) {
				if ($k === 'when' or $v instanceof \Closure)
					continue;
				$clean[$k] = is_array($v) ? self::stripClosures($v) : $v;
			}
			$out[] = $clean;
		}
		return $out;
	}

	// Recursively remove closure values from an array (defensive for json_encode).
	private static function stripClosures(array $arr): array
	{
		$out = [];
		foreach ($arr as $k => $v) {
			if ($v instanceof \Closure)
				continue;
			$out[$k] = is_array($v) ? self::stripClosures($v) : $v;
		}
		return $out;
	}

	private static function humanize(string $key): string
	{
		return ucfirst(str_replace(['_', '-'], ' ', $key));
	}
}
