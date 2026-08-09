<?php

namespace App\Entity;

use App\Repository\CardSynergyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Directed-agnostic synergy edge between two oracle identities.
 *
 * oracle_a / oracle_b are stored in lexicographic order so each pair has one
 * row per source. weight is 0..1 for theme edges; co-occurrence sources may
 * use frequency-derived weights in the same range.
 */
#[ORM\Entity(repositoryClass: CardSynergyRepository::class)]
#[ORM\Table(name: 'card_synergies')]
#[ORM\UniqueConstraint(name: 'uniq_card_synergy_pair_source', columns: ['oracle_a', 'oracle_b', 'source'])]
#[ORM\Index(name: 'idx_card_synergy_oracle_a', columns: ['oracle_a'])]
#[ORM\Index(name: 'idx_card_synergy_oracle_b', columns: ['oracle_b'])]
#[ORM\Index(name: 'idx_card_synergy_source', columns: ['source'])]
class CardSynergy
{
    public const SOURCE_THEME = 'theme';
    public const SOURCE_COOCCURRENCE = 'cooccurrence';
    public const SOURCE_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $oracleA;

    #[ORM\Column(type: 'uuid')]
    private Uuid $oracleB;

    #[ORM\Column(type: 'float')]
    private float $weight = 0.0;

    #[ORM\Column(length: 32)]
    private string $source = self::SOURCE_THEME;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sharedTags = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOracleA(): Uuid
    {
        return $this->oracleA;
    }

    public function getOracleB(): Uuid
    {
        return $this->oracleB;
    }

    /** Normalize pair order so (A,B) and (B,A) collapse to one row. */
    public function setOraclePair(Uuid $a, Uuid $b): static
    {
        $left = (string) $a;
        $right = (string) $b;
        if ($left > $right) {
            [$a, $b] = [$b, $a];
        }
        $this->oracleA = $a;
        $this->oracleB = $b;

        return $this;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function setWeight(float $weight): static
    {
        $this->weight = max(0.0, min(1.0, $weight));

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    /** @return list<string>|null */
    public function getSharedTags(): ?array
    {
        return $this->sharedTags;
    }

    /** @param list<string>|null $sharedTags */
    public function setSharedTags(?array $sharedTags): static
    {
        $this->sharedTags = $sharedTags;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function involves(Uuid $oracleId): bool
    {
        $id = (string) $oracleId;

        return (string) $this->oracleA === $id || (string) $this->oracleB === $id;
    }

    public function otherOracle(Uuid $oracleId): ?Uuid
    {
        $id = (string) $oracleId;
        if ((string) $this->oracleA === $id) {
            return $this->oracleB;
        }
        if ((string) $this->oracleB === $id) {
            return $this->oracleA;
        }

        return null;
    }
}
