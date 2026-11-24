import { useId } from 'react'
import { cn } from '@/lib/cn'

function Star({ filled, className }: { filled: boolean; className?: string }) {
  return (
    <svg viewBox="0 0 20 20" aria-hidden="true" className={cn('size-4', filled ? 'fill-amber-400' : 'fill-stone-200', className)}>
      <path d="M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.5 7.7l5.9-.9z" />
    </svg>
  )
}

/** Read-only stars, e.g. "★★★★☆ 4.3 (12)". */
export function StarRating({ value, count, className }: { value: number | null | undefined; count?: number; className?: string }) {
  if (value === null || value === undefined) {
    return <span className={cn('text-xs text-stone-500', className)}>No reviews yet</span>
  }

  return (
    <span className={cn('inline-flex items-center gap-1', className)}>
      <span className="flex" aria-hidden="true">
        {[1, 2, 3, 4, 5].map((star) => (
          <Star key={star} filled={star <= Math.round(value)} />
        ))}
      </span>
      <span className="text-sm text-stone-700">
        <span className="sr-only">Rated </span>
        {value.toFixed(1)}
        <span className="sr-only"> out of 5</span>
        {count !== undefined && <span className="text-stone-500"> ({count})</span>}
      </span>
    </span>
  )
}

/*
 * Star picker built from real radio buttons, so it works with the keyboard
 * (arrow keys) and screen readers ("3 stars, radio button, 3 of 5").
 */
export function StarInput({ value, onChange, label = 'Your rating' }: { value: number; onChange: (value: number) => void; label?: string }) {
  const name = useId()

  return (
    <fieldset>
      <legend className="text-sm font-medium text-stone-700">{label}</legend>
      <div className="mt-1 flex gap-1">
        {[1, 2, 3, 4, 5].map((star) => (
          <label key={star} className="cursor-pointer rounded p-0.5 has-focus-visible:outline-2 has-focus-visible:outline-brand-600">
            <input type="radio" name={name} value={star} checked={value === star} onChange={() => onChange(star)} className="sr-only" />
            <Star filled={star <= value} className="size-7" />
            <span className="sr-only">
              {star} star{star > 1 ? 's' : ''}
            </span>
          </label>
        ))}
      </div>
    </fieldset>
  )
}
