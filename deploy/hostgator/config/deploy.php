<?php

declare(strict_types=1);

return [
    // Production application source is copied only from this allowlist.
    'include' => [
        'app',
        'bootstrap',
        'config',
        'database/migrations',
        'public/assets',
        'public/index.php',
        'resources',
        'routes',
        'composer.json',
        'composer.lock',
    ],

    // These paths document intentional omissions even though the allowlist is primary.
    'ignore' => [
        '.git',
        '.github',
        '.phpunit.cache',
        'bin',
        'deploy',
        'docs',
        'tests',
        'phpunit.xml',
        'public/uploads',
        'storage/cache',
        'storage/logs',
        'vendor',
    ],

    // A build fails if one of these names or extensions reaches the generated mirror.
    'protected_names' => [
        '.env',
        '.env.example',
        '.htaccess',
        '.user.ini',
        'php.ini',
        'error_log',
    ],
    'protected_extensions' => [
        'log',
        'pem',
        'key',
        'crt',
        'p12',
        'pfx',
    ],
    'protected_directories' => [
        '.git',
        '.github',
        '.phpunit.cache',
        '.well-known',
        'backups',
        'cache',
        'cgi-bin',
        'logs',
        'ssl',
        'tests',
        'tmp',
        'uploads',
    ],
    'sensitive_name_patterns' => [
        '/secret/i',
        '/credential/i',
        '/private[-_]?key/i',
    ],
];
