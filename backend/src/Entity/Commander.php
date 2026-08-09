<?php

namespace App\Entity;

use App\Repository\CommanderRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One Scryfall-legal commander (oracle identity), refreshed weekly.
 *
 * Denormalized display fields keep typeahead off the hot cards table; card_id
 * points at the representative printing upserted into cards so recommend
 * endpoints can load a full Card entity.
 */
#[ORM\Entity(repositoryClass: CommanderRepository::class)]
#[ORM\Table(name: 'commanders')]
#[ORM\Index(name: 'idx_commander_name', columns: ['name'])]
class Commander
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $oracleId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'card_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Card $card;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $typeLine = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $manaCost = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $cmc = null;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $colorIdentity = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $imageUri = null;

    #[ORM\Column]
    private \DateTimeImmutable $syncedAt;

    public function __construct(Uuid $oracleId, Card $card)
    {
        $this->oracleId = $oracleId;
        $this->card = $card;
        $this->name = $card->getName();
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getOracleId(): Uuid
    {
        return $this->oracleId;
    }

    public function getCard(): Card
    {
        return $this->card;
    }

    public function setCard(Card $card): static
    {
        $this->card = $card;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getTypeLine(): ?string
    {
        return $this->typeLine;
    }

    public function setTypeLine(?string $typeLine): static
    {
        $this->typeLine = $typeLine;

        return $this;
    }

    public function getManaCost(): ?string
    {
        return $this->manaCost;
    }

    public function setManaCost(?string $manaCost): static
    {
        $this->manaCost = $manaCost;

        return $this;
    }

    public function getCmc(): ?float
    {
        return $this->cmc;
    }

    public function setCmc(?float $cmc): static
    {
        $this->cmc = $cmc;

        return $this;
    }

    /** @return list<string>|null */
    public function getColorIdentity(): ?array
    {
        return $this->colorIdentity;
    }

    /** @param list<string>|null $colorIdentity */
    public function setColorIdentity(?array $colorIdentity): static
    {
        $this->colorIdentity = $colorIdentity;

        return $this;
    }

    public function getImageUri(): ?string
    {
        return $this->imageUri;
    }

    public function setImageUri(?string $imageUri): static
    {
        $this->imageUri = $imageUri;

        return $this;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function touchSyncedAt(): static
    {
        $this->syncedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Refresh denormalized fields from the representative Card printing. */
    public function syncFromCard(Card $card): static
    {
        $this->card = $card;
        $this->name = $card->getName();
        $this->typeLine = $card->getTypeLine();
        $this->manaCost = $card->getManaCost();
        $this->cmc = $card->getCmc();
        $this->colorIdentity = $card->getColorIdentity();
        $this->imageUri = $card->getImageUrl();
        $this->touchSyncedAt();

        return $this;
    }
}
