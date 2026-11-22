<?php

declare(strict_types=1);

namespace App;

final class RomAccounts
{
    public function __construct(private readonly Postgres $database) {}

    public function enabled(): bool
    {
        return Postgres::setting('ROM_USER_ACCOUNTS') === '1';
    }

    public function byEmail(string $email): ?array
    {
        return $this->find('email', strtolower(trim($email)));
    }

    public function byId(string $id): ?array
    {
        return $this->find('id', $id);
    }

    public function create(string $email, bool $rotate): ?string
    {
        $password = bin2hex(random_bytes(16));
        $onConflict = $rotate ? 'DO UPDATE SET password_hash = EXCLUDED.password_hash' : 'DO NOTHING';
        $query = $this->database->connection()->prepare(
            'INSERT INTO rom_accounts (id, email, password_hash) VALUES (?, ?, ?) ON CONFLICT (email) '.$onConflict
        );
        $query->execute([bin2hex(random_bytes(16)), strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT)]);

        return $query->rowCount() ? $password : null;
    }

    public function register(string $email, string $password): ?array
    {
        $query = $this->database->connection()->prepare(
            'INSERT INTO rom_accounts (id, email, password_hash) VALUES (?, ?, ?) ON CONFLICT (email) DO NOTHING RETURNING id, password_hash'
        );
        $query->execute([bin2hex(random_bytes(16)), strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT)]);

        return $query->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function find(string $column, string $value): ?array
    {
        $query = $this->database->connection()->prepare('SELECT id, email, password_hash FROM rom_accounts WHERE '.$column.' = ?');
        $query->execute([$value]);

        return $query->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
