<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PostgresRomLibrary
{
    private const CHUNK_SIZE = 1024 * 1024;

    public function __construct(private readonly Postgres $database, private readonly RomLibrary $roms) {}

    public function enabled(): bool
    {
        return Postgres::setting('ROM_POSTGRES') === '1';
    }

    public function list(): array
    {
        $rows = $this->database->connection()->query('SELECT * FROM rom_archives ORDER BY name');
        $games = [];
        foreach ($rows as $row) {
            $games[] = $this->describe($row);
        }

        return $games;
    }

    public function find(string $id): ?array
    {
        $query = $this->database->connection()->prepare('SELECT * FROM rom_archives WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);

        return $row ? $this->describe($row) : null;
    }

    public function import(string $path): bool
    {
        $name = basename($path);
        if (!is_file($path) || !array_key_exists(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $this->roms->cores())) {
            throw new \InvalidArgumentException('Sélectionnez un fichier ROM lisible, par exemple un .nds ou un ZIP contenant un seul .nds.');
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new \InvalidArgumentException('Impossible d’ouvrir la ROM.');
        }

        try {
            $db = $this->database->connection();
            $size = fstat($file)['size'] ?? 0;
            if ($size < 1 || $size > 512 * self::CHUNK_SIZE) {
                throw new \InvalidArgumentException('La ROM doit faire entre 1 octet et 512 Mio.');
            }
            $id = hash('sha256', $name);
            $checksum = hash_init('sha256');
            hash_update_stream($checksum, $file);
            $digest = hash_final($checksum);
            if (!rewind($file)) {
                throw new \RuntimeException('Impossible de relire la ROM.');
            }

            // Keep bound parameters without named statements, including through a transaction pooler.
            $options = [\Pdo\Pgsql::ATTR_DISABLE_PREPARES => true];
            $db->beginTransaction();
            $insert = $db->prepare(
                'INSERT INTO rom_archives (id, name, size, checksum) VALUES (?, ?, ?, ?) ON CONFLICT (id) DO NOTHING',
                $options
            );
            $insert->execute([$id, $name, $size, $digest]);
            if (!$insert->rowCount()) {
                $lookup = $db->prepare('SELECT checksum FROM rom_archives WHERE id = ?', $options);
                $lookup->execute([$id]);
                if ($lookup->fetchColumn() !== $digest) {
                    throw new \InvalidArgumentException('Une ROM différente porte déjà ce nom. Renommez le fichier à importer.');
                }
                $db->commit();

                return false;
            }

            $insert = $db->prepare(
                "INSERT INTO rom_archive_parts (rom_id, part, content) VALUES (?, ?, decode(?, 'base64'))",
                $options
            );
            $written = hash_init('sha256');
            for ($offset = 0, $part = 0; $offset < $size; $part++) {
                $length = min(self::CHUNK_SIZE, $size - $offset);
                $bytes = stream_get_contents($file, $length);
                if ($bytes === false || strlen($bytes) !== $length) {
                    throw new \RuntimeException('Le fichier a changé pendant l’import.');
                }
                hash_update($written, $bytes);
                $insert->execute([$id, $part, base64_encode($bytes)]);
                $offset += $length;
            }
            if (fgetc($file) !== false || hash_final($written) !== $digest) {
                throw new \RuntimeException('Le fichier a changé pendant l’import.');
            }
            $db->commit();

            return true;
        } catch (\Throwable $failure) {
            if (isset($db)) {
                try {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                } catch (\PDOException) {
                    $this->database->resetConnection();
                }
            }

            throw $failure;
        } finally {
            fclose($file);
        }
    }

    public function response(array $rom, Request $request): Response
    {
        $size = $rom['size'];
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $range = $request->headers->get('Range');
        if ($range !== null && !$request->headers->has('If-Range')) {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $match) || ($match[1] === '' && $match[2] === '')) {
                return new Response('', 416, $headers + ['Content-Range' => 'bytes */'.$size]);
            }
            if ($match[1] === '') {
                $start = max(0, $size - (int) $match[2]);
            } else {
                $start = (int) $match[1];
                $end = $match[2] === '' ? $end : min($end, (int) $match[2]);
            }
            if ($start > $end) {
                return new Response('', 416, $headers + ['Content-Range' => 'bytes */'.$size]);
            }
            $status = 206;
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
        }
        $headers['Content-Length'] = (string) ($end - $start + 1);
        if ($request->query->getBoolean('download')) {
            $headers['Content-Disposition'] = HeaderUtils::makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT, $rom['name'], 'jeu.'.pathinfo($rom['name'], PATHINFO_EXTENSION)
            );
        }
        if ($request->isMethod('HEAD')) {
            return new Response('', $status, $headers);
        }

        return new StreamedResponse(function () use ($rom, $start, $end): void {
            $part = $this->database->connection()->prepare(
                'SELECT substring(content FROM ? FOR ?) FROM rom_archive_parts WHERE rom_id = ? AND part = ?'
            );
            for ($offset = $start; $offset <= $end;) {
                $index = intdiv($offset, self::CHUNK_SIZE);
                $within = $offset % self::CHUNK_SIZE;
                $length = min(self::CHUNK_SIZE - $within, $end - $offset + 1);
                $part->execute([$within + 1, $length, $rom['id'], $index]);
                $content = $part->fetchColumn();
                if ($content === false) {
                    throw new NotFoundHttpException('ROM incomplète.');
                }
                echo is_resource($content) ? stream_get_contents($content) : $content;
                flush();
                if (connection_aborted()) {
                    return;
                }
                $offset += $length;
            }
        }, $status, $headers);
    }

    private function describe(array $row): array
    {
        return $this->roms->describe($row['name'], (int) $row['size'], (int) $row['modified'], $row['id']);
    }
}
