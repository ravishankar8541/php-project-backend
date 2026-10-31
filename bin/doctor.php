<?php

declare(strict_types=1);

/**
 * Print the current storage verdict: which driver is selected, whether its
 * prerequisites are met, and the exact command to fix it if they are not.
 *
 *   php -c backend/php.ini backend/bin/doctor.php
 */

require dirname(__DIR__) . '/src/store.php';

$lines = [];
$ready = true;

$report = static function (bool $ok, string $label, string $detail) use (&$lines, &$ready): void {
    $lines[] = sprintf('%s  %-12s %s', $ok ? 'PASS' : 'FAIL', $label, $detail);
    if (!$ok) {
        $ready = false;
    }
};

$driver = resolve_driver();
$settings = mongo_settings();

$report(true, 'driver', $driver === 'mongodb'
    ? 'mongodb  (' . mask_mongo_uri($settings['uri']) . ' / db ' . $settings['database'] . ')'
    : 'json     (' . json_data_file() . ')');

if ($driver === 'json') {
    $path = json_data_file();
    $dir = dirname($path);
    $report(is_dir($dir) || mkdir($dir, 0775, true), 'data dir', $dir);

    try {
        $rows = (new TodoStore($path))->list();
        $report(is_file($path) ? is_writable($path) : true, 'file writable', $path);
        $report(true, 'docs', (string) count($rows));
    } catch (Throwable $e) {
        $report(false, 'storage', $e->getMessage());
    }
} else {
    $hasExtension = class_exists(MongoDB\Driver\Manager::class);
    $report(
        $hasExtension,
        'mongodb ext',
        $hasExtension
            ? 'loaded (' . phpversion('mongodb') . ')'
            : 'not loaded — run this script with the project ini: php -c backend/php.ini backend/bin/doctor.php'
    );

    if ($hasExtension) {
        try {
            $manager = connect_mongo($settings);
            $manager->executeCommand($settings['database'], new MongoDB\Driver\Command(['ping' => 1]));
            $report(true, 'ping', mask_mongo_uri($settings['uri']));

            $collections = $manager
                ->executeCommand($settings['database'], new MongoDB\Driver\Command(['listCollections' => 1]))
                ->toArray();

            $exists = false;
            foreach ($collections as $info) {
                if (($info->name ?? '') === $settings['collection']) {
                    $exists = true;
                }
            }

            $report(
                $exists,
                'collection',
                $exists
                    ? $settings['database'] . '.' . $settings['collection']
                    : 'missing — run: php -c backend/php.ini backend/bin/migrate.php'
            );

            if ($exists) {
                $count = $manager->executeCommand($settings['database'], new MongoDB\Driver\Command([
                    'count' => $settings['collection'],
                ]))->toArray()[0]->n ?? 0;
                $report(true, 'docs', (string) $count);
            }
        } catch (Throwable $e) {
            $report(false, 'connect', $e->getMessage());
        }
    }
}

echo implode(PHP_EOL, $lines), PHP_EOL, PHP_EOL;
echo $ready ? 'READY' : 'NOT READY', PHP_EOL;
exit($ready ? 0 : 1);
