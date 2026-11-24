import { useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { SelectField, TextField } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Pagination } from '@/components/ui/Pagination'
import { PageSpinner } from '@/components/ui/Spinner'
import { parseApiError } from '@/lib/api'
import { centsToDollarInput, parseDollarsToCents } from '@/lib/money'
import { TourCard } from './TourCard'
import { useReferenceData, useTours, type TourFilters } from './queries'

/*
 * Duration buckets. Each maps to a min/max number of days that the API
 * filters on (duration_days is computed by Postgres from the tour dates).
 * The ranges touch without gaps: a 3-day tour is in "1-3", a 4-day tour in
 * "4-7". The original project had holes between buckets and mixed hours
 * with days.
 */
const DURATIONS = [
  { value: '1-3', label: '1–3 days', min: '1', max: '3' },
  { value: '4-7', label: '4–7 days', min: '4', max: '7' },
  { value: '8-14', label: '8–14 days', min: '8', max: '14' },
  { value: '15+', label: '15+ days', min: '15', max: undefined },
] as const

const FILTER_KEYS = ['search', 'region_id', 'city_id', 'category_id', 'max_price_cents', 'min_days', 'max_days', 'min_rating', 'sort', 'page'] as const

/*
 * The filters live in the URL query string (?city_id=3&sort=price_asc), not
 * in React state. Benefits: the back button works, a filtered list can be
 * bookmarked or shared, and a page refresh keeps the filters. The URL is the
 * state; the form just reads from it and writes to it.
 */
export function ToursPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const filters: TourFilters = {}
  for (const key of FILTER_KEYS) {
    const value = searchParams.get(key)
    if (value) filters[key] = value
  }

  const tours = useTours({ ...filters, per_page: '12' })
  const reference = useReferenceData()

  // Changing any filter returns to page 1.
  function update(changes: Partial<Record<(typeof FILTER_KEYS)[number], string | undefined>>) {
    setSearchParams((current) => {
      const next = new URLSearchParams(current)
      for (const [key, value] of Object.entries(changes)) {
        if (value) next.set(key, value)
        else next.delete(key)
      }
      if (!('page' in changes)) next.delete('page')
      return next
    })
  }

  const selectedRegion = reference.data?.regions.find((region) => String(region.id) === filters.region_id)
  const cities = selectedRegion?.cities ?? reference.data?.regions.flatMap((region) => region.cities ?? []) ?? []
  const durationValue = DURATIONS.find((d) => d.min === filters.min_days && (d.max ?? undefined) === filters.max_days)?.value ?? ''

  return (
    <div className="mx-auto max-w-6xl px-4 py-10">
      <PageHeader title="Tours" description="Guided trips across Lebanon, from Roman temples to cedar forests." />

      <div className="grid gap-8 lg:grid-cols-[17rem_1fr]">
        <aside aria-label="Filters" className="space-y-4">
          <SearchBox key={`search-${filters.search ?? ''}`} initial={filters.search ?? ''} onSearch={(search) => update({ search })} />

          <SelectField
            label="Region"
            value={filters.region_id ?? ''}
            // A city belongs to one region: changing the region resets the city.
            onChange={(event) => update({ region_id: event.target.value, city_id: undefined })}
          >
            <option value="">All regions</option>
            {reference.data?.regions.map((region) => (
              <option key={region.id} value={region.id}>{region.name}</option>
            ))}
          </SelectField>

          <SelectField label="City" value={filters.city_id ?? ''} onChange={(event) => update({ city_id: event.target.value })}>
            <option value="">All cities</option>
            {cities.map((city) => (
              <option key={city.id} value={city.id}>{city.name}</option>
            ))}
          </SelectField>

          <SelectField label="Category" value={filters.category_id ?? ''} onChange={(event) => update({ category_id: event.target.value })}>
            <option value="">All categories</option>
            {reference.data?.categories.map((category) => (
              <option key={category.id} value={category.id}>{category.name}</option>
            ))}
          </SelectField>

          <SelectField
            label="Duration"
            value={durationValue}
            onChange={(event) => {
              const bucket = DURATIONS.find((d) => d.value === event.target.value)
              update({ min_days: bucket?.min, max_days: bucket?.max })
            }}
          >
            <option value="">Any duration</option>
            {DURATIONS.map((d) => (
              <option key={d.value} value={d.value}>{d.label}</option>
            ))}
          </SelectField>

          <MaxPriceBox
            key={`price-${filters.max_price_cents ?? ''}`}
            initialCents={filters.max_price_cents ? Number(filters.max_price_cents) : null}
            onApply={(cents) => update({ max_price_cents: cents === null ? undefined : String(cents) })}
          />

          <SelectField label="Rating" value={filters.min_rating ?? ''} onChange={(event) => update({ min_rating: event.target.value })}>
            <option value="">Any rating</option>
            <option value="3">3 stars and up</option>
            <option value="4">4 stars and up</option>
            <option value="4.5">4.5 stars and up</option>
          </SelectField>

          <Button variant="ghost" className="w-full" onClick={() => setSearchParams(new URLSearchParams())}>
            Clear all filters
          </Button>
        </aside>

        <section aria-label="Results" aria-busy={tours.isFetching}>
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-stone-600">
              {tours.data ? `${tours.data.meta.total} tour${tours.data.meta.total === 1 ? '' : 's'} found` : ' '}
            </p>
            <label className="flex items-center gap-2 text-sm text-stone-600">
              Sort by
              <select
                className="rounded-lg border border-stone-300 bg-white px-2 py-1.5 text-sm"
                value={filters.sort ?? 'soonest'}
                onChange={(event) => update({ sort: event.target.value === 'soonest' ? undefined : event.target.value })}
              >
                <option value="soonest">Soonest departure</option>
                <option value="price_asc">Price: low to high</option>
                <option value="price_desc">Price: high to low</option>
                <option value="rating">Best rated</option>
              </select>
            </label>
          </div>

          {tours.isPending ? (
            <PageSpinner />
          ) : tours.isError ? (
            <Alert tone="error">{parseApiError(tours.error).message}</Alert>
          ) : tours.data.data.length === 0 ? (
            <EmptyState title="No tours match these filters" description="Try another region or a wider price range." />
          ) : (
            <>
              <div className={`grid gap-6 sm:grid-cols-2 xl:grid-cols-3 ${tours.isPlaceholderData ? 'opacity-60' : ''}`}>
                {tours.data.data.map((tour) => (
                  <TourCard key={tour.id} tour={tour} />
                ))}
              </div>
              <Pagination
                meta={tours.data.meta}
                onPageChange={(page) => {
                  update({ page: String(page) })
                  window.scrollTo({ top: 0, behavior: 'smooth' })
                }}
              />
            </>
          )}
        </section>
      </div>
    </div>
  )
}

