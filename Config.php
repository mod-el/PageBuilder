<?php namespace Model\PageBuilder;

use Model\Core\Module_Config;

class Config extends Module_Config
{
	protected function assetsList(): void
	{
		$this->addAsset('config', 'config.php', function () {
			$defaults = var_export([
				'include-host-in-uploads' => false,
				'upload-path' => 'app-data/img/page-builder/',
				'sample-data-limit' => 4,
				'sources' => [],
				'components' => [],
			], true);

			// Closures can't be var_export'd, so the `sources` shape is documented as
			// a comment the dev fills in by hand (see docs/dynamic-data.md §4.1).
			$example = "\n/*\n"
				. " * Dynamic-data sources. Each key is referenced by editor bindings as\n"
				. " * { \"source\": \"<key>\" }. Edit this file to add real sources — closures\n"
				. " * are allowed here (unlike a var_export'd default):\n"
				. " *\n"
				. " * \$config['sources'] = [\n"
				. " *     'hotels' => [\n"
				. " *         'label'   => 'Hotel',           // optional display name (defaults to ucfirst(key))\n"
				. " *         'element' => 'TravioService',  // ORM element class\n"
				. " *         'where'   => ['type' => 2],      // optional ORM where\n"
				. " *         'joins'   => [],                 // optional ORM joins\n"
				. " *         // 'fields' optional: auto-introspected from metadata if omitted\n"
				. " *     ],\n"
				. " *     'custom' => [\n"
				. " *         'retriever' => function (array \$filters, ?int \$limit = null) { return []; },  // returns a list of items (\$filters is currently unused, future implementation)\n"
				. " *         'fields'    => [  // required for a retriever source\n"
				. " *             ['key' => 'name', 'label' => 'Nome', 'type' => 'text'],\n"
				. " *         ],\n"
				. " *     ],\n"
				. " * ];\n"
				. " */\n"
				. "\n/*\n"
				. " * Custom components. Each key is the component `type` (kebab-case). The\n"
				. " * PHP `template` is the single source of truth for public rendering, and\n"
				. " * the config form is auto-generated from `configSchema`. Packages can also\n"
				. " * contribute components automatically via an AbstractPageBuilderProvider\n"
				. " * (same shape as below) — host config here overrides a provider's type.\n"
				. " *\n"
				. " * A component is either a LEAF (no children, server-rendered: the editor\n"
				. " * fetches its preview HTML) or a CONTAINER ('acceptsChildren', usually with\n"
				. " * 'iterates') that reuses the common `binding` + iteration to render its\n"
				. " * authored children once per bound item — like the built-in repeater,\n"
				. " * wrapped server-side by its template (see the slider package). `configSchema`\n"
				. " * must be serializable — `options` must be arrays and `when` predicates are\n"
				. " * unsupported (stripped before reaching the editor).\n"
				. " *\n"
				. " * \$config['components'] = [\n"
				. " *     'pricing-card' => [\n"
				. " *         'label'        => 'Scheda prezzo',     // palette name\n"
				. " *         'category'     => 'Avanzato',           // optional grouping\n"
				. " *         'icon'         => 'fa fa-tag',          // optional\n"
				. " *         'configSchema' => [                      // auto-rendered config form\n"
				. " *             ['key' => 'title', 'type' => 'text',   'label' => 'Titolo', 'multilang' => true],\n"
				. " *             ['key' => 'price', 'type' => 'number', 'label' => 'Prezzo'],\n"
				. " *         ],\n"
				. " *         'defaultConfig' => ['price' => 0],       // optional\n"
				. " *         'supportsCommon' => true,                 // optional, default true\n"
				. " *         'minWidth'      => 200,                    // optional layout hint\n"
				. " *         'template'      => __DIR__ . '/components/pricing-card.php',  // required, must exist\n"
				. " *     ],\n"
				. " * ];\n"
				. " */\n";

			return "<?php\n\$config = " . $defaults . ";\n" . $example;
		});
	}

	public function getConfigData(): ?array
	{
		return [];
	}
}
