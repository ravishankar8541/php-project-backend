<?php

declare(strict_types=1);

/**
 * Copy this file to mongodb.local.php (gitignored) and fill in real settings.
 * Values here win over MONGODB_* environment variables.
 */

return [
    // 'json' keeps the flat-file store; 'mongodb' switches the API to MongoDB.
    'driver' => 'mongodb',

    // Local server:  mongodb://127.0.0.1:27017
    // Auth:          mongodb://user:pass@host:27017/todos?authSource=admin
    // Atlas:         mongodb+srv://user:pass@cluster0.xxxxx.mongodb.net/todos
    'uri' => 'mongodb://127.0.0.1:27017',

    'database' => 'todos',
    'collection' => 'todos',
];
