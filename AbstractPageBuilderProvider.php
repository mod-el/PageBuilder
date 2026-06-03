<?php namespace Model\PageBuilder;

use Model\ProvidersFinder\AbstractProvider;

/**
 * Base class a ModEl package extends to contribute page-builder components.
 *
 * Discovered automatically by the providers-finder package: the bridge calls
 * `\Model\ProvidersFinder\Providers::find('PageBuilderProvider')`, collects each
 * provider's `components()`, and merges them with the host's global `components`
 * config (see PageBuilder::components()). When both the page-builder module and a
 * provider package are installed, the package's components "just show up" in the
 * editor with no host config — exactly like AbstractAssetsProvider.
 *
 * `components()` returns the SAME shape as the global `components` config map
 * (keyed by the component `type`, a kebab-case string):
 *
 *   'slider' => [
 *       'label'           => 'Slider',
 *       'category'        => 'Avanzato',                 // optional palette grouping
 *       'icon'            => 'fa fa-images',             // optional
 *       'acceptsChildren' => true,                        // container component
 *       'iterates'        => true,                        // renders children once per bound item
 *       'configSchema'    => [ ['key'=>'type','type'=>'select', …], … ],
 *       'defaultConfig'   => [ … ],                        // optional
 *       'supportsCommon'  => true,                          // optional, default true
 *       'minWidth'        => 200,                            // optional
 *       'template'        => __DIR__ . '/PageBuilder/slider.php',  // required, must exist
 *   ]
 *
 * The PHP `template` is the single source of truth for public rendering. A LEAF
 * static component (no `acceptsChildren`) is server-rendered: the editor fetches
 * its preview from the render-node route. A CONTAINER (`acceptsChildren`, usually
 * with `iterates`) is rendered in-canvas by the editor's default container render
 * (so its children stay authorable) and on the public side by this template.
 * `configSchema` must be serializable — `options` must be arrays and `when`
 * predicates / closures are unsupported (they can't cross to the JS editor).
 *
 * Runtime safety: providers-finder is a framework runtime dependency, so the
 * `extends AbstractProvider` reference resolves. The only caller of
 * `Providers::find('PageBuilderProvider')` is the page-builder bridge, so a
 * provider class — and this parent — is only ever autoloaded when the page-builder
 * module is installed: a provider package installed without it never fatals.
 */
abstract class AbstractPageBuilderProvider extends AbstractProvider
{
	/**
	 * Return a `type => definition` map of components contributed by the package.
	 *
	 * @return array
	 */
	abstract public static function components(): array;
}
