<?php

declare(strict_types=1);

use App\Environment;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Cache\File\FileCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;
use Yiisoft\Db\Mysql\Dsn;

return [
    CacheInterface::class => FileCache::class,

    ConnectionInterface::class => static fn(SchemaCache $schemaCache): Connection => new Connection(
        new Driver(
            new Dsn(
                host: (string) Environment::get('DB_HOST'),
                databaseName: (string) Environment::get('DB_NAME'),
                port: (string) Environment::get('DB_PORT'),
                options: ['charset' => 'utf8mb4'],
            ),
            (string) Environment::get('DB_USER'),
            (string) Environment::get('DB_PASSWORD'),
        ),
        $schemaCache,
    ),
];
