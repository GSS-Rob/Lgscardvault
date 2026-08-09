<?php

namespace App\Tests\Service;

use App\Service\Recommend\StrategyCatalog;
use App\Service\Recommend\ThemeTokenizer;
use App\Tests\Support\CatalogFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StrategyCatalogTest extends KernelTestCase
{
    public function testDetectsProliferateStrategyAndClassifiesRoles(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $fixtures = new CatalogFixtures($em);
        $catalog = new StrategyCatalog();
        $tokenizer = new ThemeTokenizer();

        $commander = $fixtures->card(740, [
            'name' => 'Atraxa Strat',
            'type_line' => 'Legendary Creature — Phyrexian Angel',
            'oracle_text' => 'At the beginning of your end step, proliferate.',
            'keywords' => ['Proliferate'],
            'color_identity' => ['W', 'U', 'B', 'G'],
        ]);
        $strategies = $catalog->strategiesForCommander($commander, $tokenizer);
        self::assertSame('proliferate', $strategies[0]['id']);

        $strategy = $catalog->get('proliferate');
        self::assertNotNull($strategy);

        $engine = $fixtures->card(741, [
            'name' => 'Fluxy',
            'type_line' => 'Creature — Wizard',
            'oracle_text' => 'Whenever you cast a noncreature spell, proliferate.',
            'keywords' => ['Proliferate'],
            'color_identity' => ['U'],
        ]);
        $engineClass = $catalog->classifyCard($engine, $strategy, $tokenizer);
        self::assertSame('enabler', $engineClass['primary'], 'permanent proliferators are engines');
        self::assertSame('creature', $catalog->primaryCardType($engine));

        $fuelSpell = $fixtures->card(745, [
            'name' => 'Plan the Spread',
            'type_line' => 'Sorcery',
            'oracle_text' => 'Proliferate. Draw a card.',
            'keywords' => ['Proliferate'],
            'color_identity' => ['U'],
        ]);
        $fuelClass = $catalog->classifyCard($fuelSpell, $strategy, $tokenizer);
        self::assertSame('fuel', $fuelClass['primary'], 'spell proliferate is intermediary fuel');

        $enabler = $fixtures->card(743, [
            'name' => 'Counter Dropper',
            'type_line' => 'Creature — Phyrexian',
            'oracle_text' => 'When this enters, put a +1/+1 counter on target creature.',
            'color_identity' => ['G'],
        ]);
        $enablerClass = $catalog->classifyCard($enabler, $strategy, $tokenizer);
        self::assertSame('enabler', $enablerClass['primary']);

        $payoff = $fixtures->card(744, [
            'name' => 'Infect Finisher',
            'type_line' => 'Creature — Phyrexian',
            'oracle_text' => 'Infect. For each counter on this creature, it gets +1/+1.',
            'keywords' => ['Infect'],
            'color_identity' => ['B'],
        ]);
        $payoffClass = $catalog->classifyCard($payoff, $strategy, $tokenizer);
        self::assertSame('payoff', $payoffClass['primary']);

        $land = $fixtures->card(742, [
            'name' => 'Island Test',
            'type_line' => 'Basic Land — Island',
            'oracle_text' => '{T}: Add {U}.',
            'color_identity' => ['U'],
        ]);
        self::assertSame('land', $catalog->primaryCardType($land));
    }
}
