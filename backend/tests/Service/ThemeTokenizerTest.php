<?php

namespace App\Tests\Service;

use App\Service\Recommend\ThemeTokenizer;
use App\Tests\Support\CatalogFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ThemeTokenizerTest extends KernelTestCase
{
    public function testTokenizesProliferateAndCounters(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $fixtures = new CatalogFixtures($em);

        $card = $fixtures->card(501, [
            'name' => 'Flux Channeler',
            'type_line' => 'Creature — Human Wizard',
            'oracle_text' => 'Whenever you cast a noncreature spell, proliferate.',
            'keywords' => ['Proliferate'],
            'color_identity' => ['U'],
        ]);

        $tags = (new ThemeTokenizer())->tokenize($card);
        self::assertContains('proliferate', $tags);
    }

    public function testOverlapSharesTags(): void
    {
        $tokenizer = new ThemeTokenizer();
        $overlap = $tokenizer->overlap(['proliferate', 'counters'], ['proliferate', 'draw']);
        self::assertSame(['proliferate'], $overlap['shared']);
        self::assertGreaterThan(0.0, $overlap['score']);
    }
}
