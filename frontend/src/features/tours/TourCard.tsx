import { Link } from 'react-router'
import { StarRating } from '@/components/StarRating'
import { Badge } from '@/components/ui/Badge'
import { cn } from '@/lib/cn'
import { formatDateRange } from '@/lib/dates'
import { formatMoney } from '@/lib/money'
import type { Tour } from '@/lib/types'
import { TourImage } from './TourImage'

export function TourCard({ tour }: { tour: Tour }) {
  // "Sold out" means exactly "no spots left on any upcoming date". The server
  // decides it (is_sold_out); a tour without upcoming dates is not sold out,
  // and the public list does not return it anyway.
  const soldOut = tour.is_sold_out === true
  const departures = tour.upcoming_departures ?? []
  // The date shown is the first one a traveler can still book, so the date
  // and the "spots left" next to it always talk about the same departure.
  const shown = departures.find((departure) => departure.available_spots > 0) ?? tour.next_departure ?? null
  const otherDates = Math.max(0, departures.length - 1)
  const fewSpotsLeft = !soldOut && shown !== null && shown.available_spots <= 5

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm transition-shadow hover:shadow-md">
      <div className="relative">
        {/* Muted, but still a link: the detail page (itinerary, reviews) stays useful. */}
        <TourImage tour={tour} className={cn('aspect-[16/10] w-full', soldOut && 'opacity-60')} />
        {/* Real text, not colour alone, so screen readers announce it with the card. */}
        {soldOut && (
          <Badge tone="red" className="absolute right-3 top-3 shadow-sm">
            Sold out
          </Badge>
        )}
      </div>
      <div className="flex flex-1 flex-col p-4">
        <p className="text-xs font-medium uppercase tracking-wide text-brand-700">
          {tour.city?.name}
          {tour.city?.region && <span className="text-stone-400"> · {tour.city.region.name}</span>}
        </p>
        <h3 className="mt-1 text-base font-semibold text-stone-900">
          {/* The whole card is clickable through this link's ::after overlay. */}
          <Link to={`/tours/${tour.id}`} className="after:absolute after:inset-0 focus-visible:outline-none group-focus-within:underline">
            {tour.title}
          </Link>
        </h3>
        <p className="mt-1 text-sm text-stone-500">
          {shown ? formatDateRange(shown.starts_on, shown.ends_on) : 'No dates yet'} · {tour.duration_days} day{tour.duration_days > 1 ? 's' : ''}
          {otherDates > 0 && <span className="block">+ {otherDates} more date{otherDates > 1 ? 's' : ''}</span>}
        </p>
        <StarRating value={tour.rating_average} count={tour.reviews_count} className="mt-2" />
        <div className="mt-auto flex items-end justify-between pt-4">
          <p>
            <span className="text-lg font-semibold text-stone-900">{formatMoney(tour.price_cents)}</span>
            <span className="text-sm text-stone-500"> / person</span>
          </p>
          <p className={fewSpotsLeft ? 'text-sm font-medium text-amber-700' : 'text-sm text-stone-500'}>
            {soldOut ? 'Sold out' : shown ? `${shown.available_spots} spots left` : ''}
          </p>
        </div>
      </div>
    </article>
  )
}
