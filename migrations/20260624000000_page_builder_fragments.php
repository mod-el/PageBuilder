<?php

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

/**
 * Global fragment library — backs the PageBuilderFragment Element and the
 * Fragments ORM adapter (fields: id, name, category, doc). `doc` holds a full
 * page-builder document (`{version:1, root:[...]}`) as JSON, so it uses LONGTEXT
 * to comfortably fit large reusable subtrees.
 */
class PageBuilderFragments extends AbstractMigration
{
	public function change()
	{
		if (!$this->hasTable('page_builder_fragments')) {
			$this->table('page_builder_fragments', ['signed' => true])
				->addColumn('name', 'string', ['limit' => 255, 'null' => false])
				->addColumn('category', 'string', ['limit' => 255, 'null' => true])
				->addColumn('doc', 'text', ['limit' => MysqlAdapter::TEXT_LONG, 'null' => false])
				->create();
		}
	}
}
