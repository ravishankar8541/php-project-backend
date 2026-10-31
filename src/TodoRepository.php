<?php

declare(strict_types=1);

/**
 * Contract both storage backends satisfy, so the HTTP layer never learns
 * whether todos live in a JSON file or in MongoDB.
 */
interface TodoRepository
{
    /** @return list<array<string, mixed>> */
    public function list(): array;

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array;

    /** @return array<string, mixed> */
    public function create(string $title, string $notes = ''): array;

    /**
     * @param array{title?: mixed, notes?: mixed, completed?: mixed} $patch
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $patch): ?array;

    public function delete(string $id): bool;

    /** @return int Number of rows removed. */
    public function clearCompleted(): int;
}
