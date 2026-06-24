<?php namespace Model\PageBuilder;

use Model\Core\Core;

/**
 * ORM adapter for the global fragment library. The expected default element is
 * `PageBuilderFragment` with fields: id, name, category, doc.
 */
class Fragments
{
	public function __construct(private Core $model, private string $element = 'PageBuilderFragment')
	{
	}

	public function list(): array
	{
		try {
			$items = $this->model->_ORM->all($this->element, [], ['stream' => false]);
		} catch (\Throwable $e) {
			return [];
		}

		$out = [];
		foreach ($this->toList($items) as $item) {
			$row = $this->shape($item);
			if ($row !== null)
				$out[] = $row;
		}
		return $out;
	}

	public function get(string $id): ?array
	{
		$item = $this->find($id);
		if (!$item)
			return null;
		$row = $this->shape($item);
		return $row['doc'] ?? null;
	}

	public function save(string $name, string $category, array $doc): ?string
	{
		$data = [
			'name' => $name,
			'category' => $category,
			'doc' => json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		];

		try {
			$orm = $this->model->_ORM;
			$item = null;
			if (method_exists($orm, 'create'))
				$item = $orm->create($this->element, $data);
			elseif (method_exists($orm, 'insert'))
				$item = $orm->insert($this->element, $data);
			elseif (method_exists($orm, 'make'))
				$item = $orm->make($this->element, $data);

			if (is_object($item)) {
				foreach ($data as $k => $v)
					$this->setField($item, $k, $v);
				if (method_exists($item, 'save'))
					$item->save();
				return (string)$this->field($item, 'id');
			}

			if (is_string($item) or is_numeric($item))
				return (string)$item;
		} catch (\Throwable $e) {
			return null;
		}

		return null;
	}

	public function delete(string $id): bool
	{
		try {
			$item = $this->find($id);
			if ($item and is_object($item) and method_exists($item, 'delete')) {
				$item->delete();
				return true;
			}
			$orm = $this->model->_ORM;
			if (method_exists($orm, 'delete')) {
				$orm->delete($this->element, ['id' => $id]);
				return true;
			}
		} catch (\Throwable $e) {
			return false;
		}
		return false;
	}

	private function find(string $id)
	{
		try {
			$item = $this->model->_ORM->one($this->element, ['id' => $id], ['stream' => false, 'limit' => 1]);
			if (is_object($item) and method_exists($item, 'exists') and !$item->exists())
				return null;
			return $item ?: null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function shape($item): ?array
	{
		$id = $this->field($item, 'id');
		$name = $this->field($item, 'name');
		$doc = $this->field($item, 'doc');
		if ($id === null or $name === null)
			return null;
		if (is_string($doc)) {
			$decoded = json_decode($doc, true);
			$doc = is_array($decoded) ? $decoded : null;
		}
		if (!is_array($doc) or !isset($doc['version']) or $doc['version'] !== 1 or !isset($doc['root']) or !is_array($doc['root']))
			return null;
		return [
			'id' => (string)$id,
			'name' => (string)$name,
			'category' => (string)($this->field($item, 'category') ?? ''),
			'doc' => $doc,
		];
	}

	private function field($item, string $field)
	{
		if (is_array($item))
			return $item[$field] ?? null;
		if (is_object($item) and method_exists($item, 'offsetGet')) {
			try {
				return $item[$field];
			} catch (\Throwable $e) {
			}
		}
		if (is_object($item) and isset($item->{$field}))
			return $item->{$field};
		return null;
	}

	private function setField(object $item, string $field, $value): void
	{
		if (method_exists($item, 'offsetSet')) {
			try {
				$item[$field] = $value;
				return;
			} catch (\Throwable $e) {
			}
		}
		$item->{$field} = $value;
	}

	private function toList($value): array
	{
		if (is_array($value))
			return array_values($value);
		if ($value instanceof \Traversable)
			return array_values(iterator_to_array($value));
		return [];
	}
}
