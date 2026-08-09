<?php

namespace App\Repository;

use App\Entity\CardSynergy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CardSynergy>
 */
class CardSynergyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardSynergy::class);
    }

    /**
     * Weights keyed by the *other* oracle id for a commander oracle.
     *
     * @return array<string, array{weight: float, tags: list<string>}>
     */
    public function weightsForOracle(Uuid $oracleId, ?string $source = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.oracleA = :id OR s.oracleB = :id')
            ->setParameter('id', $oracleId);

        if (null !== $source && '' !== $source) {
            $qb->andWhere('s.source = :source')->setParameter('source', $source);
        }

        /** @var list<CardSynergy> $rows */
        $rows = $qb->getQuery()->getResult();
        $out = [];
        foreach ($rows as $row) {
            $other = $row->otherOracle($oracleId);
            if (!$other instanceof Uuid) {
                continue;
            }
            $key = (string) $other;
            $existing = $out[$key]['weight'] ?? 0.0;
            if ($row->getWeight() >= $existing) {
                $out[$key] = [
                    'weight' => $row->getWeight(),
                    'tags' => $row->getSharedTags() ?? [],
                ];
            }
        }

        return $out;
    }

    public function deleteBySource(string $source): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.source = :source')
            ->setParameter('source', $source)
            ->getQuery()
            ->execute();
    }
}
