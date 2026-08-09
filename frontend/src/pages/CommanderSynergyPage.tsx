import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router'
import {
  ArrowLeft,
  Check,
  Crown,
  Fuel,
  Gem,
  Layers,
  Search,
  ShoppingCart,
  Sparkles,
  Zap,
} from 'lucide-react'
import { cardImage, formatPrice } from '../api/client'
import type { InventoryItem } from '../api/types'
import { useAuth } from '../context/AuthContext'
import {
  useCart,
  useCommanderRecommendations,
  useCommanderSearch,
  useCommanderStrategies,
  useStore,
  useStoreTheme,
} from '../hooks'
import type { CommanderRecommendation, CommanderSummary, DeckCardType, DeckRole } from '../hooks'
import { Button, buttonVariants, EmptyState, Input } from '../components/ui'
import { CardImage } from '../components/cards'
import { StorePageLoader } from '../components/store/StorePageLoader'
import { ManaSymbol } from '../components/mtg/ManaSymbol'
import { finishName } from '../lib/finishes'
import { cx } from '../lib/cx'

const ROLE_META: Record<DeckRole, { label: string; blurb: string; icon: typeof Zap }> = {
  enabler: {
    label: 'Enablers (Engine)',
    blurb: 'Pieces that start or assemble the strategy.',
    icon: Zap,
  },
  fuel: {
    label: 'Fuel (Intermediary)',
    blurb: 'Cards that keep the engine running turn after turn.',
    icon: Fuel,
  },
  payoff: {
    label: 'Payoffs (Reward)',
    blurb: 'Cards that convert the strategy into wins and value.',
    icon: Gem,
  },
  support: {
    label: 'Support & Staples',
    blurb: 'Ramp, draw, interaction, and lands that round out the list.',
    icon: Layers,
  },
}

const TYPE_ORDER: DeckCardType[] = [
  'creature',
  'enchantment',
  'instant',
  'sorcery',
  'artifact',
  'land',
  'planeswalker',
  'other',
]

const TYPE_LABELS: Record<DeckCardType, string> = {
  creature: 'Creatures',
  enchantment: 'Enchantments',
  instant: 'Instants',
  sorcery: 'Sorceries',
  artifact: 'Artifacts',
  land: 'Lands',
  planeswalker: 'Planeswalkers',
  other: 'Other',
}

function colorPips(identity: string[] | undefined) {
  const colors = identity && identity.length > 0 ? identity : ['C']
  return (
    <span className="inline-flex items-center gap-0.5">
      {colors.map((c) => (
        <ManaSymbol key={c} symbol={c} className="size-4" />
      ))}
    </span>
  )
}

function RecRow({
  row,
  slug,
  signedIn,
  inCart,
  checked,
  disabledPick,
  adding,
  onToggle,
  onAdd,
}: {
  row: CommanderRecommendation
  slug: string
  signedIn: boolean
  inCart: number
  checked: boolean
  disabledPick: boolean
  adding: boolean
  onToggle: () => void
  onAdd: () => void
}) {
  const item = row.inventoryItem
  return (
    <li className="flex gap-3 rounded-card border border-border bg-surface p-3 shadow-sm">
      <label className="flex shrink-0 items-start pt-1">
        <input
          type="checkbox"
          className="size-4 rounded border-border text-brand-600 focus:ring-brand-500"
          checked={checked}
          disabled={disabledPick}
          onChange={onToggle}
          aria-label={`Select ${item.card.name}`}
        />
      </label>
      <Link to={`/s/${slug}/cards/${item.id}`} className="w-14 shrink-0 sm:w-16">
        <CardImage src={cardImage(item.card)} alt={item.card.name} />
      </Link>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div className="min-w-0">
            <Link
              to={`/s/${slug}/cards/${item.id}`}
              className="font-display text-sm font-extrabold text-fg hover:text-brand-600 sm:text-base"
            >
              {item.card.name}
            </Link>
            <p className="text-xs text-fg-muted">
              {item.card.typeLine}
              {' · '}
              {item.condition} / {finishName(item.card, item.isFoil, item.finish)}
              {' · '}
              {item.quantity} in stock
            </p>
          </div>
          <div className="text-right">
            <p className="font-display text-base font-extrabold text-fg">{formatPrice(item.priceCents)}</p>
            <p className="text-[0.65rem] font-semibold uppercase tracking-wide text-fg-muted">
              {row.role} · {(row.score * 100).toFixed(0)}
            </p>
          </div>
        </div>
        {row.reasons.length > 0 && (
          <div className="mt-2 flex flex-wrap gap-1.5">
            {row.reasons.slice(0, 6).map((reason) => (
              <span
                key={reason}
                className="rounded-full bg-bg px-2 py-0.5 text-[0.65rem] font-semibold text-fg-muted"
              >
                {reason.replaceAll('_', ' ')}
              </span>
            ))}
          </div>
        )}
        <div className="mt-3">
          {!signedIn ? (
            <Link to="/login" className={buttonVariants({ variant: 'secondary', size: 'sm' })}>
              Sign in to add
            </Link>
          ) : inCart > 0 ? (
            <Link to={`/s/${slug}/cart`} className={buttonVariants({ variant: 'secondary', size: 'sm' })}>
              <Check aria-hidden className="size-4" />
              In cart ({inCart})
            </Link>
          ) : (
            <Button size="sm" loading={adding} onClick={onAdd}>
              <ShoppingCart aria-hidden className="size-4" />
              Add to cart
            </Button>
          )}
        </div>
      </div>
    </li>
  )
}

