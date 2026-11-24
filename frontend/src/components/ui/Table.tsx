import type { HTMLAttributes, ReactNode, TdHTMLAttributes, ThHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

/** A table that scrolls horizontally on narrow screens instead of breaking the layout. */
export function Table({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={cn('overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm', className)}>
      <table className="min-w-full divide-y divide-stone-200 text-sm">{children}</table>
    </div>
  )
}

export function THead({ children }: { children: ReactNode }) {
  return <thead className="bg-stone-50 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">{children}</thead>
}

export function TBody({ children }: { children: ReactNode }) {
  return <tbody className="divide-y divide-stone-100">{children}</tbody>
}

export function Th({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
  return <th scope="col" className={cn('whitespace-nowrap px-4 py-3', className)} {...props} />
}

export function Td({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
  return <td className={cn('px-4 py-3 align-top text-stone-700', className)} {...props} />
}

export function Tr(props: HTMLAttributes<HTMLTableRowElement>) {
  return <tr className="hover:bg-stone-50/60" {...props} />
}
