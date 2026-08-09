<?php

namespace App\Command;

use App\Service\Recommend\SynergyIndexBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:synergy:rebuild',
    description: 'Rebuild local theme synergy edges from the Magic catalog',
)]
final class RebuildSynergyIndexCommand extends Command
{
    public function __construct(
        private readonly SynergyIndexBuilder $builder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max Magic cards to index', '8000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(100, (int) $input->getOption('limit'));
        $io->section(sprintf('Rebuilding theme synergy index (limit=%d)', $limit));

        $result = $this->builder->rebuildThemeIndex($limit);
        $io->success(sprintf(
            'Indexed %d oracle identities → %d edges (removed %d old theme rows).',
            $result['cards'],
            $result['edges'],
            $result['deleted'],
        ));

        return Command::SUCCESS;
    }
}
