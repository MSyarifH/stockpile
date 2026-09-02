<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * PDO factory. The only place in the application that constructs a database handle.
 */
final class Database
{
    /**
     * @param array{host:string,port:string,name:string,user:string,password:string} $config
     */
    public static function connect(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['name'],
        );

        return new PDO($dsn, $config['user'], $config['password'], [
            // Failures must throw, never return false silently.
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements, not client-side interpolation. With emulation on,
            // PDO builds the SQL string itself, which weakens the guarantee that user
            // input is never treated as SQL (§4.2).
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }
}
