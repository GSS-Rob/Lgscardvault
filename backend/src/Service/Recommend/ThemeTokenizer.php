<?php

namespace App\Service\Recommend;

use App\Entity\Card;

/**
 * Extracts lightweight theme tags from a card's keywords + oracle text.
 *
 * Local signals for commander synergy — not a substitute for decklist
 * co-occurrence, but enough to rank in-stock cards that share a strategy
 * with the commander (proliferate, tokens, aristocrats, etc.).
 */
final class ThemeTokenizer
{
    /** Plain substring needles (matched case-insensitively). @var array<string, list<string>> */
    private const SUBSTRING_TAGS = [
        'proliferate' => ['proliferate'],
        'counters' => ['+1/+1 counter', 'loyalty counter', 'charge counter', 'oil counter', 'poison counter'],
        'toxic' => ['toxic', 'poisonous', 'infect'],
        'tokens' => ['create a token', 'create two', 'create three', 'populate', 'token'],
        'treasure' => ['treasure token', 'create a treasure'],
        'aristocrats' => ['whenever a creature you control dies', 'sacrifice a creature', 'sacrifice another'],
        'etb' => ['enters the battlefield', 'enters,'],
        'blink' => ['flicker'],
        'reanimator' => ['reanimate', 'unearthed'],
        'graveyard' => ['from your graveyard', 'from a graveyard'],
        'mill' => ['mill '],
        'draw' => ['draw a card', 'draw two cards', 'draw three cards'],
        'ramp' => ['search your library for a', 'put a land'],
        'removal' => ['destroy target', 'exile target', 'fight target'],
        'board_wipe' => ['destroy all', 'exile all'],
        'equipment' => ['equip ', 'equipped creature', 'equipment'],
        'auras' => ['enchant creature', 'enchanted creature'],
        'lifegain' => ['you gain', 'gain 1 life', 'gain 2 life', 'gain life'],
        'go_wide' => ['creatures you control get', 'other creatures you control'],
        'spellslinger' => ['instant or sorcery', 'whenever you cast an instant', 'prowess', 'magecraft'],
        'tribal_goblin' => ['goblin'],
        'tribal_elf' => ['elf'],
        'tribal_zombie' => ['zombie'],
        'tribal_vampire' => ['vampire'],
        'tribal_dragon' => ['dragon'],
        'tribal_angel' => ['angel'],
        'tribal_dinosaur' => ['dinosaur'],
        'landfall' => ['landfall'],
        'cascade' => ['cascade'],
        'artifacts' => ['artifact'],
        'enchantments' => ['enchantment'],
    ];

    /**
     * @return list<string> sorted unique tags
     */
    public function tokenize(Card $card): array
    {
        $haystack = strtolower(trim(implode("\n", array_filter([
            $card->getTypeLine() ?? '',
            $card->getOracleText() ?? '',
            implode(' ', $card->getKeywords() ?? []),
        ]))));

        if ('' === $haystack) {
            return [];
        }

        $tags = [];
        foreach (self::SUBSTRING_TAGS as $tag => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    $tags[$tag] = true;
                    break;
                }
            }
        }

        foreach ($card->getKeywords() ?? [] as $keyword) {
            $k = strtolower((string) $keyword);
            if ('proliferate' === $k) {
                $tags['proliferate'] = true;
            }
        }

        // Mana dorks / rituals — narrow regex so "add" alone does not fire.
        if (preg_match('/\{t\}: add \{/i', $haystack) || preg_match('/\{t\}: add one mana/i', $haystack)) {
            $tags['dork'] = true;
            $tags['ramp'] = true;
        }

        $out = array_keys($tags);
        sort($out);

        return $out;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return array{score: float, shared: list<string>}
     */
    public function overlap(array $a, array $b): array
    {
        if ([] === $a || [] === $b) {
            return ['score' => 0.0, 'shared' => []];
        }

        $shared = array_values(array_intersect($a, $b));
        $union = array_unique(array_merge($a, $b));
        $jaccard = count($shared) / max(1, count($union));

        return [
            'score' => min(1.0, $jaccard * 1.25),
            'shared' => $shared,
        ];
    }
}
