<?php

namespace App\Tests\Service;

use App\Entity\Commander;
use App\Repository\CommanderRepository;
use App\Service\Recommend\CommanderCatalogSynchronizer;
use App\Service\Scryfall\ScryfallCardUpserter;
use App\Tests\Support\CatalogFixtures;
use App\Tests\Support\FakeScryfallClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommanderCatalogSynchronizerTest extends KernelTestCase
{
    public function testSyncUpsertsCommandersAndRemovesStaleRows(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $fixtures = new CatalogFixtures($em);

        $keep = $fixtures->card(800, [
            'name' => 'Keep Commander',
            'type_line' => 'Legendary Creature — Dragon',
            'color_identity' => ['R'],
            'oracle_text' => 'Flying',
        ]);
        $stale = $fixtures->card(801, [
            'name' => 'Stale Commander',
            'type_line' => 'Legendary Creature — Elf',
            'color_identity' => ['G'],
        ]);
        $staleRow = new Commander($stale->getOracleId(), $stale);
        $staleRow->syncFromCard($stale);
        $em->persist($staleRow);
        $em->flush();

        $fake = $container->get(FakeScryfallClient::class);
        assert($fake instanceof FakeScryfallClient);
        $fake->searchPages = [[
            CatalogFixtures::scryfallPayload(800, [
                'name' => 'Keep Commander',
                'type_line' => 'Legendary Creature — Dragon',
                'color_identity' => ['R'],
                'oracle_text' => 'Flying',
                'mana_cost' => '{3}{R}{R}',
                'cmc' => 5,
                'image_uris' => ['normal' => 'https://example.test/keep.jpg'],
            ]),
        ]];

        // Upserter writes native SQL from the fake page payload.
        $sync = new CommanderCatalogSynchronizer(
            $fake,
            $container->get(ScryfallCardUpserter::class),
            $em->getRepository(\App\Entity\Card::class),
            $container->get(CommanderRepository::class),
            $em,
        );

        $result = $sync->sync();
        self::assertSame(1, $result['pages']);
        self::assertGreaterThanOrEqual(1, $result['upserted']);
        self::assertGreaterThanOrEqual(1, $result['removed']);

        $repo = $container->get(CommanderRepository::class);
        assert($repo instanceof CommanderRepository);
        self::assertInstanceOf(Commander::class, $repo->find($keep->getOracleId()));
        self::assertNull($repo->find($stale->getOracleId()));

        $found = $repo->searchByName('Keep', 10);
        self::assertCount(1, $found);
        self::assertSame('Keep Commander', $found[0]->getName());
    }
}
