<?php

namespace App\Tests\Service;

use App\Service\CaseCards\ColorIdentityParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The smart filter vocabulary: natural terms owners type must resolve to the
 * right canonical color-identity code, and cards must match correctly.
 */
final class ColorIdentityParserTest extends TestCase
{
    private ColorIdentityParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ColorIdentityParser();
    }

    /** @return iterable<string, array{string, string}> */
    public static function termProvider(): iterable
    {
        // Mono colors + aliases
        yield 'black' => ['Black', 'B'];
        yield 'mono red' => ['mono red', 'R'];
        yield 'mono-blue' => ['Mono-Blue', 'U'];
        yield 'single letter' => ['g', 'G'];
        // Guilds
        yield 'azorius' => ['Azorius', 'WU'];
        yield 'rakdos' => ['rakdos', 'BR'];
        yield 'selesnya (GW sorts to WG)' => ['Selesnya', 'WG'];
        yield 'simic' => ['SIMIC', 'UG'];
        // Shards and wedges
        yield 'esper' => ['Esper', 'WUB'];
        yield 'grixis' => ['grixis', 'UBR'];
        yield 'abzan' => ['Abzan', 'WBG'];
        yield 'temur' => ['temur', 'URG'];
        // Four-color names
        yield 'sans white' => ['sans-white', 'UBRG'];
        yield 'no green' => ['no green', 'WUBR'];
        yield 'c16 growth' => ['Growth', 'WUBG'];
        yield 'nephilim yore tiller' => ['Yore-Tiller', 'WUBR'];
        yield 'generic four color' => ['four-color', '4C'];
        yield '4c' => ['4c', '4C'];
        // Five-color
        yield 'five color' => ['Five-Color', 'WUBRG'];
        yield 'wubrg letters' => ['wubrg', 'WUBRG'];
        yield 'rainbow' => ['rainbow', 'WUBRG'];
        yield '5c' => ['5c', 'WUBRG'];
        // Specials
        yield 'colorless' => ['Colorless', 'C'];
        yield 'colourless (uk)' => ['colourless', 'C'];
        yield 'multicolor' => ['Multicolor', 'M'];
        yield 'gold' => ['gold', 'M'];
        // Letter combos in any order canonicalize
        yield 'ub' => ['ub', 'UB'];
        yield 'gw reorders' => ['gw', 'WG'];
        yield 'rgb reorders' => ['rgb', 'BRG'];
    }

    #[DataProvider('termProvider')]
    public function testParsesNaturalTerms(string $term, string $expected): void
    {
        self::assertSame($expected, $this->parser->parse($term));
    }

    public function testRejectsUnknownTerms(): void
    {
        self::assertNull($this->parser->parse('purple'));
        self::assertNull($this->parser->parse('wwu')); // duplicate letters
        self::assertNull($this->parser->parse(''));
    }

    public function testMatchesExactIdentity(): void
    {
        self::assertTrue($this->parser->matches('WU', ['W', 'U']));
        self::assertTrue($this->parser->matches('WU', ['U', 'W'])); // order-insensitive
        self::assertFalse($this->parser->matches('WU', ['W']));
        self::assertFalse($this->parser->matches('WU', ['W', 'U', 'B']));
        self::assertTrue($this->parser->matches('B', ['B']));
        self::assertFalse($this->parser->matches('B', []));
    }

    public function testMatchesSpecials(): void
    {
        self::assertTrue($this->parser->matches('C', []));
        self::assertTrue($this->parser->matches('C', null));
        self::assertFalse($this->parser->matches('C', ['G']));

        self::assertTrue($this->parser->matches('M', ['U', 'B']));
        self::assertTrue($this->parser->matches('M', ['W', 'U', 'B', 'R', 'G']));
        self::assertFalse($this->parser->matches('M', ['R']));

        self::assertTrue($this->parser->matches('4C', ['U', 'B', 'R', 'G']));
        self::assertFalse($this->parser->matches('4C', ['W', 'U', 'B', 'R', 'G']));
    }

    public function testLabels(): void
    {
        self::assertSame('Azorius (WU)', $this->parser->label('WU'));
        self::assertSame('Mono Black (B)', $this->parser->label('B'));
        self::assertSame('Colorless', $this->parser->label('C'));
        self::assertSame('Five-Color (WUBRG)', $this->parser->label('WUBRG'));
        self::assertSame('Esper (WUB)', $this->parser->label('WUB'));
    }

    public function testIsCanonical(): void
    {
        self::assertTrue($this->parser->isCanonical('WU'));
        self::assertTrue($this->parser->isCanonical('C'));
        self::assertTrue($this->parser->isCanonical('4C'));
        self::assertFalse($this->parser->isCanonical('UW')); // wrong order
        self::assertFalse($this->parser->isCanonical('X'));
    }

    public function testCommanderColorIdentitySubset(): void
    {
        self::assertTrue($this->parser->isSubsetOf(['W', 'U', 'B', 'G'], ['U']));
        self::assertTrue($this->parser->isSubsetOf(['W', 'U', 'B', 'G'], []));
        self::assertTrue($this->parser->isSubsetOf(['W', 'U', 'B', 'G'], null));
        self::assertFalse($this->parser->isSubsetOf(['W', 'U', 'B', 'G'], ['R']));
        self::assertFalse($this->parser->isSubsetOf(['R'], ['R', 'G']));
        self::assertSame('WUBG', $this->parser->identityCode(['G', 'W', 'B', 'U']));
        self::assertSame('C', $this->parser->identityCode([]));
    }
}
