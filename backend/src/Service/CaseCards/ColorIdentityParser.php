<?php

namespace App\Service\CaseCards;

/**
 * Turns the color words store owners actually use — "black", "Azorius",
 * "five-color", "sans blue", "gruul", "wubrg" — into a canonical
 * color-identity code, and matches cards against it.
 *
 * Canonical codes:
 *  - a WUBRG-ordered subset string: "B", "WU", "UBR", "WUBRG", …
 *  - 'C'  — colorless (empty color identity)
 *  - 'M'  — any multicolor (2+ colors)
 *  - '4C' — any four-color identity
 *
 * The vocabulary covers mono colors, all ten guilds, the five shards, the
 * five wedges, four-color nicknames (C16 + Nephilim + "sans X"), five-color
 * groupings, raw letter combos, and common aliases. Matching is
 * case-insensitive and tolerant of hyphens/underscores and a "mono " prefix.
 */
final class ColorIdentityParser
{
    private const WUBRG = 'WUBRG';

    /** Special codes that are rules rather than exact identities. */
    private const SPECIALS = ['C', 'M', '4C'];

    /**
     * name → canonical identity. Keys are normalized (lowercase, single
     * spaces). Letter combos like "ub" are handled separately so any of the
     * 31 subsets works without listing them all.
     *
     * @var array<string, string>
     */
    private const NAMES = [
        // Mono colors
        'white' => 'W', 'blue' => 'U', 'black' => 'B', 'red' => 'R', 'green' => 'G',
        // Guilds (Ravnica)
        'azorius' => 'WU', 'dimir' => 'UB', 'rakdos' => 'BR', 'gruul' => 'RG', 'selesnya' => 'WG',
        'orzhov' => 'WB', 'izzet' => 'UR', 'golgari' => 'BG', 'boros' => 'WR', 'simic' => 'UG',
        // Shards (Alara)
        'bant' => 'WUG', 'esper' => 'WUB', 'grixis' => 'UBR', 'jund' => 'BRG', 'naya' => 'WRG',
        // Wedges (Tarkir)
        'abzan' => 'WBG', 'jeskai' => 'WUR', 'sultai' => 'UBG', 'mardu' => 'WBR', 'temur' => 'URG',
        // Four-color: Commander 2016 names
        'chaos' => 'UBRG', 'aggression' => 'WBRG', 'altruism' => 'WURG', 'growth' => 'WUBG', 'artifice' => 'WUBR',
        // Four-color: Nephilim names
        'glint eye' => 'UBRG', 'dune brood' => 'WBRG', 'ink treader' => 'WURG', 'witch maw' => 'WUBG', 'yore tiller' => 'WUBR',
        // Four-color: "sans X" / "no X"
        'sans white' => 'UBRG', 'no white' => 'UBRG',
        'sans blue' => 'WBRG', 'no blue' => 'WBRG',
        'sans black' => 'WURG', 'no black' => 'WURG',
        'sans red' => 'WUBG', 'no red' => 'WUBG',
        'sans green' => 'WUBR', 'no green' => 'WUBR',
        // Five-color groupings
        'five color' => 'WUBRG', 'five colour' => 'WUBRG', '5 color' => 'WUBRG', '5 colour' => 'WUBRG',
        '5c' => 'WUBRG', 'rainbow' => 'WUBRG', 'all colors' => 'WUBRG', 'all colours' => 'WUBRG',
        // Specials
        'colorless' => 'C', 'colourless' => 'C',
        'multicolor' => 'M', 'multicolored' => 'M', 'multicolour' => 'M', 'multicoloured' => 'M',
        'multi' => 'M', 'gold' => 'M',
        'four color' => '4C', 'four colour' => '4C', '4 color' => '4C', '4 colour' => '4C', '4c' => '4C',
    ];

    /**
     * Resolve a user-entered term to a canonical code, or null if the term
     * isn't recognized.
     */
    public function parse(string $term): ?string
    {
        $normalized = $this->normalize($term);
        if ('' === $normalized) {
            return null;
        }

        if (isset(self::NAMES[$normalized])) {
            return self::NAMES[$normalized];
        }

        // Raw letter combos: "b", "ub", "wubrg", "gw" — any unique subset of
        // WUBRG in any order (also catches single letters and "c").
        $letters = strtoupper(str_replace(' ', '', $normalized));
        if ('C' === $letters) {
            return 'C';
        }
        if (preg_match('/^[WUBRG]{1,5}$/', $letters)) {
            $unique = array_unique(str_split($letters));
            if (count($unique) === strlen($letters)) {
                return $this->canonicalize($unique);
            }
        }

        return null;
    }

