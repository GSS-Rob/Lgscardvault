<?php

namespace App\Service\Recommend;

use App\Entity\Card;

/**
 * Curated commander strategies with enabler / fuel / payoff role rules.
 *
 * A strategy is "supported" when the commander shares required theme tags
 * (from ThemeTokenizer) or oracle-text needles. Cards are then classified
 * into deck roles for that strategy so the builder can supply a focused
 * package rather than a flat popularity list.
 */
final class StrategyCatalog
{
    public const ROLE_ENABLER = 'enabler';
    public const ROLE_FUEL = 'fuel';
    public const ROLE_PAYOFF = 'payoff';
    public const ROLE_SUPPORT = 'support';

    public const ROLES = [
        self::ROLE_ENABLER,
        self::ROLE_FUEL,
        self::ROLE_PAYOFF,
        self::ROLE_SUPPORT,
    ];

    public const CARD_TYPES = [
        'creature',
        'enchantment',
        'instant',
        'sorcery',
        'artifact',
        'land',
        'planeswalker',
        'other',
    ];

    /**
     * @return list<array{
     *   id: string,
     *   label: string,
     *   description: string,
     *   commanderTags: list<string>,
     *   commanderNeedles: list<string>,
     *   roles: array<string, array{tags: list<string>, needles: list<string>}>
     * }>
     */
    public function all(): array
    {
        return [
            [
                'id' => 'proliferate',
                'label' => 'Proliferate',
                'description' => 'Stack counters with proliferate — engines that place counters, more proliferate as fuel, and infect/loyalty payoffs.',
                'commanderTags' => ['proliferate', 'counters', 'toxic'],
                'commanderNeedles' => ['proliferate'],
                'roles' => [
                    // Engines: place counters to proliferate, or permanent proliferators.
                    // Toxic/infect stay on payoff — they are the win condition, not the engine.
                    self::ROLE_ENABLER => [
                        'tags' => ['counters', 'proliferate'],
                        'needles' => ['+1/+1 counter', 'loyalty counter', 'oil counter', 'enters with', 'put a counter', 'put two +1/+1', 'proliferate'],
                    ],
                    // Intermediary: spell-speed proliferate and "whenever you proliferate" value.
                    self::ROLE_FUEL => [
                        'tags' => [],
                        'needles' => ['whenever you proliferate', 'proliferate'],
                    ],
                    self::ROLE_PAYOFF => [
                        // Keep payoffs specific — bare "counters" overlaps enablers
                        // that only place counters and would steal the headline role.
                        'tags' => ['toxic'],
                        'needles' => ['infect', 'toxic', 'poison', 'for each counter', 'counters on', 'loyalty abilities', 'win the game'],
                    ],
                ],
                // Prefer permanents with proliferate as enablers; instants/sorceries as fuel.
                'roleTypeHints' => [
                    self::ROLE_ENABLER => ['creature', 'artifact', 'enchantment', 'planeswalker'],
                    self::ROLE_FUEL => ['instant', 'sorcery'],
                ],
            ],
            [
                'id' => 'tokens',
                'label' => 'Tokens / Go Wide',
                'description' => 'Make creatures, pump the board, and overwhelm — token makers, populate/fuel, and anthem payoffs.',
                'commanderTags' => ['tokens', 'go_wide', 'treasure'],
                'commanderNeedles' => ['create a token', 'create two', 'populate', 'tokens you control'],
                'roles' => [
                    self::ROLE_ENABLER => [
                        'tags' => ['tokens', 'treasure'],
                        'needles' => ['create a token', 'create two', 'create three', 'create x', 'populate'],
                    ],
                    self::ROLE_FUEL => [
                        'tags' => ['tokens', 'etb'],
                        'needles' => ['create a token', 'populate', 'token creature', 'whenever you create'],
                    ],
                    self::ROLE_PAYOFF => [
                        'tags' => ['go_wide'],
                        'needles' => ['creatures you control get', 'other creatures you control', 'for each creature', 'tokens you control get', 'attacking creatures'],
                    ],
                ],
            ],
            [
                'id' => 'aristocrats',
                'label' => 'Aristocrats',
                'description' => 'Sacrifice engines, recurring fuel, and drain/damage payoffs.',
                'commanderTags' => ['aristocrats', 'etb', 'tokens'],
                'commanderNeedles' => ['sacrifice a creature', 'whenever .* dies', 'whenever a creature you control dies'],
                'roles' => [
                    self::ROLE_ENABLER => [
                        'tags' => ['aristocrats', 'tokens'],
                        'needles' => ['sacrifice a creature', 'sacrifice another', 'as an additional cost, sacrifice'],
                    ],
                    self::ROLE_FUEL => [
                        'tags' => ['tokens', 'etb', 'reanimator'],
                        'needles' => ['create a token', 'return .* from your graveyard', 'whenever .* dies, return', 'undying', 'persist'],
                    ],
                    self::ROLE_PAYOFF => [
                        'tags' => ['aristocrats', 'lifegain'],
                        'needles' => ['whenever a creature you control dies', 'each opponent loses', 'drain', 'blood artist', 'whenever you sacrifice'],
                    ],
                ],
            ],
            [
                'id' => 'spellslinger',
                'label' => 'Spellslinger',
                'description' => 'Cast instants and sorceries — cost reducers and rituals as enablers, cantrips as fuel, copy/storm payoffs.',
                'commanderTags' => ['spellslinger', 'draw'],
                'commanderNeedles' => ['instant or sorcery', 'whenever you cast an instant', 'magecraft', 'prowess'],
                'roles' => [
                    self::ROLE_ENABLER => [
                        'tags' => ['spellslinger', 'ramp'],
                        'needles' => ['instant and sorcery spells cost', 'the next instant', 'copy target instant', 'storm'],
                    ],
                    self::ROLE_FUEL => [
                        'tags' => ['draw', 'spellslinger'],
                        'needles' => ['draw a card', 'scry', 'impulse', 'exile the top'],
                    ],
                    self::ROLE_PAYOFF => [
                        'tags' => ['spellslinger'],
                        'needles' => ['whenever you cast an instant', 'magecraft', 'prowess', 'copy that spell', 'storm', 'for each instant'],
                    ],
                ],
            ],
            [
                'id' => 'reanimator',
                'label' => 'Reanimator',
                'description' => 'Fill the yard, reanimate bombs, protect the engine.',
                'commanderTags' => ['reanimator', 'graveyard', 'mill'],
                'commanderNeedles' => ['return .* from your graveyard', 'reanimate', 'from a graveyard onto the battlefield'],
                'roles' => [
                    self::ROLE_ENABLER => [
                        'tags' => ['mill', 'graveyard'],
                        'needles' => ['mill ', 'discard', 'put into your graveyard', 'surveil'],
                    ],
                    self::ROLE_FUEL => [
                        'tags' => ['reanimator', 'graveyard'],
                        'needles' => ['return .* from your graveyard', 'reanimate', 'unearth', 'flashback'],
                    ],
                    self::ROLE_PAYOFF => [
                        'tags' => ['reanimator'],
                        'needles' => ['when .* enters', 'enters the battlefield', 'exile target', 'destroy all'],
                    ],
                ],
            ],
            [
                'id' => 'treasure',
                'label' => 'Treasures / Ramp',
                'description' => 'Mana acceleration into big spells — rock/ritual enablers, treasure makers as fuel, expensive payoffs.',
                'commanderTags' => ['treasure', 'ramp', 'dork'],
                'commanderNeedles' => ['treasure token', 'add \{', 'search your library for a basic'],
                'roles' => [
                    self::ROLE_ENABLER => [
                        'tags' => ['ramp', 'dork'],
                        'needles' => ['add \{', '{t}: add', 'search your library for a', 'mana rock'],
                    ],
                    self::ROLE_FUEL => [
                        'tags' => ['treasure', 'ramp'],
                        'needles' => ['treasure token', 'create a treasure', 'gold token', 'mana value'],
                    ],
                    self::ROLE_PAYOFF => [
                        'tags' => [],
                        'needles' => ['costs {x} less', 'cast spells', 'without paying', 'cascade', 'extra turn'],
                    ],
                ],
            ],
            [
                'id' => 'equipment',
                'label' => 'Equipment / Voltron',
                'description' => 'Suited-up commander damage — equipment enablers, tutors/cheats as fuel, evasion and double-strike payoffs.',
                'commanderTags' => ['equipment', 'auras'],
                'commanderNeedles' => ['equip ', 'equipment', 'equipped creature', 'aura '],
                'roles' => [
                    self::ROLE_ENABLER => [
                        'tags' => ['equipment', 'auras'],
                        'needles' => ['equip ', 'equipment', 'enchant creature', 'attach'],
                    ],
                    self::ROLE_FUEL => [
                        'tags' => ['equipment'],
                        'needles' => ['search your library for an equipment', 'attach target', 'equip cost'],
                    ],
                    self::ROLE_PAYOFF => [
                        'tags' => ['equipment', 'auras'],
                        'needles' => ['double strike', 'commander damage', 'equipped creature gets', 'hexproof', 'indestructible', 'unblockable'],
                    ],
                ],
            ],
            [
                'id' => 'landfall',
                'label' => 'Landfall',
                'description' => 'Lands matter — ramp enablers, extra land drops as fuel, landfall triggers as payoff.',
                'commanderTags' => ['landfall', 'ramp'],
                'commanderNeedles' => ['landfall', 'whenever a land enters', 'lands you control'],
                'roles' => [
                    self::ROLE_ENABLER => [
                        'tags' => ['ramp'],
                        'needles' => ['search your library for a', 'put a land', 'play an additional land'],
                    ],
                    self::ROLE_FUEL => [
                        'tags' => ['landfall', 'ramp'],
                        'needles' => ['play an additional land', 'lands enter', 'fetch', 'basic land'],
                    ],
                    self::ROLE_PAYOFF => [
                        'tags' => ['landfall'],
                        'needles' => ['landfall', 'whenever a land enters', 'for each land'],
                    ],
                ],
            ],
            [
                'id' => 'tribal_goblin',
                'label' => 'Goblin Tribal',
                'description' => 'Goblins matter — lords and makers as enablers, token fuel, tribal payoffs.',
                'commanderTags' => ['tribal_goblin'],
                'commanderNeedles' => ['goblin'],
                'roles' => [
                    self::ROLE_ENABLER => [
                        'tags' => ['tribal_goblin', 'tokens'],
                        'needles' => ['goblin', 'create .* goblin'],
                    ],
                    self::ROLE_FUEL => [
                        'tags' => ['tribal_goblin', 'tokens'],
                        'needles' => ['goblin', 'create a 1/1 red goblin'],
                    ],
                    self::ROLE_PAYOFF => [
                        'tags' => ['tribal_goblin', 'go_wide'],
                        'needles' => ['goblins you control', 'other goblins', 'for each goblin'],
                    ],
                ],
            ],
            [
                'id' => 'blink',
                'label' => 'Blink / ETB',
                'description' => 'Value creatures and flicker engines — ETB bodies, blink spells as fuel, and recursive value payoffs.',
                'commanderTags' => ['blink', 'etb'],
                'commanderNeedles' => ['exile .* return', 'flicker', 'enters the battlefield'],
                'roles' => [
                    self::ROLE_ENABLER => [
                        'tags' => ['etb'],
                        'needles' => ['when .* enters', 'enters the battlefield', 'as .* enters'],
                    ],
                    self::ROLE_FUEL => [
                        'tags' => ['blink'],
                        'needles' => ['exile target', 'return it to the battlefield', 'flicker', 'blink'],
                    ],
                    self::ROLE_PAYOFF => [
                        'tags' => ['etb', 'blink'],
                        'needles' => ['whenever a creature you control enters', 'for each time', 'value'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Strategies this commander can reasonably support, ordered by confidence.
     *
     * @return list<array{id: string, label: string, description: string, confidence: float, matchedSignals: list<string>}>
     */
    public function strategiesForCommander(Card $commander, ThemeTokenizer $tokenizer): array
    {
        $tags = $tokenizer->tokenize($commander);
        $haystack = $this->haystack($commander);
        $out = [];

        foreach ($this->all() as $strategy) {
            $matched = [];
            foreach ($strategy['commanderTags'] as $tag) {
                if (in_array($tag, $tags, true)) {
                    $matched[] = $tag;
                }
            }
            foreach ($strategy['commanderNeedles'] as $needle) {
                if ($this->matchesNeedle($haystack, $needle)) {
                    $matched[] = $needle;
                }
            }
            $matched = array_values(array_unique($matched));
            if ([] === $matched) {
                continue;
            }

            $confidence = min(1.0, 0.35 + (0.2 * count($matched)));
            // Exact tag hits are stronger than loose needles.
            foreach ($matched as $signal) {
                if (in_array($signal, $strategy['commanderTags'], true)) {
                    $confidence = min(1.0, $confidence + 0.1);
                }
            }

            $out[] = [
                'id' => $strategy['id'],
                'label' => $strategy['label'],
                'description' => $strategy['description'],
                'confidence' => round($confidence, 3),
                'matchedSignals' => $matched,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        // Always offer a generic "goodstuff / staples" fallback so every
        // commander has at least one selectable strategy.
        if ([] === $out) {
            $out[] = [
                'id' => 'staples',
                'label' => 'Staples & Support',
                'description' => 'Color-identity staples — ramp, draw, interaction, and lands to round out any list.',
                'confidence' => 0.4,
                'matchedSignals' => [],
            ];
        }

        return $out;
    }

    /** @return ?array{id: string, label: string, description: string, commanderTags: list<string>, commanderNeedles: list<string>, roles: array<string, array{tags: list<string>, needles: list<string>}>} */
    public function get(string $id): ?array
    {
        if ('staples' === $id) {
            return [
                'id' => 'staples',
                'label' => 'Staples & Support',
                'description' => 'Color-identity staples — ramp, draw, interaction, and lands to round out any list.',
                'commanderTags' => [],
                'commanderNeedles' => [],
                'roles' => [
                    self::ROLE_ENABLER => [
                        'tags' => ['ramp', 'dork'],
                        'needles' => ['add \{', 'search your library for a'],
                    ],
                    self::ROLE_FUEL => [
                        'tags' => ['draw'],
                        'needles' => ['draw a card', 'draw two'],
                    ],
                    self::ROLE_PAYOFF => [
                        'tags' => ['removal', 'board_wipe'],
                        'needles' => ['destroy target', 'exile target', 'destroy all', 'counter target'],
                    ],
                ],
            ];
        }

        foreach ($this->all() as $strategy) {
            if ($strategy['id'] === $id) {
                return $strategy;
            }
        }

        return null;
    }

    /**
     * Classify a card into deck roles for a strategy. A card can match more
     * than one role; the primary role is the strongest match (hit weight,
     * then payoff > enabler > fuel > support).
     *
     * @return array{primary: string, roles: list<string>, reasons: list<string>}
     */
    public function classifyCard(Card $card, array $strategy, ThemeTokenizer $tokenizer): array
    {
        $tags = $tokenizer->tokenize($card);
        $haystack = $this->haystack($card);
        /** @var array<string, int> $roleScores */
        $roleScores = [];
        $reasons = [];

        foreach ($strategy['roles'] as $role => $rules) {
            $score = 0;
            foreach ($rules['tags'] as $tag) {
                if (in_array($tag, $tags, true)) {
                    $score += 2;
                    $reasons[] = $tag;
                }
            }
            foreach ($rules['needles'] as $needle) {
                if ($this->matchesNeedle($haystack, $needle)) {
                    // Oracle-text needles are more specific than theme tags.
                    $score += 3;
                    $reasons[] = $needle;
                }
            }
            if ($score > 0) {
                $roleScores[$role] = $score;
            }
        }

        // Optional type hints (e.g. proliferate: permanents → enabler, spells → fuel).
        $cardType = $this->primaryCardType($card);
        $typeHints = $strategy['roleTypeHints'] ?? [];
        if (is_array($typeHints) && [] !== $roleScores) {
            foreach ($typeHints as $role => $types) {
                if (!isset($roleScores[$role])) {
                    continue;
                }
                if (in_array($cardType, $types, true)) {
                    $roleScores[$role] += 4;
                } else {
                    // Soft-penalize mismatched types so Contagion Engine (artifact)
                    // beats Contentious Plan (sorcery) for the enabler slot.
                    $roleScores[$role] = max(1, $roleScores[$role] - 2);
                }
            }
        }

        // Finisher signals (infect/toxic/poison/win) should headline as payoff
        // even when the same card also proliferates or places counters.
        if (isset($roleScores[self::ROLE_PAYOFF])) {
            foreach (['infect', 'toxic', 'poison', 'win the game'] as $finisher) {
                if (in_array($finisher, $tags, true) || in_array($finisher, $reasons, true)) {
                    $roleScores[self::ROLE_PAYOFF] += 8;
                    break;
                }
            }
        }

        // Staple support: ramp/draw/removal always fill the support role when
        // they did not already claim a strategy-specific role.
        $supportTags = ['ramp', 'dork', 'draw', 'removal', 'board_wipe'];
        $isLand = str_contains(strtolower($card->getTypeLine() ?? ''), 'land');
        if ($isLand) {
            $roleScores[self::ROLE_SUPPORT] = ($roleScores[self::ROLE_SUPPORT] ?? 0) + 1;
            $reasons[] = 'land';
        } elseif ([] === $roleScores) {
            foreach ($supportTags as $tag) {
                if (in_array($tag, $tags, true)) {
                    $roleScores[self::ROLE_SUPPORT] = 1;
                    $reasons[] = $tag;
                    break;
                }
            }
        }

        if ([] === $roleScores) {
            return ['primary' => self::ROLE_SUPPORT, 'roles' => [self::ROLE_SUPPORT], 'reasons' => []];
        }

        // Stronger match wins; tie-break payoff > enabler > fuel > support.
        $priority = [
            self::ROLE_PAYOFF => 4,
            self::ROLE_ENABLER => 3,
            self::ROLE_FUEL => 2,
            self::ROLE_SUPPORT => 1,
        ];
        $roleHits = array_keys($roleScores);
        usort(
            $roleHits,
            static function (string $a, string $b) use ($roleScores, $priority): int {
                $byScore = ($roleScores[$b] ?? 0) <=> ($roleScores[$a] ?? 0);
                if (0 !== $byScore) {
                    return $byScore;
                }

                return ($priority[$b] ?? 0) <=> ($priority[$a] ?? 0);
            },
        );

        return [
            'primary' => $roleHits[0],
            'roles' => $roleHits,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    public function primaryCardType(Card $card): string
    {
        $type = strtolower($card->getTypeLine() ?? '');
        // Lands first — "Artifact Land" should bucket as land for deckbuilding.
        foreach (['land', 'creature', 'planeswalker', 'enchantment', 'instant', 'sorcery', 'artifact'] as $bucket) {
            if (str_contains($type, $bucket)) {
                return $bucket;
            }
        }

        return 'other';
    }

    private function haystack(Card $card): string
    {
        return strtolower(trim(implode("\n", array_filter([
            $card->getTypeLine() ?? '',
            $card->getOracleText() ?? '',
            implode(' ', $card->getKeywords() ?? []),
            $card->getName(),
        ]))));
    }

    private function matchesNeedle(string $haystack, string $needle): bool
    {
        $needle = strtolower($needle);
        // Treat patterns with regex metacharacters as regex; else substring.
        if (str_contains($needle, '.*') || str_contains($needle, '\\')) {
            return false !== @preg_match('/'.$needle.'/i', $haystack)
                && 1 === preg_match('/'.$needle.'/i', $haystack);
        }

        return str_contains($haystack, $needle);
    }
}
