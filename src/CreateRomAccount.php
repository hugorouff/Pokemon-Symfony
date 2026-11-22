<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:roms:account', description: 'Crée un compte privé et génère son mot de passe.')]
final class CreateRomAccount
{
    public function __construct(private readonly RomAccounts $accounts) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Adresse mail du joueur')] string $email,
        #[Option('Renouvelle le mot de passe du compte')] bool $rotate = false,
    ): int {
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Adresse mail invalide.');

            return Command::INVALID;
        }

        try {
            $password = $this->accounts->create($email, $rotate);
        } catch (\Throwable) {
            $io->error('Impossible d’accéder aux comptes. Vérifiez DATABASE_URL et lancez app:neon:setup.');

            return Command::FAILURE;
        }

        if ($password === null) {
            $io->error('Ce compte existe déjà. Utilisez --rotate pour remplacer son mot de passe.');

            return Command::FAILURE;
        }

        $io->success('Mot de passe à conserver : '.$password);

        return Command::SUCCESS;
    }
}
