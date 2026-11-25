import { formatDateRange } from '@/lib/dates'
import type { Tour } from '@/lib/types'

/**
 * "12 Dec – 13 Dec · + 3 more" for tour tables (operator and admin lists).
 * Needs the tour's `upcoming_departures`, which those endpoints load.
 */
export function NextDeparture({ tour }: { tour: Tour }) {
  const next = tour.next_departure
  if (!next) return <span className="text-stone-400">No upcoming dates</span>

  const more = (tour.upcoming_departures?.length ?? 1) - 1

  return (
    <>
      {formatDateRange(next.starts_on, next.ends_on)}
      {more > 0 && <span className="block text-xs text-stone-500">+ {more} more date{more > 1 ? 's' : ''}</span>}
    </>
  )
}
