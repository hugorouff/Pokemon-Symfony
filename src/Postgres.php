<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class Postgres
{
    private ?\PDO $connection = null;

    public function enabled(): bool
    {
        return self::setting('DATABASE_URL') !== '';
    }

    public static function setting(string $key): string
    {
        return trim((string) ($_SERVER[$key] ?? $_ENV[$key] ?? getenv($key)));
    }

    public function resetConnection(): void
    {
        $this->connection = null;
    }

    public function connection(): \PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $url = parse_url(self::setting('DATABASE_URL'));
        if (!is_array($url) || !in_array($url['scheme'] ?? '', ['postgresql', 'postgres'], true)
            || !isset($url['host'], $url['path'], $url['user']) || !extension_loaded('pdo_pgsql')) {
            throw new ServiceUnavailableHttpException(null, 'Configurez DATABASE_URL avec une URL PostgreSQL et activez pdo_pgsql.');
        }
        parse_str($url['query'] ?? '', $options);
        $parts = [
            'host' => $url['host'],
            'port' => (string) ($url['port'] ?? 5432),
            'dbname' => rawurldecode(substr($url['path'], 1)),
            'sslmode' => $options['sslmode'] ?? 'require',
            'channel_binding' => $options['channel_binding'] ?? 'prefer',
            'connect_timeout' => '5',
        ];
        foreach ($parts as $value) {
            if (!is_string($value) || $value === '' || preg_match('/[\s;\'\\\\]/', $value)) {
                throw new ServiceUnavailableHttpException(null, 'L’URL PostgreSQL est invalide.');
            }
        }
        $dsn = 'pgsql:'.implode(';', array_map(static fn ($key, $value) => $key.'='.$value, array_keys($parts), $parts));

        return $this->connection = new \PDO($dsn, rawurldecode($url['user']), rawurldecode($url['pass'] ?? ''), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
