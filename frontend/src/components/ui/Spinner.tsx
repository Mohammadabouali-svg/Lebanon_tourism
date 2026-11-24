import { cn } from '@/lib/cn'

export function Spinner({ className, label = 'Loading' }: { className?: string; label?: string }) {
  return (
    <span role="status" className="inline-flex items-center">
      <svg className={cn('size-5 animate-spin text-current', className)} viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3" className="opacity-25" />
        <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
      </svg>
      <span className="sr-only">{label}</span>
    </span>
  )
}

/** Centered spinner for a whole page or section that is loading. */
export function PageSpinner() {
  return (
    <div className="flex min-h-[40vh] items-center justify-center text-brand-700">
      <Spinner className="size-8" />
    </div>
  )
}
