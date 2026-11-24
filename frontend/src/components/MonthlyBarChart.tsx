import { useState } from 'react'
import { monthName } from '@/lib/dates'
import { formatMoney } from '@/lib/money'
import type { MonthlyPoint } from '@/lib/types'
import { cn } from '@/lib/cn'

/*
 * A bar chart made of plain <div>s: twelve bars whose height is a percentage
 * of the largest value. No chart library needed for something this simple.
 *
 * Charts are invisible to screen readers, so the same numbers are also
 * rendered as a (visually hidden) table.
 */
export function MonthlyBarChart({ data }: { data: MonthlyPoint[] }) {
  const [metric, setMetric] = useState<'bookings' | 'revenue_cents'>('bookings')
  const values = data.map((point) => point[metric])
  const max = Math.max(1, ...values)
  const format = (value: number) => (metric === 'bookings' ? String(value) : formatMoney(value))

  return (
    <div>
      <div className="mb-4 inline-flex rounded-lg border border-stone-200 p-0.5 text-sm" role="group" aria-label="Chart metric">
        {(['bookings', 'revenue_cents'] as const).map((option) => (
          <button
            key={option}
            type="button"
            aria-pressed={metric === option}
            onClick={() => setMetric(option)}
            className={cn('rounded-md px-3 py-1', metric === option ? 'bg-brand-700 text-white' : 'text-stone-600 hover:bg-stone-100')}
          >
            {option === 'bookings' ? 'Bookings' : 'Revenue'}
          </button>
        ))}
      </div>

      <div className="flex h-48 items-end gap-1 sm:gap-2" aria-hidden="true">
        {data.map((point) => (
          <div key={point.month} className="flex h-full flex-1 flex-col items-center justify-end gap-1">
            <span className="text-[10px] text-stone-500 sm:text-xs">{point[metric] > 0 ? format(point[metric]) : ''}</span>
            <div
              className="w-full rounded-t bg-brand-600 transition-[height]"
              style={{ height: `${(point[metric] / max) * 100}%`, minHeight: point[metric] > 0 ? '2px' : 0 }}
              title={`${monthName(point.month)}: ${point.bookings} bookings, ${formatMoney(point.revenue_cents)}`}
            />
            <span className="text-[10px] text-stone-500 sm:text-xs">{monthName(point.month).charAt(0)}</span>
          </div>
        ))}
      </div>

      <table className="sr-only">
        <caption>Confirmed bookings and revenue per month</caption>
        <thead>
          <tr><th>Month</th><th>Bookings</th><th>Revenue</th></tr>
        </thead>
        <tbody>
          {data.map((point) => (
            <tr key={point.month}>
              <td>{monthName(point.month)}</td>
              <td>{point.bookings}</td>
              <td>{formatMoney(point.revenue_cents)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
