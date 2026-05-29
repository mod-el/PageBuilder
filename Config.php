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
				. " */\n";

			return "<?php\n\$config = " . $defaults . ";\n" . $example;
		});
	}

	public function getConfigData(): ?array
	{
		return [];
	}
}
