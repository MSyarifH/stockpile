<?php

declare(strict_types=1);

/**
 * Environment loader.
 *
 * Values come from the container environment (set by Docker Compose from .env).
 * Nothing here reads a committed secret; .env is git-ignored.
 */

/**
 * @param string $key
 * @param string|null $default
 * @return string
 */
function env_value(string $key, ?string $default = null): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        if ($default === null) {
            throw new RuntimeException(sprintf('Required environment variable "%s" is not set.', $key));
        }
        return $default;
    }
    return $value;
}

return [
    'env' => env_value('APP_ENV', 'production'),
    'db' => [
        'host' => env_value('DB_HOST', 'db'),
        'port' => env_value('DB_PORT', '3306'),
        'name' => env_value('DB_NAME'),
        'user' => env_value('DB_USER'),
        'password' => env_value('DB_PASSWORD'),
    ],
    'uploads' => [
        'path' => dirname(__DIR__) . '/public/uploads',
        'max_bytes' => 2 * 1024 * 1024,
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
    ],
];
