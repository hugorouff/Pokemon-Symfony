<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:neon:setup', description: 'Initialise les tables et vérifie l’accès au stockage privé.')]
final class NeonSetup
{
    public function __construct(private readonly Postgres $database, private readonly CloudRomStorage $cloud) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Origine HTTPS à autoriser sur le bucket (par exemple https://pokemon.example.com)')] string $origin = '',
    ): int {
        if ($origin !== '' && (!preg_match('~^https?://[a-z0-9.-]+(?::[0-9]+)?$~iD', $origin)
            || (!str_starts_with($origin, 'https://') && !in_array(parse_url($origin, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)))) {
            $io->error('Indiquez l’origine exacte du site, sans chemin ni barre oblique finale.');

            return Command::INVALID;
        }
        if ($origin !== '' && !$this->cloud->enabled()) {
            $io->error('Renseignez ROM_S3_BUCKET pour configurer CORS.');

            return Command::INVALID;
        }
        try {
            $this->database->connection()->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS booster_openings (
                    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                    locale varchar(2) NOT NULL,
                    set_filter text NOT NULL,
                    type_filter text NOT NULL,
                    cards jsonb NOT NULL CHECK (jsonb_typeof(cards) = 'array' AND jsonb_array_length(cards) = 5),
                    opened_at timestamptz NOT NULL DEFAULT now()
                );
                CREATE TABLE IF NOT EXISTS rom_saves (
                    rom_id varchar(64) NOT NULL,
                    id varchar(32) NOT NULL,
                    name text NOT NULL,
                    content bytea NOT NULL,
                    modified_at timestamptz NOT NULL DEFAULT now(),
                    PRIMARY KEY (rom_id, id)
                );
                ALTER TABLE rom_saves ADD COLUMN IF NOT EXISTS owner_id varchar(32) NOT NULL DEFAULT '';
                CREATE INDEX IF NOT EXISTS rom_saves_owner_rom ON rom_saves (owner_id, rom_id);
                CREATE TABLE IF NOT EXISTS rom_archives (
                    id varchar(64) PRIMARY KEY,
                    name text NOT NULL,
                    size integer NOT NULL CHECK (size > 0),
                    checksum varchar(64) NOT NULL,
                    modified bigint NOT NULL DEFAULT extract(epoch FROM now())::bigint
                );
                CREATE TABLE IF NOT EXISTS rom_archive_parts (
                    rom_id varchar(64) NOT NULL REFERENCES rom_archives(id) ON DELETE CASCADE,
                    part integer NOT NULL CHECK (part >= 0),
                    content bytea NOT NULL,
                    PRIMARY KEY (rom_id, part)
                );
                CREATE TABLE IF NOT EXISTS rom_accounts (
                    id varchar(32) PRIMARY KEY,
                    email varchar(254) NOT NULL UNIQUE,
                    password_hash text NOT NULL
                );
                SQL);
            $io->success('PostgreSQL accessible. Tables des ROMs, sauvegardes, comptes et boosters disponibles.');
            if ($this->cloud->enabled()) {
                $io->success(sprintf('Stockage S3 accessible. %d jeu(x) disponible(s).', count($this->cloud->list())));
                if ($origin !== '') {
                    $this->cloud->allowOrigin($origin);
                    $io->success('Accès du navigateur configuré pour '.$origin);
                }
            }
        } catch (\Throwable $exception) {
            $io->error($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $exception->getMessage()
                : 'Vérifiez les variables PostgreSQL et S3, les droits et la connexion réseau.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
