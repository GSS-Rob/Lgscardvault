<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cheap guardrail: boots the kernel and asserts the DI container compiles and
 * a few critical services wire up. Catches config/wiring regressions in CI
 * before any behavioral test runs.
 */
final class SmokeTest extends KernelTestCase
{
    public function testKernelBootsAndContainerCompiles(): void
    {
        self::bootKernel();
        self::assertSame('test', self::$kernel->getEnvironment());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('criticalServices')]
    public function testCriticalServicesAreWired(string $id): void
    {
        self::bootKernel();
        self::assertTrue(self::getContainer()->has($id), sprintf('Service %s should be available', $id));
        self::assertNotNull(self::getContainer()->get($id));
    }

    /** @return iterable<string, array{string}> */
    public static function criticalServices(): iterable
    {
        yield 'card upserter' => [\App\Service\Scryfall\ScryfallCardUpserter::class];
        yield 'scryfall client' => [\App\Service\Scryfall\ScryfallClient::class];
        yield 'rate limiter' => [\App\Service\Scryfall\ScryfallRateLimiter::class];
        yield 'catalog resolver' => [\App\Service\Catalog\CatalogCardResolver::class];
        yield 'inventory writer' => [\App\Service\Inventory\StoreInventoryWriter::class];
        yield 'import handler' => [\App\MessageHandler\ProcessCsvImportMessageHandler::class];
        yield 'sync handler' => [\App\MessageHandler\SyncScryfallCatalogMessageHandler::class];
        yield 'commander sync handler' => [\App\MessageHandler\SyncCommanderCatalogMessageHandler::class];
        yield 'commander synchronizer' => [\App\Service\Recommend\CommanderCatalogSynchronizer::class];
    }
}
