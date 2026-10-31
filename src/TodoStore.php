<?php

declare(strict_types=1);

require_once __DIR__ . '/TodoRepository.php';
require_once __DIR__ . '/ValidatesTitle.php';

/**
 * Flat-file todo store. Every write happens under one exclusive lock so
 * concurrent requests cannot clobber each other's changes.
 */
final class TodoStore implements TodoRepository
{
    use ValidatesTitle;

    private string $path;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create data directory: {$dir}");
        }
        if (!is_file($path) && file_put_contents($path, "[]") === false) {
            throw new RuntimeException("Cannot create data file: {$path}");
        }
        $this->path = $path;
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$this->path}");
        }
        flock($handle, LOCK_SH);
        $raw = stream_get_contents($handle) ?: '';
        flock($handle, LOCK_UN);
        fclose($handle);

        return $this->decode($raw);
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        foreach ($this->list() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function create(string $title, string $notes = ''): array
    {
        $title = $this->normalizeTitle($title);
        $now = gmdate('Y-m-d\TH:i:s+00:00');

        return $this->mutate(function (array $items) use ($title, $notes, $now): array {
            $todo = [
                'id' => bin2hex(random_bytes(8)),
                'title' => $title,
                'notes' => trim($notes),
                'completed' => false,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];
            $items[] = $todo;

            return [$items, $todo];
        });
    }

    /**
     * @param array{title?: mixed, notes?: mixed, completed?: mixed} $patch
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $patch): ?array
    {
        return $this->mutate(function (array $items) use ($id, $patch): array {
            foreach ($items as $index => $item) {
                if ($item['id'] !== $id) {
                    continue;
                }

                if (array_key_exists('title', $patch)) {
                    $items[$index]['title'] = $this->normalizeTitle((string) $patch['title']);
                }
                if (array_key_exists('notes', $patch)) {
                    $items[$index]['notes'] = trim((string) $patch['notes']);
                }
                if (array_key_exists('completed', $patch)) {
                    $items[$index]['completed'] = (bool) $patch['completed'];
                }
                $items[$index]['updatedAt'] = gmdate('Y-m-d\TH:i:s+00:00');

                return [$items, $items[$index]];
            }

            return [$items, null];
        });
    }

    public function delete(string $id): bool
    {
        return $this->mutate(function (array $items) use ($id): array {
            $kept = array_values(array_filter(
                $items,
                static fn (array $item): bool => $item['id'] !== $id
            ));

            return [$kept, count($kept) !== count($items)];
        });
    }

    /** @return int Number of rows removed. */
    public function clearCompleted(): int
    {
        return $this->mutate(function (array $items): array {
            $kept = array_values(array_filter(
                $items,
                static fn (array $item): bool => $item['completed'] !== true
            ));

            return [$kept, count($items) - count($kept)];
        });
    }

    /**
     * Run $fn against the current rows while holding LOCK_EX, then persist.
     *
     * @template T
     * @param callable(list<array<string, mixed>>): array{0: list<array<string, mixed>>, 1: T} $fn
     * @return T
     */
    private function mutate(callable $fn): mixed
    {
        $handle = fopen($this->path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$this->path}");
        }
        flock($handle, LOCK_EX);

        rewind($handle);
        $items = $this->decode((string) stream_get_contents($handle));

        [$items, $result] = $fn($items);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function decode(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Data file is corrupted; it must contain a JSON array.');
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Data file must contain a JSON array.');
        }

        return array_values(array_filter($decoded, 'is_array'));
    }
}
