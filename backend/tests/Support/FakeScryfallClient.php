<?php

namespace App\Tests\Support;

use App\Entity\Card;
use App\Service\Scryfall\ScryfallClient;
use Symfony\Component\Uid\Uuid;

/**
 * Network-free ScryfallClient for tests. Overrides every method that would
 * reach the Scryfall API so the suite is deterministic and offline — tests
 * exercise the local catalog and the resolution logic, never the live API.
 *
 * Returns are configurable so a test can simulate "the remote catalog has this
 * card" when it needs to; by default everything is empty.
 */
final class FakeScryfallClient extends ScryfallClient
{
    /** @var list<Card> */
    public array $searchReturns = [];

    /** @var array<string, Card> keyed by "set|collectorNumber" */
    public array $collectionReturns = [];

    public function searchRemoteAndUpsert(string $query, int $limit = 20, ?string $setCode = null, ?string $finish = null): array
    {
        return $this->searchReturns;
    }

    public function fetchCollectionBySetCollectors(array $identifiers): array
    {
        return $this->collectionReturns;
    }

    public function fetchCardById(Uuid $id): ?Card
    {
        return null;
    }

    public function syncBulkCards(?callable $onProgress = null, string $type = self::BULK_TYPE_DEFAULT): array
    {
        return ['inserted' => 0, 'updated' => 0, 'total' => 0];
    }

    /**
     * @var list<list<array<string, mixed>>> pages of raw Scryfall card payloads
     */
    public array $searchPages = [];

    public function iterateSearchPages(string $query, string $unique = 'cards'): \Generator
    {
        foreach ($this->searchPages as $page) {
            yield $page;
        }
    }
}
