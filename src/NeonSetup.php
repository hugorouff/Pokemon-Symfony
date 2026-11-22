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
        #[Option('Met à jour uniquement le schéma PostgreSQL')] bool $schemaOnly = false,
    ): int {
        if ($schemaOnly && $origin !== '') {
            $io->error('Les options --schema-only et --origin ne sont pas compatibles.');

            return Command::INVALID;
        }
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
                SELECT pg_advisory_xact_lock(176041, 1);
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
                ALTER TABLE booster_openings ADD COLUMN IF NOT EXISTS owner_id varchar(32);
                CREATE INDEX IF NOT EXISTS booster_openings_owner_date ON booster_openings (owner_id, type_filter, opened_at);
                CREATE TABLE IF NOT EXISTS account_password_resets (
                    owner_id varchar(32) PRIMARY KEY REFERENCES rom_accounts(id) ON DELETE CASCADE,
                    token_hash varchar(64) NOT NULL,
                    expires_at timestamptz NOT NULL,
                    requested_at timestamptz NOT NULL DEFAULT now()
                );
                CREATE TABLE IF NOT EXISTS account_cards (
                    owner_id varchar(32) NOT NULL REFERENCES rom_accounts(id) ON DELETE CASCADE,
                    id varchar(103) NOT NULL,
                    card jsonb NOT NULL,
                    version bigint NOT NULL DEFAULT 1,
                    PRIMARY KEY (owner_id, id)
                );
                ALTER TABLE rom_saves ADD COLUMN IF NOT EXISTS checkpoint_core varchar(20);
                CREATE TABLE IF NOT EXISTS app_sessions (
                    sess_id varchar(128) PRIMARY KEY,
                    sess_data bytea NOT NULL,
                    sess_lifetime integer NOT NULL,
                    sess_time integer NOT NULL
                );
                CREATE INDEX IF NOT EXISTS app_sessions_expiry_idx ON app_sessions (sess_lifetime);
                CREATE TABLE IF NOT EXISTS player_features (
                    owner_id varchar(32) NOT NULL REFERENCES rom_accounts(id) ON DELETE CASCADE,
                    kind varchar(72) NOT NULL,
                    payload jsonb NOT NULL,
                    version bigint NOT NULL DEFAULT 1,
                    public_token varchar(32) UNIQUE,
                    PRIMARY KEY (owner_id, kind)
                );
                CREATE TABLE IF NOT EXISTS card_trades (
                    id varchar(32) PRIMARY KEY,
                    proposer varchar(32) NOT NULL REFERENCES rom_accounts(id) ON DELETE CASCADE,
                    recipient varchar(32) NOT NULL REFERENCES rom_accounts(id) ON DELETE CASCADE,
                    give_card varchar(103) NOT NULL,
                    take_card varchar(103) NOT NULL,
                    status varchar(10) NOT NULL DEFAULT 'pending',
                    created_at timestamptz NOT NULL DEFAULT now(),
                    decided_at timestamptz,
                    CHECK (proposer <> recipient AND give_card <> take_card),
                    CHECK (status IN ('pending', 'accepted', 'rejected', 'cancelled'))
                );
                CREATE INDEX IF NOT EXISTS card_trades_proposer_idx ON card_trades (proposer, created_at);
                CREATE INDEX IF NOT EXISTS card_trades_recipient_idx ON card_trades (recipient, created_at);
                CREATE UNIQUE INDEX IF NOT EXISTS card_trades_pending_idx ON card_trades (proposer, recipient, give_card, take_card) WHERE status = 'pending';
                SQL);
            $io->success('PostgreSQL accessible. Tables des ROMs, sauvegardes, comptes, collections et fonctionnalités joueur disponibles.');
            if (!$schemaOnly && $this->cloud->enabled()) {
                $io->success(sprintf('Stockage S3 accessible. %d jeu(x) disponible(s).', count($this->cloud->list())));
                if ($origin !== '') {
                    $this->cloud->allowOrigin($origin);
                    $io->success('Accès du navigateur configuré pour '.$origin);
                }
            }
        } catch (\PDOException $exception) {
            $state = $exception->errorInfo[0] ?? (string) $exception->getCode();
            $hint = match ($state) {
                '42501' => 'Le rôle PostgreSQL doit pouvoir créer les tables et modifier celles de l’application.',
                default => 'Vérifiez la connexion PostgreSQL et les droits du rôle.',
            };
            $io->error(sprintf('Mise à jour du schéma PostgreSQL impossible (SQLSTATE %s). %s', $state, $hint));

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $io->error($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $exception->getMessage()
                : 'Vérifiez les variables PostgreSQL et S3, les droits et la connexion réseau.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
