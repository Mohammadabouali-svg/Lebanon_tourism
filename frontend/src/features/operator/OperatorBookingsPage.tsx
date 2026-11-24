import { useSearchParams } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { buttonClasses } from '@/components/ui/buttonStyles'
import { EmptyState } from '@/components/ui/EmptyState'
import { SelectField } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Pagination } from '@/components/ui/Pagination'
import { PageSpinner } from '@/components/ui/Spinner'
import { BookingsTable } from '@/features/bookings/BookingsTable'
import { parseApiError } from '@/lib/api'
import { useOperatorBookings, useOperatorTours } from './queries'

export function OperatorBookingsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const filters = {
    tour_id: searchParams.get('tour_id') ?? '',
    status: searchParams.get('status') ?? '',
  }
  const page = searchParams.get('page') ?? '1'

  // Only send filters that are set.
  const params = Object.fromEntries(Object.entries({ ...filters, page }).filter(([, value]) => value !== ''))
  const bookings = useOperatorBookings(params)
  const tours = useOperatorTours(1)

  function update(key: string, value: string) {
    setSearchParams((current) => {
      const next = new URLSearchParams(current)
      if (value) next.set(key, value)
      else next.delete(key)
      if (key !== 'page') next.delete('page')
      return next
    })
  }

  // The export is a normal link: the browser downloads the CSV with the
  // session cookie, applying the same filters as the table.
  const exportQuery = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== '')).toString()

  return (
    <>
      <PageHeader
        title="Bookings"
        description="Travelers booked on your tours."
        actions={
          <a href={`/api/operator/bookings/export${exportQuery ? `?${exportQuery}` : ''}`} className={buttonClasses('secondary')} download>
            Export CSV
          </a>
        }
      />

      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:max-w-2xl">
        <SelectField label="Tour" value={filters.tour_id} onChange={(event) => update('tour_id', event.target.value)}>
          <option value="">All tours</option>
          {tours.data?.data.map((tour) => (
            <option key={tour.id} value={tour.id}>{tour.title}</option>
          ))}
        </SelectField>
        <SelectField label="Status" value={filters.status} onChange={(event) => update('status', event.target.value)}>
          <option value="">All statuses</option>
          <option value="confirmed">Confirmed</option>
          <option value="pending">Pending</option>
          <option value="cancelled">Cancelled</option>
          <option value="failed">Failed</option>
        </SelectField>
      </div>

      {bookings.isPending ? (
        <PageSpinner />
      ) : bookings.isError ? (
        <Alert tone="error">{parseApiError(bookings.error).message}</Alert>
      ) : bookings.data.data.length === 0 ? (
        <EmptyState title="No bookings found" />
      ) : (
        <>
          <BookingsTable bookings={bookings.data.data} />
          <Pagination meta={bookings.data.meta} onPageChange={(p) => update('page', String(p))} />
        </>
      )}
    </>
  )
}
