<?php

return [
    'driver' => env('HASH_DRIVER', 'argon2id'),
    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => false,
        'limit' => env('BCRYPT_LIMIT'),
    ],
    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        // Existing bcrypt accounts and challenges remain verifiable during the upgrade.
        'verify' => false,
    ],
    'rehash_on_login' => true,
];
