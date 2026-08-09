<?php

namespace App\Service\Recommend;

use App\Entity\Card;
use App\Entity\Store;
use App\Repository\CardSynergyRepository;
use App\Repository\InventoryItemRepository;
use App\Service\CaseCards\ColorIdentityParser;
use App\Service\CaseCards\SectionSerializer;

/**
 * Store-scoped commander deck builder recommendations.
 *
 * Pipeline:
 *  1. Detect strategies the commander supports
 *  2. Filter in-stock, color-legal inventory
 *  3. For a chosen strategy, classify cards into enabler / fuel / payoff
 *  4. Rank within roles and group by card type for a complete focused list
 */
final class CommanderRecommender
{
    private const DEFAULT_LIMIT = 80;
    private const MAX_LIMIT = 120;
    private const PER_ROLE_CAP = 36;
    private const PER_TYPE_CAP = 40;

    public function __construct(
        private readonly InventoryItemRepository $inventoryItems,
        private readonly CardSynergyRepository $synergies,
        private readonly ThemeTokenizer $tokenizer,
        private readonly ColorIdentityParser $colorIdentity,
        private readonly SectionSerializer $sectionSerializer,
        private readonly StrategyCatalog $strategies,
    ) {
    }

    /**
     * @return list<array{id: string, label: string, description: string, confidence: float, matchedSignals: list<string>}>
     */
    public function strategiesFor(Card $commander): array
    {
        return $this->strategies->strategiesForCommander($commander, $this->tokenizer);
    }

    /**
     * @return array<string, mixed>
     */
    public function recommendForStore(
        Store $store,
        Card $commander,
        ?string $strategyId = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $commanderTags = $this->tokenizer->tokenize($commander);
        $commanderOracle = $commander->getOracleId();
        $edgeWeights = $this->synergies->weightsForOracle($commanderOracle);
        $commanderCmc = $commander->getCmc() ?? 0.0;

        $supported = $this->strategiesFor($commander);
        $selectedId = $strategyId;
        if (null === $selectedId || '' === $selectedId) {
            $selectedId = $supported[0]['id'] ?? 'staples';
        }

        $strategy = $this->strategies->get($selectedId);
        if (null === $strategy) {
            throw new \InvalidArgumentException(sprintf('Unknown strategy "%s".', $selectedId));
        }

        // Reject strategies the commander does not support (except staples).
        $supportedIds = array_column($supported, 'id');
        if ('staples' !== $selectedId && !in_array($selectedId, $supportedIds, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Strategy "%s" is not supported by %s.',
                $selectedId,
                $commander->getName(),
            ));
        }

