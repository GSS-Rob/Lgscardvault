<?php

namespace App\Repository;

use App\Entity\InventoryItem;
use App\Entity\Game;
use App\Entity\Store;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InventoryItem>
 */
class InventoryItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryItem::class);
    }

    /**
     * One page of a store's inventory, card eagerly joined. Ordered by card
     * name with the item id as a stable tiebreaker so LIMIT/OFFSET pages
     * never overlap or skip rows when many printings share a name.
     *
     * @return list<InventoryItem>
     */
    public function findPageByStore(Store $store, int $offset, int $limit, ?string $gameCode = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.store = :store')
            ->setParameter('store', $store)
            ->join('i.card', 'c')
            ->addSelect('c')
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if (null !== $gameCode && '' !== $gameCode) {
            $this->scopeToGame($qb, $gameCode);
        }

        return $qb->getQuery()->getResult();
    }

    public function countByStore(Store $store, ?string $gameCode = null): int
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.store = :store')
            ->setParameter('store', $store);

        if (null !== $gameCode && '' !== $gameCode) {
            $qb->join('i.card', 'c');
            $this->scopeToGame($qb, $gameCode);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Headline numbers for one game's singles in a store: how many distinct
     * listings, and how many physical copies they represent.
     *
     * @return array{listings: int, copies: int}
     */
    public function statsForGame(Store $store, string $gameCode): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id) AS listings', 'COALESCE(SUM(i.quantity), 0) AS copies')
            ->join('i.card', 'c')
            ->andWhere('i.store = :store')
            ->setParameter('store', $store);

        $this->scopeToGame($qb, $gameCode);

        $row = $qb->getQuery()->getSingleResult();

        return ['listings' => (int) $row['listings'], 'copies' => (int) $row['copies']];
    }

    /**
     * Game codes a store actually stocks, so the storefront only offers game
     * tabs that lead somewhere. Legacy NULL-game listings report as Magic.
     *
     * @return list<string>
     */
    public function findStockedGameCodes(Store $store): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('COALESCE(g.code, :mtg) AS code')
            ->distinct()
            ->join('i.card', 'c')
            ->leftJoin('c.game', 'g')
            ->andWhere('i.store = :store')
            ->andWhere('i.quantity > 0')
            ->setParameter('store', $store)
            ->setParameter('mtg', Game::CODE_MTG)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_filter(array_map(static fn (array $row): string => (string) $row['code'], $rows)));
    }

    /**
     * Keyset (cursor) page: items with id > $afterId, ascending. Backed by
     * idx_inventory_store_id_id, each page is an O(page-size) index range
     * scan — unlike OFFSET pages, walking the whole inventory is linear, and
     * because the cursor is an immutable id, concurrent inserts/deletes can
     * never shift rows into (duplicate) or out of (skip) later pages.
     *
     * @return list<InventoryItem>
     */
    public function findByStoreAfterId(Store $store, int $afterId, int $limit, ?string $gameCode = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.store = :store')
            ->andWhere('i.id > :afterId')
            ->setParameter('store', $store)
            ->setParameter('afterId', $afterId)
            ->join('i.card', 'c')
            ->addSelect('c')
            ->orderBy('i.id', 'ASC')
            ->setMaxResults($limit);

        if (null !== $gameCode && '' !== $gameCode) {
            $this->scopeToGame($qb, $gameCode);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Restricts a listing query to one game.
     *
     * Cards predating the multi-game catalog have no game row and are Magic
     * by definition, so a Magic filter must include those NULLs — otherwise
     * every legacy listing disappears the moment a store picks "Magic".
     */
    private function scopeToGame(QueryBuilder $qb, string $gameCode): void
    {
        $code = strtolower(trim($gameCode));
        $qb->leftJoin('c.game', 'g');

        if (Game::CODE_MTG === $code) {
            $qb->andWhere('g.code = :gameCode OR c.game IS NULL');
        } else {
            $qb->andWhere('g.code = :gameCode');
        }

        $qb->setParameter('gameCode', $code);
    }


    /**
     * One page of candidate listings for a case section's auto-fill — in
     * stock, matching the SQL-expressible criteria (price range, rarity, set,
     * card type), highest price first. Color identity lives in a JSON column,
     * so SectionAutoFiller filters it in PHP over these batches (along with
     * cross-section allocation accounting) until it has enough matches.
     *
     * @return list<InventoryItem>
     */
    public function findAutoSectionCandidates(
        Store $store,
        ?int $minPriceCents,
        ?int $maxPriceCents,
        ?string $rarity,
        ?string $setCode,
        ?string $cardType,
        int $offset,
        int $limit,
    ): array {
        $qb = $this->createQueryBuilder('i')
            ->join('i.card', 'c')
            ->addSelect('c')
            ->andWhere('i.store = :store')
            ->andWhere('i.quantity > 0')
            ->setParameter('store', $store)
            ->orderBy('i.priceCents', 'DESC')
            ->addOrderBy('i.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        // Display-case auto-fill speaks Magic (rarity tiers, color identity,
        // Magic type lines), so only Magic listings may be auto-placed — a
        // One Piece card must never be swept into a Magic case section.
        $this->scopeToGame($qb, Game::CODE_MTG);

        if (null !== $minPriceCents) {
            $qb->andWhere('i.priceCents >= :minPrice')->setParameter('minPrice', $minPriceCents);
        }
        if (null !== $maxPriceCents) {
            $qb->andWhere('i.priceCents <= :maxPrice')->setParameter('maxPrice', $maxPriceCents);
        }
        if (null !== $rarity && '' !== $rarity) {
            $qb->andWhere('LOWER(c.rarity) = :rarity')->setParameter('rarity', strtolower($rarity));
        }
        if (null !== $setCode && '' !== $setCode) {
            $qb->andWhere('LOWER(c.setCode) = :setCode')->setParameter('setCode', strtolower($setCode));
        }
        if (null !== $cardType && '' !== $cardType) {
            $qb->andWhere('LOWER(c.typeLine) LIKE :cardType')->setParameter('cardType', '%'.strtolower($cardType).'%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Listings from the pre-multi-game era: a "Game: X" note (written by the
     * old import recovery path) on an item whose card has no game row. These
     * are the rows the LegacyGameLinkRepairer re-homes.
     *
     * @return list<InventoryItem>
     */
    public function findLegacyGameNoted(): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.card', 'c')
            ->addSelect('c')
            ->where('c.game IS NULL')
            ->andWhere('LOWER(i.notes) LIKE :marker')
            ->setParameter('marker', '%game:%')
            ->getQuery()
            ->getResult();
    }

    public function findOneByStoreAndId(Store $store, int $id): ?InventoryItem
    {
        return $this->createQueryBuilder('i')
            ->join('i.card', 'c')
            ->addSelect('c')
            ->andWhere('i.store = :store')
            ->andWhere('i.id = :id')
            ->setParameter('store', $store)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * In-stock Magic singles for a store — candidate pool for commander
     * recommendations. Card is eagerly joined; capped so a huge inventory
     * cannot blow the recommend request.
     *
     * @return list<InventoryItem>
     */
    public function findInStockMagicForStore(Store $store, int $limit = 2500): array
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.card', 'c')
            ->addSelect('c')
            ->andWhere('i.store = :store')
            ->andWhere('i.quantity > 0')
            ->setParameter('store', $store)
            ->orderBy('i.priceCents', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->setMaxResults($limit);

        $this->scopeToGame($qb, Game::CODE_MTG);

        return $qb->getQuery()->getResult();
    }
}
