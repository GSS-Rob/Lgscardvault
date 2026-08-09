<?php

namespace App\Command;

use App\Entity\InventoryItem;
use App\Entity\Store;
use App\Enum\CardCondition;
use App\Repository\StoreRepository;
use App\Service\Recommend\SynergyIndexBuilder;
use App\Service\Scryfall\ScryfallClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pulls a coherent commander package from Scryfall into the local catalog and
 * stocks it on a demo store so recommend → cart can be exercised end-to-end
 * without waiting on a full oracle_cards sync.
 */
#[AsCommand(
    name: 'app:recommend:seed-commander-package',
    description: 'Seed a commander + synergistic cards into store inventory via Scryfall',
)]
final class SeedCommanderPackageCommand extends Command
{
    /**
     * Exact-name Scryfall queries for an Atraxa proliferate / counters package.
     * Kept small so seeding stays within Scryfall's polite rate budget.
     *
     * @var list<string>
     */
    private const PACKAGE = [
        'Atraxa, Praetors\' Voice',
        'Doubling Season',
        'Vorinclex, Monstrous Raider',
        'Evolution Sage',
        'Flux Channeler',
        'Inexorable Tide',
        'Tekuthal, Inquiry Dominus',
        'Viral Drake',
        'Thrummingbird',
        'Contagion Engine',
        'Deepglow Skate',
        'Pir, Imaginative Rascal',
        'Toothy, Imaginary Friend',
        'Sol Ring',
        'Arcane Signet',
        'Command Tower',
        'Exotic Orchard',
        'Fellwar Stone',
        'Astral Cornucopia',
        'Everflowing Chalice',
        'Swarm Intelligence',
        'Blinkmoth Infusion',
        'Contentious Plan',
        'Experimental Augury',
        'Reject Imperfection',
        'Fuel for the Cause',
        'Serum Snare',
        'Bloated Contaminator',
        'Venerated Rotpriest',
        'Skithiryx, the Blight Dragon',
    ];

    public function __construct(
        private readonly StoreRepository $stores,
        private readonly ScryfallClient $scryfall,
        private readonly SynergyIndexBuilder $synergyIndex,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store slug', 'acme-tcg')
            ->addOption('skip-synergy', null, InputOption::VALUE_NONE, 'Do not rebuild the theme synergy index after seeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = (string) $input->getOption('store');
        $store = $this->stores->findOneBy(['slug' => $slug]);
        if (!$store instanceof Store) {
            $io->error(sprintf('Store "%s" not found.', $slug));

            return Command::FAILURE;
        }

        $io->section(sprintf('Seeding commander package into /s/%s', $slug));
        $added = 0;
        $skipped = 0;

        foreach (self::PACKAGE as $index => $name) {
            try {
                // Exact-name Scryfall search; take the first English printing.
                $query = sprintf('!"%s"', $name);
                $cards = $this->scryfall->searchRemoteAndUpsert($query, 1);
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s — %s', $name, $e->getMessage()));
                ++$skipped;
                // Back off harder on rate limits so the rest of the package can finish.
                usleep(str_contains($e->getMessage(), '429') ? 1_500_000 : 200_000);
                continue;
            }

            if ([] === $cards) {
                $io->warning(sprintf('No Scryfall hit for %s', $name));
                ++$skipped;
                continue;
            }

            $card = $cards[0];
            $existing = $this->entityManager->getRepository(InventoryItem::class)->findOneBy([
                'store' => $store,
                'card' => $card,
                'condition' => CardCondition::NM,
                'finish' => 'Nonfoil',
            ]);

            if ($existing instanceof InventoryItem) {
                if ($existing->getQuantity() < 1) {
                    $existing->setQuantity(2);
                    $this->entityManager->flush();
                    $io->text(sprintf('Restocked %s', $card->getName()));
                    ++$added;
                } else {
                    $io->text(sprintf('Already stocked: %s', $card->getName()));
                    ++$skipped;
                }
                usleep(150_000);
                continue;
            }

            // Price ladder so the UI shows variety; still demo-scale.
            $priceCents = 99 + (($index % 10) * 150) + (str_contains(strtolower($name), 'atraxa') ? 2500 : 0);
            $item = (new InventoryItem())
                ->setStore($store)
                ->setCard($card)
                ->setQuantity(2 + ($index % 3))
                ->setPriceCents($priceCents)
                ->setCondition(CardCondition::NM)
                ->applyFinish('Nonfoil')
                ->setNotes('Commander synergy demo stock');

            $this->entityManager->persist($item);
            $this->entityManager->flush();
            $io->text(sprintf('Stocked %s ×%d @ $%.2f', $card->getName(), $item->getQuantity(), $priceCents / 100));
            ++$added;

            // Be polite to Scryfall between lookups.
            usleep(150_000);
        }

        if (!$input->getOption('skip-synergy')) {
            $io->section('Rebuilding theme synergy index');
            $result = $this->synergyIndex->rebuildThemeIndex(5000);
            $io->text(sprintf('%d cards → %d edges', $result['cards'], $result['edges']));
        }

        $io->success(sprintf('Done. Added/restocked %d, skipped %d.', $added, $skipped));
        $io->listing([
            sprintf('Open /s/%s/deck-builder', $slug),
            'Search for Atraxa, Praetors\' Voice',
            'Add recommendations to cart individually or en masse',
        ]);

        return Command::SUCCESS;
    }
}
