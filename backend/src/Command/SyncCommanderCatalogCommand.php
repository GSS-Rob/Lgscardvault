<?php

namespace App\Command;

use App\Message\SyncCommanderCatalogMessage;
use App\Service\Recommend\CommanderCatalogSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:commanders:sync',
    description: 'Refresh the local commanders table from Scryfall is:commander',
)]
final class SyncCommanderCatalogCommand extends Command
{
    public function __construct(
        private readonly CommanderCatalogSynchronizer $synchronizer,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('async', null, InputOption::VALUE_NONE, 'Queue the sync on the async messenger worker')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Alias for --async');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('async') || $input->getOption('queue')) {
            $this->messageBus->dispatch(new SyncCommanderCatalogMessage());
            $io->success('Queued SyncCommanderCatalogMessage on the async transport.');
            $io->text('Ensure a worker is running: php bin/console messenger:consume async');

            return Command::SUCCESS;
        }

        $io->section('Syncing commanders from Scryfall (is:commander)');
        $result = $this->synchronizer->sync(static function (int $page, int $batchSize, int $upserted) use ($io): void {
            $io->text(sprintf('Page %d: %d cards (running upserted=%d)', $page, $batchSize, $upserted));
        });

        $io->success(sprintf(
            'Upserted %d commanders across %d pages; removed %d stale rows.',
            $result['upserted'],
            $result['pages'],
            $result['removed'],
        ));

        return Command::SUCCESS;
    }
}
