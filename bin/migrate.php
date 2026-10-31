<?php

declare(strict_types=1);

/**
 * Verify MongoDB connectivity and create the indexes the API sorts and filters by.
 *
 *   php -c backend/php.ini backend/bin/migrate.php
 *
 * Safe to re-run: the index creation ignores "index already exists".
 */

require dirname(__DIR__) . '/src/store.php';

function say(string $line): void
{
    echo $line, PHP_EOL;
}

function fail(string $message): never
{
    say('FAIL  ' . $message);
    exit(1);
}

$settings = mongo_settings();
say('Target   ' . mask_mongo_uri($settings['uri']) . '  database=' . $settings['database'] . '  collection=' . $settings['collection']);

try {
    $manager = connect_mongo($settings);
    $manager->executeCommand($settings['database'], new MongoDB\Driver\Command(['ping' => 1]));
} catch (Throwable $e) {
    fail('Cannot reach MongoDB: ' . $e->getMessage());
}

say('Ping     ok');

$indexes = [
    ['key' => ['completed' => 1], 'name' => 'completed_1'],
    ['key' => ['createdAt' => 1], 'name' => 'createdAt_1'],
];

try {
    $manager->executeCommand($settings['database'], new MongoDB\Driver\Command([
        'createIndexes' => $settings['collection'],
        'indexes' => $indexes,
    ]));
    say('Indexes  ' . implode(', ', array_column($indexes, 'name')));
} catch (MongoDB\Driver\Exception\CommandException $e) {
    // Code 85 means the index set already exists, which is what we want anyway.
    if ($e->getCode() !== 85 && stripos($e->getMessage(), 'already exists') === false) {
        fail('createIndexes: ' . $e->getMessage());
    }
    say('Indexes  already present');
}

$count = $manager->executeCommand($settings['database'], new MongoDB\Driver\Command([
    'count' => $settings['collection'],
]))->toArray()[0]->n ?? 0;

say('Docs     ' . $count);
say('');
say('READY — select this backend with DB_DRIVER=mongodb (or driver => mongodb in backend/config/mongodb.local.php), then:');
say('  php -c backend/php.ini -S 127.0.0.1:8000 -t backend/public backend/public/index.php');
exit(0);
