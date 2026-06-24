<?php namespace Model\PageBuilder\Elements;

use Model\ORM\Element;

/**
 * A single reusable fragment in the global library. Read/written through the
 * Fragments ORM adapter (and ModelFragmentProvider on the render side); editable
 * in normal ModEl admin, where `doc` mounts the very same page-builder editor.
 *
 * `doc` stores a full document `{version:1, root:[...]}` as JSON. The
 * `page-builder` field type backs onto a textarea, so the JSON round-trips
 * unchanged when the record is edited by hand.
 *
 * `source` is the optional data source the fragment is designed against (e.g.
 * "hotels"); `doc` declares `scopeSourceField => 'source'` so its mounted editor
 * reads that sibling value and offers the source's fields while authoring the
 * fragment body (and locks the single-item picker on dropped instances).
 */
class PageBuilderFragment extends Element
{
	public static ?string $table = 'page_builder_fragments';

	public static array $fields = [
		'source' => [
			'type' => 'text',
		],
		'doc' => [
			'type' => 'page-builder',
			'scopeSourceField' => 'source',
		],
	];
}
