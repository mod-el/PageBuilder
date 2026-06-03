<?php namespace Model\PageBuilder\Providers;

use Model\Assets\AbstractAssetsProvider;

/**
 * Declares the editor's CSS/JS as a single `page-builder` assets library.
 *
 * `auto_enable` is false: the editor is admin-only, so the public side never
 * pulls the ~90KB bundle. A host enables it where the field is used (typically
 * `\Model\Assets\Assets::enable('page-builder');` in its admin controller
 * `init()`, like the slider package) — see README.
 */
class AssetsProvider extends AbstractAssetsProvider
{
	public static function assets(): array
	{
		return [
			[
				'name' => 'page-builder',
				'auto_enable' => false,
				'files' => [
					// Paths are relative to PATH (the assets package prepends it). This
					// is an app/model module, so files live under model/PageBuilder/files/
					// (not vendor/). cacheable:false: the bundle is already minified by
					// esbuild, so skip re-minify/combine and keep each file a standalone,
					// correctly-ordered tag. CSS in head (editor chrome ready early); the
					// two JS files in foot, page-builder.min.js before init.js so
					// window.PageBuilder exists before init.js runs.
					['path' => 'model/PageBuilder/files/page-builder.min.css', 'cacheable' => false],
					['path' => 'model/PageBuilder/files/page-builder.min.js', 'withTags' => ['position' => 'foot'], 'cacheable' => false],
					['path' => 'model/PageBuilder/files/init.js', 'withTags' => ['position' => 'foot'], 'cacheable' => false],
				],
			],
		];
	}
}
