<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:roms:password', description: 'Initialise ou renouvelle le mot de passe des ROMs privées.')]
final class ConfigureRomAccess extends Command
{
    public function __construct(private readonly RomAccess $access)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('rotate', null, InputOption::VALUE_NONE, 'Remplace le mot de passe et ferme les sessions existantes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $password = $this->access->initialize((bool) $input->getOption('rotate'));
        if ($password !== null) {
            $output->writeln([
                'Mot de passe de Mes ROMs (à conserver) : <info>'.$password.'</info>',
                'Il ne sera affiché qu’une fois.',
            ]);
        }

        return Command::SUCCESS;
    }
}
