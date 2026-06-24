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
 */
class PageBuilderFragment extends Element
{
	public static ?string $table = 'page_builder_fragments';

	public static array $fields = [
		'doc' => [
			'type' => 'page-builder',
		],
	];
}
