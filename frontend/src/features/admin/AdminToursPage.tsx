import { Link } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { StatusBadge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { Select } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Pagination } from '@/components/ui/Pagination'
import { PageSpinner } from '@/components/ui/Spinner'
import { TBody, THead, Table, Td, Th, Tr } from '@/components/ui/Table'
import { useQueryFilters } from '@/hooks/useQueryFilters'
import { parseApiError } from '@/lib/api'
import { formatDateRange } from '@/lib/dates'
import { formatMoney } from '@/lib/money'
import type { TourStatus } from '@/lib/types'
import { SearchInput } from './SearchInput'
import { useAdminTours, useSetTourStatus } from './queries'

/** Moderation: every tour of every operator, with a status switch. */
export function AdminToursPage() {
  const { values, params, update, setPage } = useQueryFilters(['status', 'search'] as const)
  const tours = useAdminTours(params)
  const setStatus = useSetTourStatus()

  return (
    <>
      <PageHeader title="Tours" description="Archive a tour to take it off the public site." />

      <div className="mb-6 flex flex-col gap-3 sm:flex-row">
        <Select aria-label="Status" value={values.status} onChange={(event) => update('status', event.target.value)} className="w-full sm:w-48">
          <option value="">All statuses</option>
          <option value="published">Published</option>
          <option value="draft">Draft</option>
          <option value="archived">Archived</option>
        </Select>
        <SearchInput key={values.search} value={values.search} onSearch={(value) => update('search', value)} placeholder="Tour title" />
      </div>

      {setStatus.isError && <Alert tone="error" className="mb-4">{parseApiError(setStatus.error).message}</Alert>}

      {tours.isPending ? (
        <PageSpinner />
      ) : tours.isError ? (
        <Alert tone="error">{parseApiError(tours.error).message}</Alert>
      ) : tours.data.data.length === 0 ? (
        <EmptyState title="No tours found" />
      ) : (
        <>
          <Table>
            <THead>
              <tr><Th>Tour</Th><Th>Operator</Th><Th>Dates</Th><Th className="text-right">Price</Th><Th className="text-right">Bookings</Th><Th>Status</Th></tr>
            </THead>
            <TBody>
              {tours.data.data.map((tour) => (
                <Tr key={tour.id}>
                  <Td>
                    {tour.status === 'published' ? (
                      <Link to={`/tours/${tour.id}`} className="font-medium text-stone-900 hover:underline">{tour.title}</Link>
                    ) : (
                      <span className="font-medium text-stone-900">{tour.title}</span>
                    )}
                    <span className="block text-xs text-stone-500">{tour.city?.name} · {tour.category?.name}</span>
                  </Td>
                  <Td>{tour.operator?.company_name}</Td>
                  <Td className="whitespace-nowrap">{formatDateRange(tour.starts_on, tour.ends_on)}</Td>
                  <Td className="text-right">{formatMoney(tour.price_cents)}</Td>
                  <Td className="text-right">{tour.bookings_count ?? 0}</Td>
                  <Td>
                    <div className="flex items-center gap-2">
                      <StatusBadge status={tour.status} />
                      <Select
                        aria-label={`Change status of ${tour.title}`}
                        value={tour.status}
                        disabled={setStatus.isPending && setStatus.variables?.id === tour.id}
                        onChange={(event) => setStatus.mutate({ id: tour.id, status: event.target.value as TourStatus })}
                        className="w-32 py-1"
                      >
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                        <option value="archived">Archived</option>
                      </Select>
                    </div>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
          <Pagination meta={tours.data.meta} onPageChange={setPage} />
        </>
      )}
    </>
  )
}