export default function CommanderSynergyPage() {
  const { slug = '' } = useParams()
  const { user } = useAuth()
  const signedIn = Boolean(user)
  const { data: store, isLoading: storeLoading } = useStore(slug)
  useStoreTheme(store)

  const [query, setQuery] = useState('')
  const [selected, setSelected] = useState<CommanderSummary | null>(null)
  const [strategyId, setStrategyId] = useState<string | null>(null)
  const [view, setView] = useState<'roles' | 'types'>('roles')
  const [picked, setPicked] = useState<Set<number>>(() => new Set())
  const [bulkBusy, setBulkBusy] = useState(false)
  const [bulkDone, setBulkDone] = useState(false)

  const search = useCommanderSearch(slug, query)
  const strategiesQuery = useCommanderStrategies(slug, selected?.id ?? null)
  const recommend = useCommanderRecommendations(slug, selected?.id ?? null, strategyId)
  const cart = useCart(slug, signedIn)
  const cartData = cart.query.data
  const recommendations = recommend.data?.recommendations ?? []

  // Auto-select the top supported strategy when commander strategies load.
  useEffect(() => {
    if (!selected) {
      setStrategyId(null)
      return
    }
    const list = strategiesQuery.data
    if (!list || list.length === 0) return
    setStrategyId((current) => {
      if (current && list.some((s) => s.id === current)) return current
      return list[0].id
    })
  }, [selected, strategiesQuery.data])

  const cartQtyByInventoryId = useMemo(() => {
    const map = new Map<number, number>()
    for (const line of cartData ?? []) {
      if (line.inventoryItem?.id) map.set(line.inventoryItem.id, line.quantity)
    }
    return map
  }, [cartData])

  const selectableIds = recommendations
    .map((row) => row.inventoryItem.id)
    .filter((id) => !cartQtyByInventoryId.has(id))

  const allSelected = selectableIds.length > 0 && selectableIds.every((id) => picked.has(id))

  function togglePick(id: number) {
    setPicked((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
    setBulkDone(false)
  }

  function toggleSelectAll() {
    setPicked((current) => {
      if (selectableIds.every((id) => current.has(id))) return new Set()
      return new Set(selectableIds)
    })
    setBulkDone(false)
  }

  async function addOne(item: InventoryItem) {
    if (!signedIn) return
    const inCart = cartQtyByInventoryId.get(item.id) ?? 0
    await cart.setItem.mutateAsync({ item, quantity: Math.min(item.quantity, inCart + 1) })
  }

  async function addSelectedEnMasse() {
    if (!signedIn || picked.size === 0) return
    setBulkBusy(true)
    setBulkDone(false)
    try {
      const rows = recommendations.filter((row) => picked.has(row.inventoryItem.id))
      for (const row of rows) {
        const item = row.inventoryItem
        const inCart = cartQtyByInventoryId.get(item.id) ?? 0
        await cart.setItem.mutateAsync({ item, quantity: Math.min(item.quantity, Math.max(1, inCart + 1)) })
      }
      setPicked(new Set())
      setBulkDone(true)
    } finally {
      setBulkBusy(false)
    }
  }

  function renderRows(rows: CommanderRecommendation[]) {
    return (
      <ul className="space-y-3">
        {rows.map((row) => {
          const item = row.inventoryItem
          const inCart = cartQtyByInventoryId.get(item.id) ?? 0
          return (
            <RecRow
              key={item.id}
              row={row}
              slug={slug}
              signedIn={signedIn}
              inCart={inCart}
              checked={picked.has(item.id)}
              disabledPick={inCart > 0}
              adding={cart.setItem.isPending}
              onToggle={() => togglePick(item.id)}
              onAdd={() => void addOne(item)}
            />
          )
        })}
      </ul>
    )
  }

  if (storeLoading || !store) {
    return <StorePageLoader />
  }

  const byRole = recommend.data?.byRole
  const byType = recommend.data?.byType

  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <Link
        to={`/s/${slug}`}
        className="inline-flex items-center gap-1.5 text-sm font-medium text-fg-muted hover:text-brand-600"
      >
        <ArrowLeft aria-hidden className="size-4" />
        Back to {store.name}
      </Link>

      <header className="mt-4">
        <p className="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-brand-600">
          <Crown aria-hidden className="size-3.5" />
          Commander deck builder
        </p>
        <h1 className="mt-1 font-display text-3xl font-extrabold tracking-tight text-fg">Deck Builder</h1>
        <p className="mt-2 max-w-3xl text-sm text-fg-muted">
          Pick a commander, choose the strategy it supports, then build from in-stock
          <span className="font-semibold text-fg"> enablers</span>,
          <span className="font-semibold text-fg"> fuel</span>, and
          <span className="font-semibold text-fg"> payoffs</span> — sorted by card type for a complete focused list.
        </p>
      </header>

      <section className="mt-8 grid gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <div className="space-y-4">
          <label className="block">
            <span className="mb-1.5 block text-sm font-semibold text-fg">1. Search commanders</span>
            <div className="relative">
              <Search aria-hidden className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-fg-muted" />
              <Input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="e.g. Atraxa, Krenko…"
                className="pl-9"
                autoComplete="off"
              />
            </div>
          </label>

          {search.isFetching && query.trim().length >= 2 && (
            <p className="text-sm text-fg-muted">Searching catalog…</p>
          )}

          {search.data && search.data.length > 0 && (
            <ul className="overflow-hidden rounded-card border border-border bg-surface shadow-sm">
              {search.data.map((commander) => {
                const active = selected?.id === commander.id
                return (
                  <li key={commander.id} className="border-b border-border last:border-b-0">
                    <button
                      type="button"
                      onClick={() => {
                        setSelected(commander)
                        setStrategyId(null)
                        setPicked(new Set())
                        setBulkDone(false)
                      }}
                      className={cx(
                        'flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors',
                        active ? 'bg-brand-50' : 'hover:bg-bg',
                      )}
                    >
                      <div className="h-14 w-10 shrink-0 overflow-hidden rounded-md bg-bg">
                        {commander.imageUrl ? (
                          <img src={commander.imageUrl} alt="" className="h-full w-full object-cover" />
                        ) : null}
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-semibold text-fg">{commander.name}</p>
                        <p className="truncate text-xs text-fg-muted">{commander.typeLine}</p>
                        <div className="mt-1">{colorPips(commander.colorIdentity)}</div>
                      </div>
                    </button>
                  </li>
                )
              })}
            </ul>
          )}

          {selected && (
            <div className="rounded-card border border-border bg-surface p-4 shadow-sm">
              <div className="flex gap-3">
                <div className="w-20 shrink-0">
                  <CardImage src={selected.imageUrl} alt={selected.name} />
                </div>
                <div className="min-w-0">
                  <p className="font-display text-lg font-extrabold text-fg">{selected.name}</p>
                  <p className="text-sm text-fg-muted">{selected.typeLine}</p>
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    {colorPips(selected.colorIdentity)}
                    {recommend.data?.identityCode && (
                      <span className="rounded-full bg-bg px-2 py-0.5 text-xs font-semibold text-fg-muted">
                        {recommend.data.identityCode}
                      </span>
                    )}
                  </div>
                </div>
              </div>
            </div>
          )}

          {selected && (
            <div className="space-y-2">
              <p className="text-sm font-semibold text-fg">2. Pick a strategy</p>
              {strategiesQuery.isLoading && <p className="text-sm text-fg-muted">Detecting strategies…</p>}
              <div className="space-y-2">
                {(strategiesQuery.data ?? []).map((strategy) => {
                  const active = strategyId === strategy.id
                  return (
                    <button
                      key={strategy.id}
                      type="button"
                      onClick={() => {
                        setStrategyId(strategy.id)
                        setPicked(new Set())
                        setBulkDone(false)
                      }}
                      className={cx(
                        'w-full rounded-card border px-3 py-3 text-left transition-colors',
                        active
                          ? 'border-brand-500 bg-brand-50 shadow-sm'
                          : 'border-border bg-surface hover:border-brand-300',
                      )}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <p className="font-semibold text-fg">{strategy.label}</p>
                        <span className="shrink-0 rounded-full bg-bg px-2 py-0.5 text-[0.65rem] font-bold uppercase tracking-wide text-fg-muted">
                          {Math.round(strategy.confidence * 100)}%
                        </span>
                      </div>
                      <p className="mt-1 text-xs text-fg-muted">{strategy.description}</p>
                      {strategy.matchedSignals.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-1">
                          {strategy.matchedSignals.slice(0, 4).map((signal) => (
                            <span
                              key={signal}
                              className="rounded-full bg-bg px-2 py-0.5 text-[0.65rem] font-semibold text-fg-muted"
                            >
                              {signal.replaceAll('_', ' ')}
                            </span>
                          ))}
                        </div>
                      )}
                    </button>
                  )
                })}
              </div>
            </div>
          )}
        </div>

        <div className="min-w-0">
          {!selected ? (
            <EmptyState
              icon={Sparkles}
              title="Choose a commander"
              description="Search the local commanders catalog, then pick a strategy that commander supports. We'll supply enablers, fuel, and payoffs from this store's inventory."
            />
          ) : !strategyId || strategiesQuery.isLoading ? (
            <p className="text-sm text-fg-muted">Waiting for strategy selection…</p>
          ) : recommend.isLoading ? (
            <p className="text-sm text-fg-muted">Building your {recommend.data?.strategy.label ?? 'strategy'} package…</p>
          ) : recommendations.length === 0 ? (
            <EmptyState
              icon={Search}
              title="No in-stock package yet"
              description="This store doesn't currently stock enough cards for that strategy. Try another strategy, or ask the store to sync more Magic inventory."
            />
          ) : (
            <>
              <div className="mb-4 flex flex-col gap-3 rounded-card border border-border bg-surface p-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="text-sm font-semibold text-fg">
                    {recommend.data?.strategy.label}: {recommendations.length} in-stock cards
                  </p>
                  <p className="text-xs text-fg-muted">
                    From {recommend.data?.totalCandidates ?? recommendations.length} color-legal candidates · grouped by{' '}
                    {view === 'roles' ? 'role' : 'card type'}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <div className="inline-flex rounded-btn border border-border p-0.5">
                    <button
                      type="button"
                      onClick={() => setView('roles')}
                      className={cx(
                        'rounded-btn px-2.5 py-1 text-xs font-semibold',
                        view === 'roles' ? 'bg-brand-500 text-white' : 'text-fg-muted',
                      )}
                    >
                      By role
                    </button>
                    <button
                      type="button"
                      onClick={() => setView('types')}
                      className={cx(
                        'rounded-btn px-2.5 py-1 text-xs font-semibold',
                        view === 'types' ? 'bg-brand-500 text-white' : 'text-fg-muted',
                      )}
                    >
                      By type
                    </button>
                  </div>
                  <Button type="button" variant="secondary" size="sm" onClick={toggleSelectAll}>
                    {allSelected ? 'Clear selection' : 'Select all'}
                  </Button>
                  {!signedIn ? (
                    <Link to="/login" className={buttonVariants({ size: 'sm' })}>
                      Sign in to add
                    </Link>
                  ) : (
                    <Button
                      type="button"
                      size="sm"
                      loading={bulkBusy}
                      disabled={bulkBusy || picked.size === 0}
                      onClick={() => void addSelectedEnMasse()}
                    >
                      <ShoppingCart aria-hidden className="size-4" />
                      Add {picked.size || ''} to cart
                    </Button>
                  )}
                  {signedIn && (
                    <Link to={`/s/${slug}/cart`} className={buttonVariants({ variant: 'secondary', size: 'sm' })}>
                      View cart
                    </Link>
                  )}
                </div>
              </div>

              {bulkDone && (
                <p className="mb-3 flex items-center gap-1.5 text-sm font-medium text-success-700">
                  <Check aria-hidden className="size-4" />
                  Selected cards added to your cart.
                </p>
              )}

              {view === 'roles' && byRole && (
                <div className="space-y-8">
                  {(['enabler', 'fuel', 'payoff', 'support'] as DeckRole[]).map((role) => {
                    const rows = byRole[role] ?? []
                    if (rows.length === 0) return null
                    const meta = ROLE_META[role]
                    const Icon = meta.icon
                    return (
                      <section key={role}>
                        <div className="mb-3 flex items-start gap-2">
                          <span className="mt-0.5 grid size-8 place-items-center rounded-full bg-brand-50 text-brand-700">
                            <Icon aria-hidden className="size-4" />
                          </span>
                          <div>
                            <h2 className="font-display text-lg font-extrabold text-fg">{meta.label}</h2>
                            <p className="text-xs text-fg-muted">
                              {meta.blurb} · {rows.length} card{rows.length === 1 ? '' : 's'}
                            </p>
                          </div>
                        </div>
                        {renderRows(rows)}
                      </section>
                    )
                  })}
                </div>
              )}

              {view === 'types' && byType && (
                <div className="space-y-8">
                  {TYPE_ORDER.map((type) => {
                    const rows = byType[type] ?? []
                    if (rows.length === 0) return null
                    return (
                      <section key={type}>
                        <h2 className="mb-3 font-display text-lg font-extrabold text-fg">
                          {TYPE_LABELS[type]}
                          <span className="ml-2 text-sm font-semibold text-fg-muted">({rows.length})</span>
                        </h2>
                        {renderRows(rows)}
                      </section>
                    )
                  })}
                </div>
              )}
            </>
          )}
        </div>
      </section>
    </div>
  )
}
