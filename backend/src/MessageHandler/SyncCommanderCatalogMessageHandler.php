<?php

namespace App\MessageHandler;

use App\Message\SyncCommanderCatalogMessage;
use App\Service\Recommend\CommanderCatalogSynchronizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncCommanderCatalogMessageHandler
{
    public function __construct(
        private CommanderCatalogSynchronizer $synchronizer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncCommanderCatalogMessage $message): void
    {
        $this->logger->info('Commander catalog sync started (Scryfall is:commander).');

        $result = $this->synchronizer->sync();

        $this->logger->info(
            'Commander catalog sync finished: {upserted} upserted, {removed} removed, {pages} pages.',
            $result,
        );
    }
}
