import {ClipboardList, Crown, GalleryHorizontalEnd, Search, WalletCards} from "lucide-react";

type FinishFilter = 'all' | 'foil' | 'nonfoil'
type SortKey = 'featured' | 'price-desc' | 'price-asc' | 'name' | 'newest'
type ViewMode = 'grid' | 'list'

const DEFAULT_SPOTLIGHT_MIN_PRICE_CENTS = 1000
const SPOTLIGHT_MAX_ITEMS = 12
const RESULTS_PAGE_SIZE = 24

const COLORS = [
    { key: 'W', label: 'White', dark: true },
    { key: 'U', label: 'Blue', dark: false },
    { key: 'B', label: 'Black', dark: false },
    { key: 'R', label: 'Red', dark: false },
    { key: 'G', label: 'Green', dark: false },
    { key: 'C', label: 'Colorless', dark: true },
] as const

// Primary card types offered by the storefront type-line filter.
const CARD_TYPES = [
    'Creature',
    'Planeswalker',
    'Instant',
    'Sorcery',
    'Artifact',
    'Enchantment',
    'Battle',
    'Land',
] as const

const FINISH_OPTIONS: { key: FinishFilter; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'nonfoil', label: 'Nonfoil' },
    { key: 'foil', label: 'Foil' },
]

const SORTS: { value: SortKey; label: string }[] = [
    { value: 'featured', label: 'Featured' },
    { value: 'price-desc', label: 'Price: High to Low' },
    { value: 'price-asc', label: 'Price: Low to High' },
    { value: 'name', label: 'Name: A–Z' },
    { value: 'newest', label: 'Newest' },
]

// Themed shortcut tiles shown above the spotlight. Entries with a `path` link
// to a store-relative page; the rest are placeholders until their destinations
// are built.
type QuickAction = { label: string; icon: typeof Search; path?: string; action?: 'search' }

const QUICK_ACTIONS: QuickAction[] = [
    { label: 'Search Cards', icon: Search, action: 'search' },
    { label: 'Case cards', icon: GalleryHorizontalEnd, path: 'case-cards' },
    { label: 'Mass Search', icon: ClipboardList, path: 'mass-search' },
    { label: 'Deck Builder', icon: Crown, path: 'deck-builder' },
    { label: 'Sell/Trade', icon: WalletCards, path: 'sell' },
]

export {QUICK_ACTIONS, SORTS, CARD_TYPES, FINISH_OPTIONS, COLORS, DEFAULT_SPOTLIGHT_MIN_PRICE_CENTS,SPOTLIGHT_MAX_ITEMS, RESULTS_PAGE_SIZE};
export type { ViewMode,SortKey,FinishFilter };
