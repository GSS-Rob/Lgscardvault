<?php

namespace App\Service\Recommend;

use App\Entity\Card;
use App\Entity\CardSynergy;
use App\Repository\CardRepository;
use App\Repository\CardSynergyRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rebuilds theme-source rows in card_synergies from the local Magic catalog.
 *
 * Pairs cards that share theme tags; weight = Jaccard overlap. Cap pairs per
 * card so the table stays bounded. Moxfield co-occurrence can write a
 * separate source later without touching these rows.
 */
final class SynergyIndexBuilder
{
    private const MAX_PARTNERS_PER_CARD = 40;
    private const MIN_SHARED_TAGS = 1;
    private const BATCH_FLUSH = 200;

    public function __construct(
        private readonly CardRepository $cards,
        private readonly CardSynergyRepository $synergies,
        private readonly ThemeTokenizer $tokenizer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{cards: int, edges: int, deleted: int}
     */
    public function rebuildThemeIndex(int $cardLimit = 8000): array
    {
        $deleted = $this->synergies->deleteBySource(CardSynergy::SOURCE_THEME);
        $this->entityManager->clear();

        /** @var list<Card> $catalog */
        $catalog = $this->cards->createQueryBuilder('c')
            ->leftJoin('c.game', 'g')
            ->andWhere('g.code = :mtg OR c.game IS NULL')
            ->setParameter('mtg', 'mtg')
            ->andWhere('c.oracleText IS NOT NULL OR c.keywords IS NOT NULL')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($cardLimit)
            ->getQuery()
            ->getResult();

        // oracle_id => tags; keep one representative card per oracle.
        $byOracle = [];
        foreach ($catalog as $card) {
            $oracle = (string) $card->getOracleId();
            if (isset($byOracle[$oracle])) {
                continue;
            }
            $tags = $this->tokenizer->tokenize($card);
            if (count($tags) < self::MIN_SHARED_TAGS) {
                continue;
            }
            $byOracle[$oracle] = [
                'uuid' => $card->getOracleId(),
                'tags' => $tags,
            ];
        }

        // Inverted index: tag => list of oracle ids
        $inverted = [];
        foreach ($byOracle as $oracle => $row) {
            foreach ($row['tags'] as $tag) {
                $inverted[$tag][] = $oracle;
            }
        }

        $partnerCounts = [];
        $pending = [];
        $edges = 0;

        foreach ($byOracle as $oracle => $row) {
            $scores = [];
            foreach ($row['tags'] as $tag) {
                foreach ($inverted[$tag] ?? [] as $other) {
                    if ($other <= $oracle) {
                        continue; // undirected; only emit once
                    }
                    $scores[$other] = ($scores[$other] ?? 0) + 1;
                }
            }

            arsort($scores);
            $emitted = 0;
            foreach ($scores as $other => $sharedCount) {
                if ($emitted >= self::MAX_PARTNERS_PER_CARD) {
                    break;
                }
                if (($partnerCounts[$oracle] ?? 0) >= self::MAX_PARTNERS_PER_CARD) {
                    break;
                }
                if (($partnerCounts[$other] ?? 0) >= self::MAX_PARTNERS_PER_CARD) {
                    continue;
                }

                $overlap = $this->tokenizer->overlap($row['tags'], $byOracle[$other]['tags']);
                if ($overlap['score'] < 0.08 || [] === $overlap['shared']) {
                    continue;
                }

                $edge = (new CardSynergy())
                    ->setOraclePair($row['uuid'], $byOracle[$other]['uuid'])
                    ->setWeight($overlap['score'])
                    ->setSource(CardSynergy::SOURCE_THEME)
                    ->setSharedTags($overlap['shared'])
                    ->touch();
                $this->entityManager->persist($edge);
                $pending[] = $edge;
                ++$edges;
                ++$emitted;
                $partnerCounts[$oracle] = ($partnerCounts[$oracle] ?? 0) + 1;
                $partnerCounts[$other] = ($partnerCounts[$other] ?? 0) + 1;

                if (0 === count($pending) % self::BATCH_FLUSH) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    $pending = [];
                }
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return [
            'cards' => count($byOracle),
            'edges' => $edges,
            'deleted' => $deleted,
        ];
    }
}
