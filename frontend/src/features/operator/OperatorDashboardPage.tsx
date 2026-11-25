import { useState } from 'react'
import { Link } from 'react-router'
import { MonthlyBarChart } from '@/components/MonthlyBarChart'
import { Alert } from '@/components/ui/Alert'
import { ButtonLink } from '@/components/ui/Button'
import { CardSection, StatCard } from '@/components/ui/Card'
import { Select } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { PageSpinner } from '@/components/ui/Spinner'
import { useCurrentUser } from '@/features/auth/hooks'
import { parseApiError } from '@/lib/api'
import { formatDateRange } from '@/lib/dates'
import { formatMoney } from '@/lib/money'
import { useOperatorDashboard } from './queries'

export function OperatorDashboardPage() {
  const { user } = useCurrentUser()
  const [year, setYear] = useState<number | null>(null)
  const dashboard = useOperatorDashboard(year)

  return (
    <>
      <PageHeader
        title="Dashboard"
        description={user?.operator?.company_name}
        actions={<ButtonLink to="/operator/tours/new">New tour</ButtonLink>}
      />

      {dashboard.isPending ? (
        <PageSpinner />
      ) : dashboard.isError ? (
        <Alert tone="error">{parseApiError(dashboard.error).message}</Alert>
      ) : (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Published tours" value={dashboard.data.tours.published} hint={`${dashboard.data.tours.draft} draft · ${dashboard.data.tours.archived} archived`} />
            <StatCard label="Confirmed bookings" value={dashboard.data.totals.bookings} />
            <StatCard label="Travelers" value={dashboard.data.totals.travelers} />
            <StatCard label="Revenue" value={formatMoney(dashboard.data.totals.revenue_cents)} hint="Confirmed bookings, all time" />
          </div>

          <CardSection
            title={`Bookings in ${dashboard.data.year}`}
            actions={
              <Select aria-label="Year" value={dashboard.data.year} onChange={(event) => setYear(Number(event.target.value))} className="w-28">
                {dashboard.data.years.map((y) => (
                  <option key={y} value={y}>{y}</option>
                ))}
              </Select>
            }
          >
            <MonthlyBarChart data={dashboard.data.monthly} />
          </CardSection>

          <CardSection title="Upcoming departures">
            {dashboard.data.upcoming_departures.length === 0 ? (
              <p className="text-sm text-stone-500">No published tours coming up.</p>
            ) : (
              <ul className="divide-y divide-stone-100">
                {dashboard.data.upcoming_departures.map(({ departure, tour }) => {
                  const percent = Math.round((departure.booked_spots / departure.capacity) * 100)
                  return (
                    <li key={departure.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                      <div>
                        <Link to={`/operator/tours/${tour.id}/edit`} className="font-medium text-stone-900 hover:underline">{tour.title}</Link>
                        <p className="text-sm text-stone-500">{formatDateRange(departure.starts_on, departure.ends_on)} · {tour.city?.name}</p>
                      </div>
                      <div className="w-40">
                        <p className="text-right text-xs text-stone-500">{departure.booked_spots} / {departure.capacity} booked</p>
                        <div className="mt-1 h-2 rounded-full bg-stone-100" role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100} aria-label={`${tour.title} occupancy`}>
                          <div className="h-2 rounded-full bg-brand-600" style={{ width: `${percent}%` }} />
                        </div>
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
          </CardSection>
        </div>
      )}
    </>
  )
}
