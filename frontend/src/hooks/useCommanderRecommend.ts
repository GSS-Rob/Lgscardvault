import { useQuery } from '@tanstack/react-query'
import api from '../api/client'
import type { InventoryItem } from '../api/types'
import { useDebouncedValue } from './useDebouncedValue'

export interface CommanderSummary {
  id: string
  oracleId: string
  name: string
  typeLine?: string | null
  manaCost?: string | null
  cmc?: number | null
  colorIdentity?: string[]
  imageUrl?: string | null
  setCode?: string
  setName?: string | null
}

export type DeckRole = 'enabler' | 'fuel' | 'payoff' | 'support'
export type DeckCardType =
  | 'creature'
  | 'enchantment'
  | 'instant'
  | 'sorcery'
  | 'artifact'
  | 'land'
  | 'planeswalker'
  | 'other'

export interface CommanderStrategy {
  id: string
  label: string
  description: string
  confidence: number
  matchedSignals: string[]
}

export interface CommanderRecommendation {
  score: number
  role: DeckRole
  roles: DeckRole[]
  cardType: DeckCardType
  reasons: string[]
  inventoryItem: InventoryItem
}

export interface CommanderRecommendResponse {
  commander: CommanderSummary & { themes?: string[] }
  colorIdentity: string[]
  identityCode: string
  strategies: CommanderStrategy[]
  strategy: Pick<CommanderStrategy, 'id' | 'label' | 'description'>
  totalCandidates: number
  recommendations: CommanderRecommendation[]
  byRole: Record<DeckRole, CommanderRecommendation[]>
  byType: Record<DeckCardType, CommanderRecommendation[]>
}

export function commanderSearchKey(slug: string, q: string) {
  return ['commander-search', slug, q] as const
}

export function commanderStrategiesKey(slug: string, cardId: string) {
  return ['commander-strategies', slug, cardId] as const
}

export function commanderRecommendKey(slug: string, cardId: string, strategy: string) {
  return ['commander-recommend', slug, cardId, strategy] as const
}

/** Typeahead for legendary creature commanders at a store. */
export function useCommanderSearch(slug: string, query: string, enabled = true) {
  const debounced = useDebouncedValue(query.trim(), 250)
  return useQuery({
    queryKey: commanderSearchKey(slug, debounced),
    queryFn: async () => {
      const { data } = await api.get<CommanderSummary[]>(`/stores/${slug}/recommend/commanders`, {
        params: { q: debounced, limit: 12 },
      })
      return data
    },
    enabled: enabled && Boolean(slug) && debounced.length >= 2,
  })
}

/** Strategies this commander supports (for the picker). */
export function useCommanderStrategies(slug: string, cardId: string | null, enabled = true) {
  return useQuery({
    queryKey: commanderStrategiesKey(slug, cardId ?? ''),
    queryFn: async () => {
      const { data } = await api.get<{ strategies: CommanderStrategy[] }>(
        `/stores/${slug}/recommend/commander/${cardId}/strategies`,
      )
      return data.strategies
    },
    enabled: enabled && Boolean(slug) && Boolean(cardId),
  })
}

/** Strategy-scoped in-stock deck package for a selected commander. */
export function useCommanderRecommendations(
  slug: string,
  cardId: string | null,
  strategy: string | null,
  enabled = true,
) {
  return useQuery({
    queryKey: commanderRecommendKey(slug, cardId ?? '', strategy ?? ''),
    queryFn: async () => {
      const { data } = await api.get<CommanderRecommendResponse>(
        `/stores/${slug}/recommend/commander/${cardId}`,
        { params: { strategy: strategy || undefined, limit: 80 } },
      )
      return data
    },
    enabled: enabled && Boolean(slug) && Boolean(cardId) && Boolean(strategy),
  })
}
