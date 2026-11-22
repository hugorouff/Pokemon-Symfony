<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

#[AsCommand(name: 'app:roms:import', description: 'Importe des ROMs privées dans PostgreSQL.')]
final class ImportPostgresRoms
{
    public function __construct(private readonly PostgresRomLibrary $roms) {}

    public function __invoke(SymfonyStyle $io, #[Argument('Fichiers ROM à importer')] array $files): int
    {
        $status = Command::SUCCESS;
        foreach ($files as $file) {
            $name = OutputFormatter::escape(basename($file));
            try {
                $created = $this->roms->import($file);
                $io->success($name.($created ? ' importé.' : ' déjà présent.'));
            } catch (\PDOException $exception) {
                $io->error($name.' · '.$this->databaseError($exception));
                $status = Command::FAILURE;
            } catch (\InvalidArgumentException | ServiceUnavailableHttpException | \RuntimeException $exception) {
                $io->error($name.' · '.OutputFormatter::escape($exception->getMessage()));
                $status = Command::FAILURE;
            }
        }

        return $status;
    }

    private function databaseError(\PDOException $exception): string
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $reported = (string) ($exception->errorInfo[2] ?? '');
        $reason = match (true) {
            preg_match('/quota|storage limit|maximum.*(?:storage|size)|exceeded.*(?:storage|size)/i', $reported) === 1
                || $state === '53100' => 'Limite de stockage PostgreSQL atteinte ou espace insuffisant. Vérifiez le stockage du projet Neon.',
            $state === '42P01' => 'Table manquante. Relancez php bin/console app:neon:setup avec la même DATABASE_URL.',
            $state === '42501' => 'Le rôle PostgreSQL utilisé ne peut pas écrire dans les tables des ROMs.',
            $state === '25006' => 'La connexion PostgreSQL est en lecture seule. Utilisez le point de connexion principal de la branche.',
            $state === '25P02' => 'Transaction déjà en échec. Réessayez avec une connexion Neon directe, sans pooler.',
            $state === '57014' => 'PostgreSQL a annulé la requête, par exemple à cause d’un délai dépassé.',
            $state === '42P05' || $state === '26000' => 'Erreur de requête préparée. Pour l’import, essayez une connexion directe sans pooler.',
            str_starts_with($state, '08') || in_array($state, ['57P01', '57P03'], true)
                => 'La connexion PostgreSQL a été interrompue ou refusée.',
            default => 'PostgreSQL a refusé l’import.',
        };

        // Return only the error classification. Driver messages may expose connection details or SQL parameters.
        return $reason.(preg_match('/^[A-Z0-9]{5}$/D', $state) ? ' [SQLSTATE '.$state.']' : '');
    }
}