/** Typing does not hit the API on every key press: the search runs on submit. */
function SearchBox({ initial, onSearch }: { initial: string; onSearch: (value: string) => void }) {
  const [value, setValue] = useState(initial)

  function submit(event: FormEvent) {
    event.preventDefault()
    onSearch(value.trim())
  }

  return (
    <form role="search" onSubmit={submit} className="space-y-2">
      <TextField label="Search" type="search" placeholder="Byblos, wine, hiking…" value={value} onChange={(event) => setValue(event.target.value)} />
      <Button type="submit" variant="secondary" size="sm" className="w-full">
        Search
      </Button>
    </form>
  )
}

function MaxPriceBox({ initialCents, onApply }: { initialCents: number | null; onApply: (cents: number | null) => void }) {
  const [value, setValue] = useState(initialCents === null ? '' : centsToDollarInput(initialCents))
  const [error, setError] = useState<string>()

  function submit(event: FormEvent) {
    event.preventDefault()
    if (value.trim() === '') {
      setError(undefined)
      onApply(null)
      return
    }
    const cents = parseDollarsToCents(value)
    if (cents === null) {
      setError('Enter an amount like 150 or 99.50')
      return
    }
    setError(undefined)
    onApply(cents)
  }

  return (
    <form onSubmit={submit} className="space-y-2">
      <TextField label="Max price per person (USD)" inputMode="decimal" placeholder="e.g. 150" value={value} onChange={(event) => setValue(event.target.value)} error={error} />
      <Button type="submit" variant="secondary" size="sm" className="w-full">
        Apply price
      </Button>
    </form>
  )
}
