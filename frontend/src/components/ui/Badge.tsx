import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

export type BadgeTone = 'neutral' | 'green' | 'yellow' | 'red' | 'blue'

const tones: Record<BadgeTone, string> = {
  neutral: 'bg-stone-100 text-stone-700 ring-stone-200',
  green: 'bg-brand-50 text-brand-800 ring-brand-200',
  yellow: 'bg-amber-50 text-amber-800 ring-amber-200',
  red: 'bg-red-50 text-red-700 ring-red-200',
  blue: 'bg-sea-50 text-sea-700 ring-sea-600/20',
}

export function Badge({ tone = 'neutral', children, className }: { tone?: BadgeTone; children: ReactNode; className?: string }) {
  return (
    <span className={cn('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset', tones[tone], className)}>
      {children}
    </span>
  )
}

// One place that decides which colour each status gets.
const statusTones: Record<string, BadgeTone> = {
  confirmed: 'green',
  published: 'green',
  approved: 'green',
  active: 'green',
  pending: 'yellow',
  draft: 'yellow',
  cancelled: 'neutral',
  archived: 'neutral',
  failed: 'red',
  suspended: 'red',
  inactive: 'neutral',
}

export function StatusBadge({ status }: { status: string }) {
  return <Badge tone={statusTones[status] ?? 'neutral'}>{status.charAt(0).toUpperCase() + status.slice(1)}</Badge>
}
