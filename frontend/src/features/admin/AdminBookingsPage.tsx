import { Alert } from '@/components/ui/Alert'
import { buttonClasses } from '@/components/ui/buttonStyles'
import { EmptyState } from '@/components/ui/EmptyState'
import { Select } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Pagination } from '@/components/ui/Pagination'
import { PageSpinner } from '@/components/ui/Spinner'
import { BookingsTable } from '@/features/bookings/BookingsTable'
import { useQueryFilters } from '@/hooks/useQueryFilters'
import { parseApiError } from '@/lib/api'
import { SearchInput } from './SearchInput'
import { useAdminBookings } from './queries'

export function AdminBookingsPage() {
  const { values, params, update, setPage, filterQuery } = useQueryFilters(['status', 'search'] as const)
  const bookings = useAdminBookings(params)

  return (
    <>
      <PageHeader
        title="Bookings"
        actions={
          <a href={`/api/admin/bookings/export${filterQuery ? `?${filterQuery}` : ''}`} className={buttonClasses('secondary')} download>
            Export CSV
          </a>
        }
      />

      <div className="mb-6 flex flex-col gap-3 sm:flex-row">
        <Select aria-label="Status" value={values.status} onChange={(event) => update('status', event.target.value)} className="w-full sm:w-48">
          <option value="">All statuses</option>
          <option value="confirmed">Confirmed</option>
          <option value="pending">Pending</option>
          <option value="cancelled">Cancelled</option>
          <option value="failed">Failed</option>
        </Select>
        <SearchInput key={values.search} value={values.search} onSearch={(value) => update('search', value)} placeholder="Reference, name or e-mail" />
      </div>

      {bookings.isPending ? (
        <PageSpinner />
      ) : bookings.isError ? (
        <Alert tone="error">{parseApiError(bookings.error).message}</Alert>
      ) : bookings.data.data.length === 0 ? (
        <EmptyState title="No bookings found" />
      ) : (
        <>
          <BookingsTable bookings={bookings.data.data} showOperator />
          <Pagination meta={bookings.data.meta} onPageChange={setPage} />
        </>
      )}
    </>
  )
}