    /** Human-readable label for a canonical code (for admin display). */
    public function label(string $code): string
    {
        return match ($code) {
            'C' => 'Colorless',
            'M' => 'Multicolor (any)',
            '4C' => 'Four-Color (any)',
            'WUBRG' => 'Five-Color (WUBRG)',
            default => sprintf('%s (%s)', $this->identityName($code), $code),
        };
    }

    /**
     * Does a card's color identity (array of letters, e.g. ["B","G"], empty
     * for colorless, null treated as colorless) satisfy the code?
     */
    public function matches(string $code, ?array $colorIdentity): bool
    {
        $letters = array_values(array_intersect(str_split(self::WUBRG), array_map('strval', $colorIdentity ?? [])));

        return match ($code) {
            'C' => [] === $letters,
            'M' => count($letters) >= 2,
            '4C' => 4 === count($letters),
            default => $this->canonicalize($letters) === $code,
        };
    }

    /**
     * Commander deckbuilding rule: every color in the card's identity must
     * appear in the commander's identity. Colorless cards (empty identity)
     * are always legal.
     *
     * @param list<string>|null $commanderIdentity
     * @param list<string>|null $cardIdentity
     */
    public function isSubsetOf(?array $commanderIdentity, ?array $cardIdentity): bool
    {
        $commander = array_values(array_intersect(
            str_split(self::WUBRG),
            array_map('strval', $commanderIdentity ?? []),
        ));
        $card = array_values(array_intersect(
            str_split(self::WUBRG),
            array_map('strval', $cardIdentity ?? []),
        ));

        if ([] === $card) {
            return true;
        }

        foreach ($card as $letter) {
            if (!in_array($letter, $commander, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical WUBRG-ordered identity string for a color-identity array
     * (empty → "C").
     *
     * @param list<string>|null $colorIdentity
     */
    public function identityCode(?array $colorIdentity): string
    {
        $letters = array_values(array_intersect(
            str_split(self::WUBRG),
            array_map('strval', $colorIdentity ?? []),
        ));

        return [] === $letters ? 'C' : $this->canonicalize($letters);
    }

    /** True when the code is one of the canonical values this parser can emit. */
    public function isCanonical(string $code): bool
    {
        if (in_array($code, self::SPECIALS, true)) {
            return true;
        }

        return 1 === preg_match('/^[WUBRG]{1,5}$/', $code)
            && $this->canonicalize(str_split($code)) === $code;
    }

    /**
     * The suggestion vocabulary for admin autocomplete — friendly names only
     * (letter combos work too but don't need suggesting).
     *
     * @return list<string>
     */
    public function suggestions(): array
    {
        return [
            'White', 'Blue', 'Black', 'Red', 'Green', 'Colorless', 'Multicolor',
            'Azorius', 'Dimir', 'Rakdos', 'Gruul', 'Selesnya', 'Orzhov', 'Izzet', 'Golgari', 'Boros', 'Simic',
            'Bant', 'Esper', 'Grixis', 'Jund', 'Naya',
            'Abzan', 'Jeskai', 'Sultai', 'Mardu', 'Temur',
            'Four-Color', 'Five-Color',
        ];
    }

    /** @param list<string> $letters */
    private function canonicalize(array $letters): string
    {
        $out = '';
        foreach (str_split(self::WUBRG) as $letter) {
            if (in_array($letter, $letters, true)) {
                $out .= $letter;
            }
        }

        return $out;
    }

    /** Best-known name for an exact identity ("WU" → "Azorius", "B" → "Mono Black"). */
    private function identityName(string $code): string
    {
        static $reverse = null;
        if (null === $reverse) {
            $reverse = [];
            foreach (self::NAMES as $name => $canonical) {
                // First (most canonical) name wins; skip aliases like "no red".
                if (!isset($reverse[$canonical]) && !str_starts_with($name, 'sans ') && !str_starts_with($name, 'no ')) {
                    $reverse[$canonical] = ucwords($name);
                }
            }
        }

        if (1 === strlen($code)) {
            return 'Mono '.($reverse[$code] ?? $code);
        }

        return $reverse[$code] ?? $code;
    }

    private function normalize(string $term): string
    {
        $t = strtolower(trim($term));
        $t = str_replace(['-', '_', '/'], ' ', $t);
        $t = preg_replace('/\s+/', ' ', $t) ?? '';
        // "mono black" and "mono-b" mean the same as "black" / "b".
        if (str_starts_with($t, 'mono ')) {
            $t = substr($t, 5);
        }

        return trim($t);
    }
}
