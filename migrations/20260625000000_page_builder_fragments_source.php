<?php

use Phinx\Migration\AbstractMigration;

/**
 * Adds the optional `source` column to the global fragment library. It records
 * the data source a fragment is *designed against* (e.g. "hotels") — pure
 * authoring metadata that drives the definition editor's field pickers and the
 * locked single-item picker on dropped instances. Never affects rendered output
 * (production binding lives on each instance's `config.item`), so it is nullable.
 */
class PageBuilderFragmentsSource extends AbstractMigration
{
	public function change()
	{
		if ($this->hasTable('page_builder_fragments')) {
			$table = $this->table('page_builder_fragments');
			if (!$table->hasColumn('source')) {
				$table->addColumn('source', 'string', ['limit' => 255, 'null' => true, 'after' => 'category'])
					->update();
			}
		}
	}
}
