import { useSearchParams } from 'react-router'

/*
 * Filters kept in the URL query string (?role=operator&search=ana&page=2),
 * so they survive a refresh and the back button works.
 *
 *   const { values, params, update } = useQueryFilters(['role', 'search'])
 *   values.role   → current value ('' when absent)
 *   params        → only the non-empty values, plus page: ready to send to the API
 *   update('role', 'admin') → changes the URL (and resets to page 1)
 */
export function useQueryFilters<K extends string>(keys: readonly K[]) {
  const [searchParams, setSearchParams] = useSearchParams()

  const values = Object.fromEntries(keys.map((key) => [key, searchParams.get(key) ?? ''])) as Record<K, string>
  const page = searchParams.get('page') ?? '1'
  const params: Record<string, string> = { page }
  for (const key of keys) if (values[key]) params[key] = values[key]

  function update(key: K | 'page', value: string) {
    setSearchParams((current) => {
      const next = new URLSearchParams(current)
      if (value) next.set(key, value)
      else next.delete(key)
      if (key !== 'page') next.delete('page')
      return next
    })
  }

  /** The filters without the page, for export links. */
  const filterQuery = new URLSearchParams(Object.entries(params).filter(([key]) => key !== 'page')).toString()

  return { values, params, update, filterQuery, setPage: (p: number) => update('page', String(p)) }
}
