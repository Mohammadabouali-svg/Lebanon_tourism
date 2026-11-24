import { useState } from 'react'
import { Link } from 'react-router'
import { MonthlyBarChart } from '@/components/MonthlyBarChart'
import { Alert } from '@/components/ui/Alert'
import { CardSection, StatCard } from '@/components/ui/Card'
import { Select } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { PageSpinner } from '@/components/ui/Spinner'
import { parseApiError } from '@/lib/api'
import { formatMoney } from '@/lib/money'
import { useAdminDashboard } from './queries'

export function AdminDashboardPage() {
  const [year, setYear] = useState<number | null>(null)
  const dashboard = useAdminDashboard(year)

  if (dashboard.isPending) return <PageSpinner />
  if (dashboard.isError) return <Alert tone="error">{parseApiError(dashboard.error).message}</Alert>

  const d = dashboard.data

  return (
    <>
      <PageHeader title="Admin dashboard" />
      {d.pending_operators > 0 && (
        <Alert tone="warning" className="mb-6">
          {d.pending_operators} tour operator(s) waiting for approval.{' '}
          <Link to="/admin/operators?status=pending" className="font-medium underline">Review them</Link>
        </Alert>
      )}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Travelers" value={d.users.travelers} hint={`${d.users.operators} operators · ${d.users.admins} admins`} />
        <StatCard label="Published tours" value={d.published_tours} />
        <StatCard label="Confirmed bookings" value={d.totals.bookings} hint={`${d.totals.travelers} travelers`} />
        <StatCard label="Revenue" value={formatMoney(d.totals.revenue_cents)} hint="All operators, all time" />
      </div>

      <CardSection
        className="mt-6"
        title={`Bookings in ${d.year}`}
        actions={
          <Select aria-label="Year" value={d.year} onChange={(event) => setYear(Number(event.target.value))} className="w-28">
            {d.years.map((y) => (
              <option key={y} value={y}>{y}</option>
            ))}
          </Select>
        }
      >
        <MonthlyBarChart data={d.monthly} />
      </CardSection>
    </>
  )
}
