import { Link } from 'react-router'
import { StarRating } from '@/components/StarRating'
import { formatDateRange } from '@/lib/dates'
import { formatMoney } from '@/lib/money'
import type { Tour } from '@/lib/types'
import { TourImage } from './TourImage'

export function TourCard({ tour }: { tour: Tour }) {
  const fewSpotsLeft = tour.available_spots > 0 && tour.available_spots <= 5

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm transition-shadow hover:shadow-md">
      <TourImage tour={tour} className="aspect-[16/10] w-full" />
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
          {formatDateRange(tour.starts_on, tour.ends_on)} · {tour.duration_days} day{tour.duration_days > 1 ? 's' : ''}
        </p>
        <StarRating value={tour.rating_average} count={tour.reviews_count} className="mt-2" />
        <div className="mt-auto flex items-end justify-between pt-4">
          <p>
            <span className="text-lg font-semibold text-stone-900">{formatMoney(tour.price_cents)}</span>
            <span className="text-sm text-stone-500"> / person</span>
          </p>
          <p className={fewSpotsLeft ? 'text-sm font-medium text-amber-700' : 'text-sm text-stone-500'}>
            {tour.available_spots === 0 ? 'Sold out' : `${tour.available_spots} spots left`}
          </p>
        </div>
      </div>
    </article>
  )
}
