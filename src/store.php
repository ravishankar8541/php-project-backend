<?php

declare(strict_types=1);

require_once __DIR__ . '/TodoRepository.php';
require_once __DIR__ . '/TodoStore.php';
require_once __DIR__ . '/MongoTodoStore.php';

if (!defined('DATA_FILE')) {
    define('DATA_FILE', dirname(__DIR__) . '/data/todos.json');
}

/**
 * Storage wiring: picks the MongoDB store or the flat-file store and hands the
 * HTTP layer a TodoRepository either way.
 *
 * Settings resolve in this order: backend/config/mongodb.local.php, then
 * MONGODB_* environment variables, then the defaults below.
 */

function backend_root(): string
{
    return dirname(__DIR__);
}

/** @return array<string, mixed> */
function mongo_file_config(): array
{
    $file = backend_root() . '/config/mongodb.local.php';

    return is_file($file) ? (array) require $file : [];
}

/** @return array{driver: string, uri: string, database: string, collection: string} */
function mongo_settings(): array
{
    $file = mongo_file_config();
    $setting = static function (string $key, string $default) use ($file): string {
        $env = getenv('MONGODB_' . strtoupper($key));

        return $env !== false && $env !== ''
            ? (string) $env
            : (string) ($file[$key] ?? $default);
    };

    return [
        'driver' => $setting('driver', ''),
        'uri' => $setting('uri', 'mongodb://127.0.0.1:27017'),
        'database' => $setting('database', 'todos'),
        'collection' => $setting('collection', 'todos'),
    ];
}

/** JSON store path, overridable with TODO_DATA_FILE. */
function json_data_file(): string
{
    return (string) (getenv('TODO_DATA_FILE') !== false ? getenv('TODO_DATA_FILE') : DATA_FILE);
}

/** Redact the password so connection strings are safe to print. */
function mask_mongo_uri(string $uri): string
{
    return preg_replace(
        '#^(mongodb(?:\+srv)?://[^:/@]+):[^@]*@#i',
        '$1:***@',
        $uri
    ) ?? $uri;
}

/** @return 'json'|'mongodb' */
function resolve_driver(): string
{
    $env = getenv('DB_DRIVER');
    $driver = $env !== false && $env !== '' ? $env : mongo_settings()['driver'];

    return $driver === 'mongodb' ? 'mongodb' : 'json';
}

function connect_mongo(array $settings): MongoDB\Driver\Manager
{
    if (!class_exists(MongoDB\Driver\Manager::class)) {
        throw new RuntimeException(
            'The mongodb PHP extension is not loaded. Install php_mongodb for PHP 8.3 '
            . '(TS, VS16, x64) and enable extension=mongodb in backend/php.ini.'
        );
    }

    return new MongoDB\Driver\Manager($settings['uri'], [], [
        'appName' => 'todo-app',
    ]);
}

function make_store(): TodoRepository
{
    $settings = mongo_settings();

    if (resolve_driver() === 'json') {
        return new TodoStore(json_data_file());
    }

    try {
        $manager = connect_mongo($settings);
    } catch (Throwable $e) {
        throw new RuntimeException(sprintf(
            'Cannot connect to MongoDB at %s (database %s). %s',
            mask_mongo_uri($settings['uri']),
            $settings['database'],
            $e->getMessage()
        ));
    }

    return new MongoTodoStore($manager, $settings['database'], $settings['collection']);
}
