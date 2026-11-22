<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RomSaves
{
    public function __construct(private readonly string $directory) {}

    public function list(string $rom): array
    {
        $directory = $this->saveDirectory($rom);
        if (!is_dir($directory)) {
            return [];
        }
        $saves = [];
        foreach (new \FilesystemIterator($directory) as $file) {
            if ($file->isFile() && !$file->isLink() && $file->isReadable()
                && preg_match('/^([a-f0-9]{32})--(.+)$/uD', $file->getFilename(), $parts)) {
                $saves[] = ['id' => $parts[1], 'name' => $parts[2], 'size' => $file->getSize(), 'modified' => $file->getMTime()];
            }
        }
        usort($saves, static fn (array $a, array $b): int => ($b['modified'] <=> $a['modified']) ?: strcmp($a['id'], $b['id']));

        return $saves;
    }

    public function upload(string $rom, UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new BadRequestHttpException('Le transfert de la sauvegarde a échoué.');
        }
        $name = $this->importName($file->getClientOriginalName(), $file->getSize() ?: 0);
        $directory = $this->saveDirectory($rom);
        $id = bin2hex(random_bytes(16));
        $size = $file->getSize();
        $staging = $directory.'/.import-'.$id;
        try {
            (new Filesystem())->mkdir($directory);
            $file->move($directory, basename($staging));
            if (!@rename($staging, $directory.'/'.$id.'--'.$name)) {
                throw new HttpException(500, 'La sauvegarde n’a pas pu être finalisée.');
            }
        } catch (FileException | \Symfony\Component\Filesystem\Exception\IOExceptionInterface) {
            throw new HttpException(500, 'La sauvegarde n’a pas pu être enregistrée.');
        } finally {
            if (is_file($staging)) {
                @unlink($staging);
            }
        }

        return ['id' => $id, 'name' => $name, 'size' => $size, 'modified' => time()];
    }

    public function importName(string $name, int $size): string
    {
        if ($size < 1 || $size > 268_435_456) {
            throw new BadRequestHttpException('Sélectionnez une sauvegarde de 256 Mio maximum.');
        }
        $name = mb_strcut(trim((string) preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', $name), ' .'), 0, 160);
        if (!preg_match('/\.(sav|srm|dsv|save|rtc|bin|dat|zip|sgm|state\d*)$/iD', $name)) {
            throw new BadRequestHttpException('Format de sauvegarde non pris en charge.');
        }

        return $name;
    }

    public function file(string $rom, string $id): array
    {
        foreach ($this->list($rom) as $save) {
            if ($id === $save['id']) {
                return [$this->saveDirectory($rom).'/'.$id.'--'.$save['name'], $save['name']];
            }
        }

        throw new NotFoundHttpException('Sauvegarde introuvable.');
    }

    public function delete(string $rom, string $id): void
    {
        [$file] = $this->file($rom, $id);
        if (!@unlink($file)) {
            throw new HttpException(500, 'La sauvegarde n’a pas pu être supprimée.');
        }
    }

    private function saveDirectory(string $rom): string
    {
        return $this->directory.'/.sauvegardes/'.$rom;
    }
}
