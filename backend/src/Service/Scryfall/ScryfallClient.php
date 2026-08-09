<?php

namespace App\Service\Scryfall;

use App\Entity\Card;
use App\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ScryfallClient
{
    private const BULK_DATA_URL = 'https://api.scryfall.com/bulk-data';
    private const SEARCH_URL = 'https://api.scryfall.com/cards/search';
    private const CARD_URL = 'https://api.scryfall.com/cards/';
    private const COLLECTION_URL = 'https://api.scryfall.com/cards/collection';

    /**
     * `oracle_cards` is one representative printing per Oracle ID (~35k rows,
     * ~170 MB) — enough for rules text and search, but NOT enough to resolve
     * store imports, which identify a specific printing by set + collector
     * number. `default_cards` is every printing (~450k rows) and is what lets
     * the local catalog answer imports without falling back to the API.
     */
    public const BULK_TYPE_ORACLE = 'oracle_cards';
    public const BULK_TYPE_DEFAULT = 'default_cards';
    public const BULK_TYPES = [self::BULK_TYPE_ORACLE, self::BULK_TYPE_DEFAULT];

    private const SYNC_BATCH_SIZE = 200;

    /**
     * Scryfall requires an identifying User-Agent AND an Accept header on
     * every API request (requests without them are rejected).
     */
    private const DEFAULT_HEADERS = [
        'User-Agent' => 'MTGStore/1.0',
        'Accept' => 'application/json;q=0.9,*/*;q=0.8',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly CardRepository $cardRepository,
        private readonly ScryfallCardUpserter $cardUpserter,
        private readonly ScryfallRateLimiter $rateLimiter,
        private readonly ScryfallBulkFileReader $bulkFileReader,
    ) {
    }

    /** @return array{download_uri: string, jsonl_download_uri: ?string, updated_at: string} */
    public function getBulkInfo(string $type): array
    {
        if (!in_array($type, self::BULK_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown Scryfall bulk type "%s".', $type));
        }

        $response = $this->httpClient->request('GET', self::BULK_DATA_URL, [
            'headers' => self::DEFAULT_HEADERS,
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('Scryfall bulk-data request failed with status %d.', $response->getStatusCode()));
        }

        $payload = $response->toArray();
        $items = $payload['data'] ?? null;
        if (!is_array($items)) {
            throw new \RuntimeException('Scryfall bulk-data response is missing a "data" array.');
        }

        foreach ($items as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== $type) {
                continue;
            }

            $downloadUri = $item['download_uri'] ?? null;
            $updatedAt = $item['updated_at'] ?? null;
            if (!is_string($downloadUri) || '' === $downloadUri || !is_string($updatedAt) || '' === $updatedAt) {
                throw new \RuntimeException(sprintf('%s bulk data entry is missing download_uri or updated_at.', $type));
            }

            $jsonlDownloadUri = $item['jsonl_download_uri'] ?? null;

            return [
                'download_uri' => $downloadUri,
                'jsonl_download_uri' => is_string($jsonlDownloadUri) && '' !== $jsonlDownloadUri ? $jsonlDownloadUri : null,
                'updated_at' => $updatedAt,
            ];
        }

        throw new \RuntimeException(sprintf('%s bulk data not found.', $type));
    }

    /**
     * Sync a Scryfall bulk dataset into the local catalog.
     *
     * The bulk file is streamed to a temp file, then parsed incrementally
     * (ScryfallBulkFileReader) and written in multi-row ON CONFLICT batches —
     * neither the raw body, the decoded card list, nor ORM entities are ever
     * held in memory, so the multi-hundred-MB `default_cards` file syncs with
     * flat memory usage.
     *
     * Prefers the gzipped-JSONL download (`jsonl_download_uri`) — the only
     * format Scryfall serves after 2026-07-20 — and falls back to the legacy
     * `download_uri`; the reader handles both formats either way.
     *
     * @param callable(int, int): void|null $onProgress receives (processed, changed);
     *                                                  the dataset size is unknown while streaming
     *
     * @return array{inserted: int, updated: int, total: int}
     */
    public function syncBulkCards(?callable $onProgress = null, string $type = self::BULK_TYPE_DEFAULT): array
    {
        $info = $this->getBulkInfo($type);
        $response = $this->httpClient->request('GET', $info['jsonl_download_uri'] ?? $info['download_uri'], [
            'headers' => ['User-Agent' => 'MTGStore/1.0', 'Accept' => '*/*'],
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('Scryfall bulk download failed with status %d.', $response->getStatusCode()));
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'scryfall_bulk_');
        if (false === $tmpPath) {
            throw new \RuntimeException('Unable to allocate a temp file for the Scryfall bulk download.');
        }

        $handle = fopen($tmpPath, 'wb');
        if (false === $handle) {
            @unlink($tmpPath);
            throw new \RuntimeException('Unable to open the temp file for the Scryfall bulk download.');
        }

        $inserted = 0;
        $updated = 0;
        $processed = 0;

        try {
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content = $chunk->getContent();
                if ('' !== $content) {
                    fwrite($handle, $content);
                }
                unset($content);
            }
            fclose($handle);
            $handle = null;
            unset($response);

            // Iterate the file one card at a time (JSONL line or legacy array
            // element); only one decoded card (plus the current batch) is
            // ever resident.
            $batch = [];
            foreach ($this->bulkFileReader->cards($tmpPath) as $cardData) {
                $batch[] = $cardData;
                if (count($batch) < self::SYNC_BATCH_SIZE) {
                    continue;
                }

                $result = $this->cardUpserter->upsertMany($batch);
                $inserted += $result['inserted'];
                $updated += $result['updated'];
                $processed += count($batch);
                $batch = [];

                if (null !== $onProgress) {
                    $onProgress($processed, $inserted + $updated);
                }
            }

            if ([] !== $batch) {
                $result = $this->cardUpserter->upsertMany($batch);
                $inserted += $result['inserted'];
                $updated += $result['updated'];
                $processed += count($batch);

                if (null !== $onProgress) {
                    $onProgress($processed, $inserted + $updated);
                }
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($tmpPath);
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'total' => $processed];
    }

    /** @return list<Card> */
    public function searchRemoteAndUpsert(string $query, int $limit = 20, ?string $setCode = null, ?string $finish = null): array
    {
        $scryfallQuery = $query;
        if (null !== $setCode && '' !== $setCode) {
            $scryfallQuery .= ' set:'.strtolower($setCode);
        }
        if ('foil' === $finish) {
            $scryfallQuery .= ' is:foil';
        } elseif ('nonfoil' === $finish) {
            $scryfallQuery .= ' is:nonfoil';
        }

        $response = $this->requestWithRateLimit('GET', self::SEARCH_URL, [
            'headers' => self::DEFAULT_HEADERS,
            'query' => ['q' => $scryfallQuery, 'unique' => 'prints'],
        ]);

        $status = $response->getStatusCode();
        // Scryfall returns 404 when a search yields no results — treat as empty.
        if (404 === $status) {
            return [];
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Scryfall search request failed with status %d.', $status));
        }

        // Use toArray(false) so a non-throwing decode lets us guard the shape ourselves.
        $payload = $response->toArray(false);
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }

        $batch = [];
        foreach (array_slice($data, 0, $limit) as $cardData) {
            if (is_array($cardData) && isset($cardData['id'])) {
                $batch[] = $cardData;
            }
        }

        if ([] === $batch) {
            return [];
        }

        $this->cardUpserter->upsertMany($batch);

        $cards = [];
        foreach ($batch as $cardData) {
            $card = $this->loadFreshCard(Uuid::fromString((string) $cardData['id']));
            if ($card instanceof Card) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * Paginate a Scryfall search end-to-end, yielding raw card payloads.
     *
     * Used by the weekly commander catalog sync (`is:commander`) so the full
     * legal-commander list lands locally without per-keystroke API calls.
     * Callers should upsert each page themselves (and respect rate limits —
     * this method already waits between pages via requestWithRateLimit).
     *
     * @return \Generator<int, list<array<string, mixed>>>
     */
    public function iterateSearchPages(string $query, string $unique = 'cards'): \Generator
    {
        $url = self::SEARCH_URL;
        $options = [
            'headers' => self::DEFAULT_HEADERS,
            'query' => ['q' => $query, 'unique' => $unique],
        ];

        while (true) {
            $response = $this->requestWithRateLimit('GET', $url, $options);
            $status = $response->getStatusCode();
            if (404 === $status) {
                return;
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf('Scryfall search request failed with status %d.', $status));
            }

            $payload = $response->toArray(false);
            $data = $payload['data'] ?? null;
            if (!is_array($data) || [] === $data) {
                return;
            }

            $batch = [];
            foreach ($data as $cardData) {
                if (is_array($cardData) && isset($cardData['id'], $cardData['oracle_id'])) {
                    $batch[] = $cardData;
                }
            }

            if ([] !== $batch) {
                yield $batch;
            }

            if (empty($payload['has_more']) || empty($payload['next_page']) || !is_string($payload['next_page'])) {
                return;
            }

            // next_page is a fully-qualified URL — do not re-apply query params.
            $url = $payload['next_page'];
            $options = ['headers' => self::DEFAULT_HEADERS];
        }
    }

    /**
     * @param list<array{set: string, collectorNumber: string}> $identifiers
     *
     * @return array<string, Card> keyed by "set|collectorNumber" (see collectionKey())
     */
    public function fetchCollectionBySetCollectors(array $identifiers): array
    {
        $unique = [];
        foreach ($identifiers as $identifier) {
            $set = strtolower(trim($identifier['set']));
            $collectorNumber = trim($identifier['collectorNumber']);
            if ('' === $set || '' === $collectorNumber) {
                continue;
            }

            $unique[self::collectionKey($set, $collectorNumber)] = [
                'set' => $set,
                'collector_number' => $collectorNumber,
            ];
        }

        $matchedIds = [];
        foreach (array_chunk($unique, 75, true) as $chunk) {
            $response = $this->requestWithRateLimit('POST', self::COLLECTION_URL, [
                'headers' => self::DEFAULT_HEADERS,
                'json' => ['identifiers' => array_values($chunk)],
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                continue;
            }

            $payload = $response->toArray(false);
            $data = $payload['data'] ?? null;
            if (!is_array($data)) {
                continue;
            }

            $batch = [];
            foreach ($data as $cardData) {
                if (is_array($cardData) && isset($cardData['id'])) {
                    $batch[] = $cardData;
                }
            }

            if ([] === $batch) {
                continue;
            }

            $this->cardUpserter->upsertMany($batch);

            foreach ($batch as $cardData) {
                $key = self::collectionKey(
                    (string) ($cardData['set'] ?? ''),
                    (string) ($cardData['collector_number'] ?? ''),
                );
                $matchedIds[$key] = Uuid::fromString((string) $cardData['id']);
            }
        }

        $matches = [];
        foreach ($matchedIds as $key => $id) {
            $card = $this->loadFreshCard($id);
            if ($card instanceof Card) {
                $matches[$key] = $card;
            }
        }

        return $matches;
    }

    /**
     * Fetch the full card payload for a single card from Scryfall by its id and
     * persist every field (plus the complete raw payload) locally.
     *
     * Returns null when the card cannot be found on Scryfall.
     */
    public function fetchCardById(Uuid $id): ?Card
    {
        $response = $this->requestWithRateLimit('GET', self::CARD_URL.$id->toRfc4122(), [
            'headers' => self::DEFAULT_HEADERS,
        ]);

        if (200 !== $response->getStatusCode()) {
            return null;
        }

        $data = $response->toArray(false);
        if (($data['object'] ?? null) === 'error') {
            return null;
        }

        $this->cardUpserter->upsertOne($data);

        return $this->loadFreshCard($id);
    }

    /**
     * Canonical map key for collection lookups: lowercased "set|collectorNumber".
     * Shared with the CSV import handler so both sides build identical keys.
     */
    public static function collectionKey(string $set, string $collectorNumber): string
    {
        return strtolower(trim($set)).'|'.strtolower(trim($collectorNumber));
    }

    /**
     * Loads a card after a native upsert. The upsert bypasses the ORM, so a
     * previously-managed instance in the identity map would be stale —
     * refresh() re-reads the committed row into the managed entity.
     */
    private function loadFreshCard(Uuid $id): ?Card
    {
        $card = $this->cardRepository->find($id);
        if (!$card instanceof Card) {
            return null;
        }

        $this->entityManager->refresh($card);

        return $card;
    }

    /** @param array<string, mixed> $options */
    private function requestWithRateLimit(string $method, string $url, array $options): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $attempt = 0;
        $maxAttempts = 6;

        while (true) {
            ++$attempt;
            $this->rateLimiter->acquire();
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();

            if (429 !== $status) {
                return $response;
            }

            if ($attempt >= $maxAttempts) {
                return $response;
            }

            $headers = $response->getHeaders(false);
            $retryAfter = isset($headers['retry-after'][0]) ? (float) $headers['retry-after'][0] : 0.0;
            // Scryfall often omits Retry-After on burst limits — back off hard.
            $sleepSeconds = max($retryAfter, min(60.0, 1.5 ** $attempt));
            usleep((int) ceil($sleepSeconds * 1_000_000));
        }
    }
}
