<?php

declare(strict_types=1);

require_once __DIR__ . '/TodoRepository.php';
require_once __DIR__ . '/ValidatesTitle.php';

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

/**
 * MongoDB-backed todo repository.
 *
 * Built on the low-level `mongodb` PECL driver (MongoDB\Driver\*) so no Composer
 * package is required. Documents keep the application id in `_id`, which lets
 * rows move between this store and the JSON store without re-keying.
 */
final class MongoTodoStore implements TodoRepository
{
    use ValidatesTitle;

    private Manager $manager;
    private string $namespace;

    /** @var array<string, string> */
    private const TYPE_MAP = [
        'root' => 'array',
        'document' => 'array',
        'array' => 'array',
    ];

    public function __construct(Manager $manager, string $database, string $collection = 'todos')
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $collection) !== 1) {
            throw new RuntimeException('Database and collection names may only contain letters, digits and underscores.');
        }

        $this->manager = $manager;
        $this->namespace = $database . '.' . $collection;
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $cursor = $this->manager->executeQuery(
            $this->namespace,
            new Query([], ['sort' => ['createdAt' => 1, '_id' => 1]])
        );
        $cursor->setTypeMap(self::TYPE_MAP);

        $records = [];
        foreach ($cursor as $document) {
            $records[] = $this->toRecord($document);
        }

        return $records;
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        $cursor = $this->manager->executeQuery($this->namespace, new Query(['_id' => $id]));
        $cursor->setTypeMap(self::TYPE_MAP);

        foreach ($cursor as $document) {
            return $this->toRecord($document);
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function create(string $title, string $notes = ''): array
    {
        $now = gmdate('Y-m-d\TH:i:s+00:00');

        $document = [
            '_id' => bin2hex(random_bytes(16)),
            'title' => $this->normalizeTitle($title),
            'notes' => trim($notes),
            'completed' => false,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];

        $bulk = new BulkWrite();
        $bulk->insert($document);
        $this->manager->executeBulkWrite($this->namespace, $bulk);

        return $this->toRecord($document);
    }

    /**
     * @param array{title?: mixed, notes?: mixed, completed?: mixed} $patch
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $patch): ?array
    {
        $set = [];

        if (array_key_exists('title', $patch)) {
            $set['title'] = $this->normalizeTitle((string) $patch['title']);
        }
        if (array_key_exists('notes', $patch)) {
            $set['notes'] = trim((string) $patch['notes']);
        }
        if (array_key_exists('completed', $patch)) {
            $set['completed'] = (bool) $patch['completed'];
        }

        if ($set !== []) {
            $set['updatedAt'] = gmdate('Y-m-d\TH:i:s+00:00');

            $bulk = new BulkWrite();
            $bulk->update(['_id' => $id], ['$set' => $set], ['multi' => false, 'limit' => 1]);
            $this->manager->executeBulkWrite($this->namespace, $bulk);
        }

        return $this->get($id);
    }

    public function delete(string $id): bool
    {
        $bulk = new BulkWrite();
        $bulk->delete(['_id' => $id], ['limit' => 1]);

        return $this->manager->executeBulkWrite($this->namespace, $bulk)->getDeletedCount() > 0;
    }

    public function clearCompleted(): int
    {
        $bulk = new BulkWrite();
        $bulk->delete(['completed' => true]);

        return $this->manager->executeBulkWrite($this->namespace, $bulk)->getDeletedCount();
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    private function toRecord(array $document): array
    {
        return [
            'id' => (string) ($document['_id'] ?? ''),
            'title' => (string) ($document['title'] ?? ''),
            'notes' => (string) ($document['notes'] ?? ''),
            'completed' => (bool) ($document['completed'] ?? false),
            'createdAt' => (string) ($document['createdAt'] ?? ''),
            'updatedAt' => (string) ($document['updatedAt'] ?? ''),
        ];
    }
}
