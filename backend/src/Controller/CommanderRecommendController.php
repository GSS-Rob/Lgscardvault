<?php

namespace App\Controller;

use App\Entity\Card;
use App\Entity\Commander;
use App\Entity\Store;
use App\Repository\CardRepository;
use App\Repository\CommanderRepository;
use App\Repository\StoreRepository;
use App\Service\Recommend\CommanderRecommender;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Store-scoped commander deck builder.
 *
 * Commander typeahead reads the weekly-synced `commanders` table. After the
 * shopper picks a commander (and a strategy that commander supports),
 * recommendations return in-stock enabler / fuel / payoff packages grouped
 * by card type for a complete focused list.
 */
#[Route('/api/stores/{slug}/recommend')]
final class CommanderRecommendController extends AbstractController
{
    public function __construct(
        private readonly StoreRepository $stores,
        private readonly CardRepository $cards,
        private readonly CommanderRepository $commanders,
        private readonly CommanderRecommender $recommender,
    ) {
    }

    /**
     * Search every Scryfall-legal commander in the local catalog.
     * Independent of what the store currently stocks.
     */
    #[Route('/commanders', name: 'api_store_recommend_commanders', methods: ['GET'])]
    public function searchCommanders(string $slug, Request $request): JsonResponse
    {
        if (!$this->requireStore($slug) instanceof Store) {
            return $this->json(['detail' => 'Store not found.'], 404);
        }

        $q = trim((string) $request->query->get('q', ''));
        $limit = (int) $request->query->get('limit', 12);
        $results = $this->commanders->searchByName($q, $limit);

        return $this->json(array_map(
            fn (Commander $commander) => $this->serializeCommander($commander),
            $results,
        ));
    }

    /** Strategies this commander supports (for the strategy picker). */
    #[Route('/commander/{cardId}/strategies', name: 'api_store_recommend_commander_strategies', methods: ['GET'])]
    public function strategies(string $slug, string $cardId): JsonResponse
    {
        if (!$this->requireStore($slug) instanceof Store) {
            return $this->json(['detail' => 'Store not found.'], 404);
        }

        $commanderCard = $this->resolveCommanderCard($cardId);
        if ($commanderCard instanceof JsonResponse) {
            return $commanderCard;
        }

        return $this->json([
            'commander' => [
                'id' => (string) $commanderCard->getId(),
                'name' => $commanderCard->getName(),
            ],
            'strategies' => $this->recommender->strategiesFor($commanderCard),
        ]);
    }

    /**
     * Strategy-scoped in-stock deck package for one commander.
     * Query: ?strategy=proliferate&limit=80
     */
    #[Route('/commander/{cardId}', name: 'api_store_recommend_for_commander', methods: ['GET'])]
    public function recommend(string $slug, string $cardId, Request $request): JsonResponse
    {
        $store = $this->requireStore($slug);
        if (!$store instanceof Store) {
            return $this->json(['detail' => 'Store not found.'], 404);
        }

        $commanderCard = $this->resolveCommanderCard($cardId);
        if ($commanderCard instanceof JsonResponse) {
            return $commanderCard;
        }

        $strategy = trim((string) $request->query->get('strategy', ''));
        $limit = (int) $request->query->get('limit', 80);

        try {
            return $this->json($this->recommender->recommendForStore(
                $store,
                $commanderCard,
                '' === $strategy ? null : $strategy,
                $limit,
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['detail' => $e->getMessage()], 422);
        }
    }

    private function requireStore(string $slug): ?Store
    {
        return $this->stores->findOneBy(['slug' => $slug, 'isActive' => true]);
    }

    private function resolveCommanderCard(string $cardId): Card|JsonResponse
    {
        $commanderCard = $this->cards->findOneMagicById($cardId);
        if (!$commanderCard instanceof Card) {
            return $this->json(['detail' => 'Commander not found.'], 404);
        }

        $listed = $this->commanders->findOneByOracleId($commanderCard->getOracleId());
        if (!$listed instanceof Commander && !$this->looksLikeCommander($commanderCard)) {
            return $this->json(['detail' => 'That card is not a legal commander.'], 422);
        }

        return $commanderCard;
    }

    private function looksLikeCommander(Card $card): bool
    {
        $type = strtolower($card->getTypeLine() ?? '');

        return str_contains($type, 'legendary') && str_contains($type, 'creature');
    }

    /** @return array<string, mixed> */
    private function serializeCommander(Commander $commander): array
    {
        $card = $commander->getCard();

        return [
            'id' => (string) $card->getId(),
            'oracleId' => (string) $commander->getOracleId(),
            'name' => $commander->getName(),
            'typeLine' => $commander->getTypeLine(),
            'manaCost' => $commander->getManaCost(),
            'cmc' => $commander->getCmc(),
            'colorIdentity' => $commander->getColorIdentity() ?? [],
            'imageUrl' => $commander->getImageUri() ?? $card->getImageUrl(),
            'setCode' => $card->getSetCode(),
            'setName' => $card->getSetName(),
        ];
    }
}
