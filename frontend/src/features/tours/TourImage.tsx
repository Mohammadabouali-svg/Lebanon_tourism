import { cn } from '@/lib/cn'
import type { Tour } from '@/lib/types'

// Deterministic placeholder colours: the same tour always gets the same one.
const gradients = [
  'from-brand-800 via-brand-600 to-sea-500',
  'from-sea-700 via-sea-500 to-brand-300',
  'from-amber-700 via-orange-500 to-rose-400',
  'from-stone-700 via-brand-800 to-brand-500',
  'from-rose-700 via-amber-500 to-yellow-300',
]

/** The tour photo, or a gradient with the category name when there is none. */
export function TourImage({ tour, className }: { tour: Pick<Tour, 'id' | 'title' | 'image_url' | 'category'>; className?: string }) {
  if (tour.image_url) {
    return <img src={tour.image_url} alt={tour.title} loading="lazy" className={cn('object-cover', className)} />
  }

  return (
    <div
      role="img"
      aria-label={tour.title}
      className={cn('flex items-end bg-linear-to-br p-4', gradients[tour.id % gradients.length], className)}
    >
      <span className="rounded-full bg-black/20 px-3 py-1 text-xs font-medium text-white backdrop-blur-sm">
        {tour.category?.name ?? 'Lebanon'}
      </span>
    </div>
  )
}
