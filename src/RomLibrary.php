<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RomLibrary
{
    private const SYSTEMS = [
        'nds' => ['Nintendo DS', 'desmume'],
        'zip' => ['Nintendo DS', 'desmume'],
        'gba' => ['Game Boy Advance', 'mgba'],
        'gb' => ['Game Boy', 'gambatte'],
        'gbc' => ['Game Boy Color', 'gambatte'],
        'nes' => ['NES', 'fceumm'],
        'sfc' => ['Super Nintendo', 'snes9x'],
        'smc' => ['Super Nintendo', 'snes9x'],
        '3ds' => ['Nintendo 3DS', null],
        'nsp' => ['Nintendo Switch', null],
    ];

    public function __construct(private readonly string $directory) {}

    public function cores(): array
    {
        return array_map(static fn (array $system): ?string => $system[1], self::SYSTEMS);
    }

    public function list(): array
    {
        $games = [];
        foreach ($this->files() as $file) {
            $games[] = $this->describe($file->getFilename(), $file->getSize(), $file->getMTime());
        }
        usort($games, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $games;
    }

    public function file(string $id): string
    {
        foreach ($this->files() as $file) {
            if ($id === hash('sha256', $file->getFilename())) {
                return $file->getPathname();
            }
        }

        throw new NotFoundHttpException('Ce jeu ne se trouve plus dans le dossier roms/.');
    }

    private function files(): iterable
    {
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (new \FilesystemIterator($this->directory) as $file) {
            if ($file->isFile() && !$file->isLink() && $file->isReadable() && isset(self::SYSTEMS[$this->extension($file->getFilename())])) {
                yield $file;
            }
        }
    }

    public function describe(string $name, int $size, int $modified, ?string $id = null): array
    {
        [$system, $core] = self::SYSTEMS[$this->extension($name)];
        $id ??= hash('sha256', $name);

        return ['name' => $name, 'size' => $size, 'modified' => $modified, 'system' => $system, 'core' => $core, 'id' => $id];
    }

    private function extension(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
}
