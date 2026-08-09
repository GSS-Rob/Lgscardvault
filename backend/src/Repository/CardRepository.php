<?php

namespace App\Repository;

use App\Entity\Card;
use App\Entity\Game;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Card>
 */
class CardRepository extends ServiceEntityRepository
{
    /** How many same-word candidates the normalized-name fallback inspects. */
    private const NORMALIZED_MATCH_CANDIDATES = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    /**
     * Substring name search. Backed by the trigram GIN index on LOWER(name)
     * (see migration Version20260718090000) so the leading-% LIKE no longer
     * forces a sequential scan of the whole catalog.
     *
     * @return list<Card>
     */
    public function searchByName(string $query, int $limit = 20): array
    {
        return $this->magicScoped()
            ->andWhere('LOWER(c.name) LIKE :query')
            ->setParameter('query', '%'.strtolower($query).'%')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Legendary creatures suitable as commanders. Dedupes by oracle id so
     * multiple printings of the same commander collapse to one result.
     *
     * @return list<Card>
     */
    public function searchCommanders(string $query, int $limit = 20): array
    {
        $limit = max(1, min(40, $limit));
        $qb = $this->magicScoped()
            ->andWhere('LOWER(c.typeLine) LIKE :legendary')
            ->andWhere('LOWER(c.typeLine) LIKE :creature')
            ->setParameter('legendary', '%legendary%')
            ->setParameter('creature', '%creature%')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit * 3);

        $q = strtolower(trim($query));
        if ('' !== $q) {
            $qb->andWhere('LOWER(c.name) LIKE :query')
                ->setParameter('query', '%'.$q.'%');
        }

        /** @var list<Card> $rows */
        $rows = $qb->getQuery()->getResult();
        $seen = [];
        $out = [];
        foreach ($rows as $card) {
            $oracle = (string) $card->getOracleId();
            if (isset($seen[$oracle])) {
                continue;
            }
            $seen[$oracle] = true;
            $out[] = $card;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function findOneMagicById(string $id): ?Card
    {
        try {
            $uuid = \Symfony\Component\Uid\Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $card = $this->find($uuid);
        if (!$card instanceof Card) {
            return null;
        }

        // Reject non-Magic printings even if the UUID happens to collide.
        $code = $card->resolvedGameCode();

        return Game::CODE_MTG === $code ? $card : null;
    }

    /**
     * Name search within one game. Non-Magic catalogs are local-only
     * (TCGCSV), so this is the whole search for them — there is no remote
     * leg to fall back to.
     *
     * @return list<Card>
     */
    public function searchByNameForGame(Game $game, string $query, int $limit = 40): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('LOWER(c.name) LIKE :query')
            ->setParameter('query', '%'.mb_strtolower(trim($query)).'%')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit);

        // Cards predating the multi-game catalog carry no game and are Magic.
        if ($game->isMtg()) {
            $qb->leftJoin('c.game', 'g')
                ->andWhere('g.code = :code OR c.game IS NULL')
                ->setParameter('code', Game::CODE_MTG);
        } else {
            $qb->join('c.game', 'g')
                ->andWhere('g.code = :code')
                ->setParameter('code', $game->getCode());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Exact-name lookup for decklist lines: full name or the front face of a
     * double-faced card ("Fable of the Mirror-Breaker // ..."). Any printing
     * satisfies a deck line, so the first match wins.
     */
    public function findOneByExactName(string $name): ?\App\Entity\Card
    {
        $lower = mb_strtolower(trim($name));
        if ('' === $lower) {
            return null;
        }

        return $this->magicScoped()
            ->andWhere('LOWER(c.name) = :name OR LOWER(c.name) LIKE :front')
            ->setParameter('name', $lower)
            ->setParameter('front', $lower.' //%')
            ->orderBy('c.releasedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Printing lookup by natural key. A printing is uniquely identified by
     * set code + collector number, and every import row carries both — this
     * is the primary (indexed, exact) match path for imports; name search is
     * only the fallback. Backed by the expression index on
     * (LOWER(set_code), LOWER(collector_number)).
     *
     * Returns a list because multiple language rows can share a set/collector
     * pair; callers pick with their own filters.
     *
     * @return list<Card>
     */
    public function findByNaturalKey(string $setCode, string $collectorNumber, int $limit = 10): array
    {
        return $this->magicScoped()
            ->andWhere('LOWER(c.setCode) = :setCode')
            ->andWhere('LOWER(c.collectorNumber) = :collectorNumber')
            ->setParameter('setCode', strtolower(trim($setCode)))
            ->setParameter('collectorNumber', strtolower(trim($collectorNumber)))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Game-scoped printing lookup for non-Magic imports. Magic singles come
     * from Scryfall (see CatalogCardResolver); every other game's catalog is
     * local (TCGCSV), so a row resolves by matching name within the game,
     * narrowed by collector number and set when the sheet supplies them.
     */
    public function findOneForGame(Game $game, string $name, string $setCode = '', string $collectorNumber = ''): ?Card
    {
        // Magic is Scryfall's domain (CatalogCardResolver), and this
        // matcher's collector-number-first strategy is only sound where a
        // collector number is unique within the game. In Magic "254" names
        // hundreds of cards, so answering here would hand back an arbitrary
        // one — which is exactly how a repair run once re-pointed listings
        // at the wrong printings.
        if ($game->isMtg()) {
            return null;
        }

        // 1. Natural key. Outside Magic a collector number ("OP01-003",
        //    "MON038") encodes its own set and is unique within the game, so
        //    it identifies the printing on its own. Matching on it first means
        //    a sheet whose set column says "Romance Dawn" while the catalog
        //    calls it "OP-01 Romance Dawn" still resolves.
        if ('' !== trim($collectorNumber)) {
            $byNumber = $this->scopedToGame($game)
                ->andWhere('LOWER(c.collectorNumber) = :collector')
                ->setParameter('collector', mb_strtolower(trim($collectorNumber)))
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($byNumber instanceof Card) {
                return $byNumber;
            }
        }

        // 2. Exact name, narrowed by set when the sheet gave one.
        $exact = $this->matchByName($game, $name, $setCode, exact: true);
        if ($exact instanceof Card) {
            return $exact;
        }

        // 3. Same name ignoring punctuation and spacing. Card names in these
        //    games are punctuation-heavy ("Monkey.D.Luffy", "Trafalgar Law"),
        //    and every export writes them slightly differently.
        return $this->matchByName($game, $name, $setCode, exact: false);
    }

    /**
     * Real cards from this game's catalog, for building an import template
     * that is guaranteed to resolve. Prefers printings with a collector
     * number, since that is the natural key the importer matches on.
     *
     * @return list<Card>
     */
    public function findSampleForGame(Game $game, int $limit = 2): array
    {
        return $this->scopedToGame($game)
            ->andWhere("c.collectorNumber <> ''")
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Cards in this game that share a name, whatever set they are in. Used to
     * turn "no match" into an error that says what the catalog does have.
     *
     * @return list<Card>
     */
    public function findNamesakesForGame(Game $game, string $name, int $limit = 5): array
    {
        // Search on the name's most distinctive word, not the whole string —
        // "Trafalgar Law (Parallel)" must still surface "Trafalgar Law".
        $seed = $this->searchSeed($name);
        if ('' === $seed) {
            return [];
        }

        return $this->scopedToGame($game)
            ->andWhere('LOWER(c.name) LIKE :like')
            ->setParameter('like', '%'.$seed.'%')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function matchByName(Game $game, string $name, string $setCode, bool $exact): ?Card
    {
        if ($exact) {
            $needle = mb_strtolower(trim($name));
            if ('' === $needle) {
                return null;
            }

            $match = $this->nameQuery($game, $needle, $setCode)->getQuery()->getOneOrNullResult();
            if ($match instanceof Card || '' === trim($setCode)) {
                return $match;
            }

            // The set narrowed it to nothing. A renamed or mistyped set column
            // should not hide a card the catalog clearly has.
            return $this->nameQuery($game, $needle, '')->getQuery()->getOneOrNullResult();
        }

        return $this->matchByNormalizedName($game, $name, $setCode);
    }

    /**
     * Fallback for names that differ only in punctuation or spacing
     * ("Monkey.D.Luffy" vs "Monkey. D. Luffy"). Those differences defeat both
     * equality and LIKE, so candidates are pulled with the name's first word
     * and compared with punctuation stripped on both sides.
     */
    private function matchByNormalizedName(Game $game, string $name, string $setCode): ?Card
    {
        $needle = $this->normalizeName($name);
        if ('' === $needle) {
            return null;
        }

        $seed = $this->searchSeed($name);
        if ('' === $seed) {
            return null;
        }

        /** @var list<Card> $candidates */
        $candidates = $this->scopedToGame($game)
            ->andWhere('LOWER(c.name) LIKE :seed')
            ->setParameter('seed', '%'.$seed.'%')
            ->setMaxResults(self::NORMALIZED_MATCH_CANDIDATES)
            ->getQuery()
            ->getResult();

        $fallback = null;
        foreach ($candidates as $candidate) {
            if ($this->normalizeName($candidate->getName()) !== $needle) {
                continue;
            }

            // A set match wins; otherwise remember the first same-name hit in
            // case the sheet's set column is wrong.
            if ('' === trim($setCode) || $this->matchesSet($candidate, $setCode)) {
                return $candidate;
            }
            $fallback ??= $candidate;
        }

        return $fallback;
    }

    private function nameQuery(Game $game, string $needle, string $setCode): QueryBuilder
    {
        $qb = $this->scopedToGame($game)
            ->andWhere('LOWER(c.name) = :name')
            ->setParameter('name', $needle)
            ->setMaxResults(1);

        if ('' !== trim($setCode)) {
            $qb->andWhere('LOWER(c.setCode) = :setCode OR LOWER(c.setName) = :setCode')
                ->setParameter('setCode', mb_strtolower(trim($setCode)));
        }

        return $qb;
    }

    private function matchesSet(Card $card, string $setCode): bool
    {
        $wanted = mb_strtolower(trim($setCode));

        return mb_strtolower($card->getSetCode()) === $wanted
            || mb_strtolower((string) $card->getSetName()) === $wanted;
    }

    /** Longest leading word of a name, used to pull normalization candidates. */
    private function searchSeed(string $name): string
    {
        preg_match_all('/[a-z0-9]+/u', mb_strtolower(trim($name)), $matches);
        $words = $matches[0] ?? [];
        if ([] === $words) {
            return '';
        }

        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $words[0];
    }

    /** Base query for one game, including legacy NULL-game rows for Magic. */
    private function scopedToGame(Game $game): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c');

        if ($game->isMtg()) {
            $qb->leftJoin('c.game', 'g')
                ->andWhere('g.code = :gameCode OR c.game IS NULL')
                ->setParameter('gameCode', Game::CODE_MTG);
        } else {
            $qb->andWhere('c.game = :game')->setParameter('game', $game);
        }

        return $qb;
    }

    /** Lowercase, stripped of the punctuation card names disagree about. */
    private function normalizeName(string $name): string
    {
        return str_replace(['.', ' ', '-', ','], '', mb_strtolower(trim($name)));
    }

    /** Is this a set code the local catalog knows? (case-insensitive) */
    public function setCodeExists(string $setCode): bool
    {
        return null !== $this->magicScoped()
            ->select('1')
            ->andWhere('LOWER(c.setCode) = :setCode')
            ->setParameter('setCode', strtolower(trim($setCode)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Resolve a full set NAME ("Adventures in the Forgotten Realms") to its
     * code ("afr") via the local catalog, case-insensitively. Null when no set
     * by that name is known locally.
     */
    public function findSetCodeByName(string $setName): ?string
    {
        $row = $this->magicScoped()
            ->select('c.setCode')
            ->andWhere('LOWER(c.setName) = :setName')
            ->setParameter('setName', strtolower(trim($setName)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return is_array($row) ? (string) $row['setCode'] : null;
    }

    /**
     * Base query for the LEGACY helpers above (name search, exact name,
     * natural key, set-code lookups). Those all predate the multi-game
     * catalog and back Magic-only surfaces — deck imports, CSV resolution,
     * the buy list, Scryfall-backed search — so they must never see another
     * game's rows. Without this, a One Piece "OP13" set code or a shared
     * card name leaks into Magic flows and gets added from a Magic context.
     * Game-aware callers use the explicit *ForGame variants instead.
     */
    private function magicScoped(): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.game', 'legacyGame')
            ->andWhere('legacyGame.code = :legacyMtg OR c.game IS NULL')
            ->setParameter('legacyMtg', Game::CODE_MTG);
    }
}
