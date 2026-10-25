<?php

return [
    'driver' => 'argon2id',
    'argon' => ['memory' => (int) env('ARGON_MEMORY', 65536), 'threads' => 1, 'time' => (int) env('ARGON_TIME', 4), 'verify' => false],
    'bcrypt' => ['rounds' => env('BCRYPT_ROUNDS', 12), 'verify' => false],
    'rehash_on_login' => true,
];
