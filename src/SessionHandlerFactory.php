<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

final class SessionHandlerFactory
{
    public function create(string $savePath): \SessionHandlerInterface
    {
        // The session handler manages its own transactions, independently of game saves.
        $database = new Postgres();

        return $database->enabled()
            ? new PdoSessionHandler($database->connection(), ['db_table' => 'app_sessions'])
            : new NativeFileSessionHandler($savePath);
    }
}
