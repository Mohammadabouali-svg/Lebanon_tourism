import type { PaginationMeta } from '@/lib/types'
import { Button } from './Button'

/** Previous / next controls driven by Laravel's pagination `meta`. */
export function Pagination({ meta, onPageChange }: { meta: PaginationMeta; onPageChange: (page: number) => void }) {
  if (meta.last_page <= 1) return null

  return (
    <nav aria-label="Pagination" className="mt-6 flex items-center justify-between gap-4">
      <p className="text-sm text-stone-500">
        {meta.from}–{meta.to} of {meta.total}
      </p>
      <div className="flex items-center gap-2">
        <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPageChange(meta.current_page - 1)}>
          Previous
        </Button>
        <span className="text-sm text-stone-600">
          Page {meta.current_page} of {meta.last_page}
        </span>
        <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPageChange(meta.current_page + 1)}>
          Next
        </Button>
      </div>
    </nav>
  )
}
