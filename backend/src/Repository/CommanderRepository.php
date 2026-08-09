<?php

namespace App\Repository;

use App\Entity\Commander;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Commander>
 */
class CommanderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commander::class);
    }

    /**
     * Typeahead over the weekly-synced commander catalog (not store inventory).
     *
     * @return list<Commander>
     */
    public function searchByName(string $query, int $limit = 20): array
    {
        $limit = max(1, min(40, $limit));
        $qb = $this->createQueryBuilder('cmd')
            ->join('cmd.card', 'c')
            ->addSelect('c')
            ->orderBy('cmd.name', 'ASC')
            ->setMaxResults($limit);

        $q = mb_strtolower(trim($query));
        if ('' !== $q) {
            $qb->andWhere('LOWER(cmd.name) LIKE :query')
                ->setParameter('query', '%'.$q.'%');
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByOracleId(Uuid $oracleId): ?Commander
    {
        return $this->find($oracleId);
    }

    /**
     * Drop oracle ids that were not seen in the latest Scryfall pass so the
     * table stays a clean "currently legal as commander" list.
     *
     * @param list<string> $seenOracleIds
     */
    public function deleteNotInOracleIds(array $seenOracleIds): int
    {
        $conn = $this->getEntityManager()->getConnection();
        if ([] === $seenOracleIds) {
            return (int) $conn->executeStatement('DELETE FROM commanders');
        }

        $existing = $conn->fetchFirstColumn('SELECT oracle_id::text FROM commanders');
        $keep = array_fill_keys($seenOracleIds, true);
        $stale = array_values(array_filter(
            $existing,
            static fn (string $id): bool => !isset($keep[$id]),
        ));

        $deleted = 0;
        foreach (array_chunk($stale, 200) as $chunk) {
            $deleted += $conn->executeStatement(
                'DELETE FROM commanders WHERE oracle_id::text IN ('.implode(',', array_fill(0, count($chunk), '?')).')',
                $chunk,
            );
        }

        return $deleted;
    }
}
