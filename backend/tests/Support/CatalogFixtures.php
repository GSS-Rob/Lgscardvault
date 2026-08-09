<?php

namespace App\Tests\Support;

use App\Entity\Card;
use App\Entity\InventoryItem;
use App\Entity\Store;
use App\Entity\User;
use App\Enum\CardCondition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Builders for the entities the catalog/import/inventory tests need. Each
 * runs inside DAMA's per-test transaction, so nothing here persists beyond
 * the test that created it.
 */
final class CatalogFixtures
{
    private static int $storeCounter = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Minimal Scryfall-shaped payload for the card upserter. Deterministic ids
     * are derived from $seed so tests can reference them without Uuid::v4.
     *
     * @return array<string, mixed>
     */
    public static function scryfallPayload(int $seed, array $overrides = []): array
    {
        $hex = str_pad(dechex($seed), 8, '0', STR_PAD_LEFT);

        return array_replace([
            'id' => sprintf('%s-1111-4222-8333-%012d', $hex, $seed),
            'oracle_id' => sprintf('%s-5555-4666-8777-%012d', $hex, $seed),
            'name' => 'Test Card '.$seed,
            'set' => 'tst',
            'collector_number' => (string) $seed,
            'rarity' => 'common',
            'prices' => ['usd' => '1.00', 'usd_foil' => '2.00'],
            'finishes' => ['nonfoil', 'foil'],
            'lang' => 'en',
            'layout' => 'normal',
        ], $overrides);
    }

    /**
     * Find-or-create a Card by its deterministic seed id, so the same card can
     * be referenced across stores/orders within a test without a duplicate-key
     * clash (cards are a shared global catalog).
     */
    public function card(int $seed, array $overrides = []): Card
    {
        $data = self::scryfallPayload($seed, $overrides);
        $existing = $this->em->getRepository(Card::class)->find(Uuid::fromString($data['id']));
        if ($existing instanceof Card) {
            return $existing;
        }

        $card = new Card(Uuid::fromString($data['id']));
        $card->setOracleId(Uuid::fromString($data['oracle_id']));
        $card->setName($data['name']);
        $card->setSetCode($data['set']);
        $card->setCollectorNumber($data['collector_number']);
        $card->setRarity($data['rarity'] ?? null);
        $card->setSetName($data['set_name'] ?? null);
        $card->setFinishes($data['finishes'] ?? null);
        $card->setPrices($data['prices'] ?? null);
        $card->setTypeLine($data['type_line'] ?? null);
        $card->setOracleText($data['oracle_text'] ?? null);
        $card->setManaCost($data['mana_cost'] ?? null);
        $card->setCmc(isset($data['cmc']) ? (float) $data['cmc'] : null);
        $card->setColors($data['colors'] ?? null);
        $card->setColorIdentity($data['color_identity'] ?? null);
        $card->setKeywords($data['keywords'] ?? null);
        $card->setLegalities($data['legalities'] ?? null);
        $card->setScryfallData($data);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    public function storeCase(Store $store, string $name = 'Display Case'): \App\Entity\StoreCase
    {
        $case = new \App\Entity\StoreCase();
        $case->setStore($store);
        $case->setName($name);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    public function store(?string $slug = null): Store
    {
        // Unique per call by default so a test can create several stores without
        // colliding on the unique slug / owner email.
        $slug ??= 'test-store-'.(++self::$storeCounter);

        $owner = new User();
        $owner->setEmail($slug.'-owner@test.local');
        $owner->setPassword('x');
        $owner->setDisplayName('Owner');
        $owner->setRoles(['ROLE_STORE_OWNER']);
        $this->em->persist($owner);

        $store = new Store();
        $store->setName('Test Store');
        $store->setSlug($slug);
        $store->setOwner($owner);
        $this->em->persist($store);
        $this->em->flush();

        return $store;
    }

    /**
     * @param list<string> $roles
     */
    public function user(array $roles = ['ROLE_USER'], ?string $email = null): User
    {
        $email ??= 'user-'.(++self::$storeCounter).'@test.local';
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Test User');
        $user->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @param string $finish the treatment, in the card's game's own words */
    public function inventoryItem(Store $store, Card $card, int $quantity = 1, CardCondition $condition = CardCondition::NM, string $finish = 'Nonfoil', int $priceCents = 100): InventoryItem
    {
        $item = new InventoryItem();
        $item->setStore($store);
        $item->setCard($card);
        $item->setQuantity($quantity);
        $item->setPriceCents($priceCents);
        $item->setCondition($condition);
        $item->applyFinish($finish);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }
}
