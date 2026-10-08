<?php
// Omeka S local configuration for the Docker image (Linux).
return [
    'logger' => [
        'log' => true,
        'priority' => \Laminas\Log\Logger::WARN,
    ],
    'http_client' => [
        'sslcapath' => null,
        'sslcafile' => null,
    ],
    'cli' => [
        // Background jobs (imports, reindexing) run through PHP-CLI.
        'phpcli_path' => '/usr/local/bin/php',
    ],
    'thumbnails' => [
        'types' => [
            'large' => ['constraint' => 800],
            'medium' => ['constraint' => 200],
            'square' => ['constraint' => 200],
        ],
        'thumbnailer_options' => [
            'imagemagick_dir' => '/usr/bin',
        ],
    ],
    'translator' => [
        'locale' => 'en_US',
    ],
    'service_manager' => [
        'aliases' => [
            'Omeka\File\Store' => 'Omeka\File\Store\Local',
            'Omeka\File\Thumbnailer' => 'Omeka\File\Thumbnailer\ImageMagick',
        ],
    ],
];
