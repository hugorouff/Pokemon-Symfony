<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:catalogue:actualiser', description: 'Actualise les index TCGdex locaux.')]
final class RefreshCatalog extends Command
{
    public function __construct(private readonly Catalog $catalog)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('langue', InputArgument::OPTIONAL, 'fr, en ou all', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $language = $input->getArgument('langue');
        if (!in_array($language, ['fr', 'en', 'all'], true)) {
            $output->writeln('Langues possibles : fr, en ou all.');

            return Command::INVALID;
        }

        $code = Command::SUCCESS;
        foreach ($language === 'all' ? ['fr', 'en'] : [$language] as $lang) {
            try {
                [$cards, $sets] = $this->catalog->refresh($lang);
                $output->writeln(sprintf('%s : %d cartes, %d séries.', strtoupper($lang), $cards, $sets));
            } catch (\Exception $e) {
                $output->writeln(sprintf('%s : actualisation impossible. %s', strtoupper($lang), $e->getMessage()));
                $code = Command::FAILURE;
            }
        }

        return $code;
    }
}
