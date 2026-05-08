<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'SecurePress',
        'env' => 'development',
        'debug' => false,
    ],
    'requirements' => [
        'php' => '8.2.0',
        'wordpress' => '6.4',
    ],
    'logging' => [
        'channel' => 'file',
        'level' => 'info',
        'file' => 'securepress.log',
    ],
];