        $candidates = $this->inventoryItems->findInStockMagicForStore($store);
        $ranked = [];
        foreach ($candidates as $item) {
            $card = $item->getCard();
            if (!$card instanceof Card) {
                continue;
            }
            if ((string) $card->getOracleId() === (string) $commanderOracle) {
                continue;
            }
            if (!$this->colorIdentity->isSubsetOf($commander->getColorIdentity(), $card->getColorIdentity())) {
                continue;
            }
            if (!$this->isCommanderLegal($card)) {
                continue;
            }

            $classification = $this->strategies->classifyCard($card, $strategy, $this->tokenizer);
            $cardTags = $this->tokenizer->tokenize($card);
            $overlap = $this->tokenizer->overlap($commanderTags, $cardTags);
            $oracleKey = (string) $card->getOracleId();
            $edge = $edgeWeights[$oracleKey] ?? null;
            $edgeWeight = (float) ($edge['weight'] ?? 0.0);

            $roleBoost = match ($classification['primary']) {
                StrategyCatalog::ROLE_PAYOFF => 0.35,
                StrategyCatalog::ROLE_ENABLER => 0.30,
                StrategyCatalog::ROLE_FUEL => 0.25,
                default => 0.10,
            };
            $strategyMatch = [] === $classification['reasons'] ? 0.0 : 0.45;
            $curveFit = $this->curveFit($commanderCmc, $card->getCmc());
            $stockBoost = min(0.15, 0.03 * min(5, $item->getQuantity()));

            $score = (0.40 * $strategyMatch)
                + (0.25 * $roleBoost / 0.35)
                + (0.15 * $overlap['score'])
                + (0.10 * $edgeWeight)
                + (0.05 * $curveFit)
                + (0.05 * ($stockBoost / 0.15));

            // Strategy builds require a role/signal hit once inventory is rich.
            if (
                $score < 0.12
                && count($candidates) > 12
                && [] === $classification['reasons']
                && 0.0 === $overlap['score']
                && 0.0 === $edgeWeight
            ) {
                continue;
            }

            $cardType = $this->strategies->primaryCardType($card);
            $reasons = array_values(array_unique(array_merge(
                $classification['reasons'],
                $overlap['shared'],
                $edge['tags'] ?? [],
            )));

            $ranked[] = [
                'score' => round($score, 4),
                'role' => $classification['primary'],
                'roles' => $classification['roles'],
                'cardType' => $cardType,
                'reasons' => $reasons,
                'inventoryItem' => $this->sectionSerializer->serializeInventoryItem($item),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        // Deduplicate by oracle id.
        $seenOracle = [];
        $deduped = [];
        foreach ($ranked as $row) {
            $oracle = (string) ($row['inventoryItem']['card']['oracleId'] ?? '');
            if ('' !== $oracle && isset($seenOracle[$oracle])) {
                continue;
            }
            if ('' !== $oracle) {
                $seenOracle[$oracle] = true;
            }
            $deduped[] = $row;
            if (count($deduped) >= $limit) {
                break;
            }
        }

        return [
            'commander' => [
                'id' => (string) $commander->getId(),
                'oracleId' => (string) $commanderOracle,
                'name' => $commander->getName(),
                'typeLine' => $commander->getTypeLine(),
                'manaCost' => $commander->getManaCost(),
                'cmc' => $commander->getCmc(),
                'colorIdentity' => $commander->getColorIdentity() ?? [],
                'imageUrl' => $commander->getImageUrl(),
                'themes' => $commanderTags,
            ],
            'colorIdentity' => $commander->getColorIdentity() ?? [],
            'identityCode' => $this->colorIdentity->identityCode($commander->getColorIdentity()),
            'strategies' => $supported,
            'strategy' => [
                'id' => $strategy['id'],
                'label' => $strategy['label'],
                'description' => $strategy['description'],
            ],
            'totalCandidates' => count($ranked),
            'recommendations' => $deduped,
            'byRole' => $this->groupByRole($deduped),
            'byType' => $this->groupByType($deduped),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByRole(array $rows): array
    {
        $groups = [
            StrategyCatalog::ROLE_ENABLER => [],
            StrategyCatalog::ROLE_FUEL => [],
            StrategyCatalog::ROLE_PAYOFF => [],
            StrategyCatalog::ROLE_SUPPORT => [],
        ];
        foreach ($rows as $row) {
            $role = (string) ($row['role'] ?? StrategyCatalog::ROLE_SUPPORT);
            if (!isset($groups[$role])) {
                $role = StrategyCatalog::ROLE_SUPPORT;
            }
            if (count($groups[$role]) >= self::PER_ROLE_CAP) {
                continue;
            }
            $groups[$role][] = $row;
        }

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByType(array $rows): array
    {
        $groups = [];
        foreach (StrategyCatalog::CARD_TYPES as $type) {
            $groups[$type] = [];
        }
        foreach ($rows as $row) {
            $type = (string) ($row['cardType'] ?? 'other');
            if (!isset($groups[$type])) {
                $type = 'other';
            }
            if (count($groups[$type]) >= self::PER_TYPE_CAP) {
                continue;
            }
            $groups[$type][] = $row;
        }

        return $groups;
    }

    private function isCommanderLegal(Card $card): bool
    {
        $legalities = $card->getLegalities();
        if (!is_array($legalities) || !isset($legalities['commander'])) {
            return true;
        }

        return in_array((string) $legalities['commander'], ['legal', 'restricted'], true);
    }

    private function curveFit(float $commanderCmc, ?float $cardCmc): float
    {
        if (null === $cardCmc) {
            return 0.4;
        }
        $delta = abs($cardCmc - max(2.0, $commanderCmc * 0.6));

        return max(0.0, 1.0 - ($delta / 6.0));
    }
}
