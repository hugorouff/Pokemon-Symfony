<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PostgresRomSaves
{
    public function __construct(private readonly Postgres $database, private readonly RomSaves $files) {}

    public function enabled(): bool
    {
        return $this->database->enabled();
    }

    public function list(string $rom, string $owner): array
    {
        $query = $this->database->connection()->prepare(
            'SELECT id, name, octet_length(content) AS size, extract(epoch FROM modified_at)::bigint AS modified
             FROM rom_saves WHERE rom_id = ? AND owner_id = ? ORDER BY modified_at DESC, id'
        );
        $query->execute([$rom, $owner]);

        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function upload(string $rom, string $owner, UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new BadRequestHttpException('Le transfert de la sauvegarde a échoué.');
        }
        $size = $file->getSize();
        if ($size > 16 * 1024 * 1024) {
            throw new BadRequestHttpException('Les sauvegardes PostgreSQL sont limitées à 16 Mio par fichier.');
        }
        $name = $this->files->importName($file->getClientOriginalName(), $size ?: 0);
        $content = $file->getContent();
        $id = bin2hex(random_bytes(16));
        $query = $this->database->connection()->prepare(
            "INSERT INTO rom_saves (rom_id, owner_id, id, name, content) VALUES (?, ?, ?, ?, decode(?, 'hex'))
             RETURNING extract(epoch FROM modified_at)::bigint"
        );
        $query->execute([$rom, $owner, $id, $name, bin2hex($content)]);

        return ['id' => $id, 'name' => $name, 'size' => $size, 'modified' => (int) $query->fetchColumn()];
    }

    public function read(string $rom, string $owner, string $id): array
    {
        $query = $this->database->connection()->prepare('SELECT name, content FROM rom_saves WHERE rom_id = ? AND owner_id = ? AND id = ?');
        $query->execute([$rom, $owner, $id]);
        $save = $query->fetch(\PDO::FETCH_ASSOC);
        if ($save === false) {
            throw new NotFoundHttpException('Sauvegarde introuvable.');
        }

        return [is_resource($save['content']) ? stream_get_contents($save['content']) : $save['content'], $save['name']];
    }
}
