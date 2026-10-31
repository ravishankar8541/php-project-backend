<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/store.php';

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', getenv('ALLOWED_ORIGIN') ?: 'http://localhost:5173,https://php-project-pearl.vercel.app'),
)));

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/');
if ($path === '' || $path === '/api') {
    $path = '/api/todos';
}

/** @return array<string, mixed> */
function payload(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Request body must be valid JSON.');
    }

    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Request body must be a JSON object.');
    }

    return $decoded;
}

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

try {
    $store = null;
    $getStore = static function () use (&$store): TodoRepository {
        return $store ??= make_store();
    };

    if ($path === '/api/health') {
        $driver = resolve_driver();
        $storage = 'ok';

        try {
            $getStore();
        } catch (Throwable $e) {
            $storage = 'unavailable: ' . $e->getMessage();
        }

        respond(['status' => 'ok', 'driver' => $driver, 'storage' => $storage, 'time' => gmdate('c')]);
    }

    $store = $getStore();

    if ($path === '/api/todos/completed' && $method === 'DELETE') {
        respond(['removed' => $store->clearCompleted()]);
    }

    if ($path === '/api/todos') {
        match ($method) {
            'GET' => respond(['todos' => $store->list()]),
            'POST' => respond(
                ['todo' => $store->create((string) (payload()['title'] ?? ''), (string) (payload()['notes'] ?? ''))],
                201
            ),
            default => respond(['error' => 'Method not allowed.'], 405),
        };
    }

    if (preg_match('#^/api/todos/([0-9a-f]{1,32})$#', $path, $matches) === 1) {
        $id = $matches[1];

        if ($method === 'GET') {
            $todo = $store->get($id);
            $todo === null ? respond(['error' => 'Todo not found.'], 404) : respond(['todo' => $todo]);
        }

        if ($method === 'PATCH' || $method === 'PUT') {
            $todo = $store->update($id, payload());
            $todo === null ? respond(['error' => 'Todo not found.'], 404) : respond(['todo' => $todo]);
        }

        if ($method === 'DELETE') {
            $store->delete($id)
                ? respond(['deleted' => $id])
                : respond(['error' => 'Todo not found.'], 404);
        }

        respond(['error' => 'Method not allowed.'], 405);
    }

    respond(['error' => 'Route not found.'], 404);
} catch (InvalidArgumentException $e) {
    respond(['error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    respond(['error' => 'Storage unavailable: ' . $e->getMessage()], 503);
} catch (Throwable $e) {
    respond(['error' => 'Unexpected server error.'], 500);
}
