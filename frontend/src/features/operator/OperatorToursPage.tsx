import { useState } from 'react'
import { Link } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { Badge, StatusBadge } from '@/components/ui/Badge'
import { Button, ButtonLink } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/Dialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { PageHeader } from '@/components/ui/PageHeader'
import { Pagination } from '@/components/ui/Pagination'
import { PageSpinner } from '@/components/ui/Spinner'
import { TBody, THead, Table, Td, Th, Tr } from '@/components/ui/Table'
import { useFlash } from '@/hooks/useFlash'
import { parseApiError } from '@/lib/api'
import { NextDeparture } from '@/features/tours/NextDeparture'
import { formatMoney } from '@/lib/money'
import type { Tour } from '@/lib/types'
import { useDeleteTour, useOperatorTours } from './queries'

export function OperatorToursPage() {
  const [page, setPage] = useState(1)
  const tours = useOperatorTours(page)
  const flash = useFlash()
  const [toDelete, setToDelete] = useState<Tour | null>(null)
  const remove = useDeleteTour()

  return (
    <>
      <PageHeader title="My tours" actions={<ButtonLink to="/operator/tours/new">New tour</ButtonLink>} />
      {flash && <Alert tone="success" className="mb-4">{flash}</Alert>}

      {tours.isPending ? (
        <PageSpinner />
      ) : tours.isError ? (
        <Alert tone="error">{parseApiError(tours.error).message}</Alert>
      ) : tours.data.data.length === 0 ? (
        <EmptyState title="No tours yet" description="Create your first tour. It stays a draft until you publish it." action={<ButtonLink to="/operator/tours/new">Create a tour</ButtonLink>} />
      ) : (
        <>
          <Table>
            <THead>
              <tr>
                <Th>Tour</Th>
                <Th>Next date</Th>
                <Th>Status</Th>
                <Th className="text-right">Price</Th>
                <Th className="text-right">Bookings</Th>
                <Th><span className="sr-only">Actions</span></Th>
              </tr>
            </THead>
            <TBody>
              {tours.data.data.map((tour) => (
                <Tr key={tour.id}>
                  <Td>
                    <span className="font-medium text-stone-900">{tour.title}</span>
                    <span className="block text-xs text-stone-500">{tour.city?.name} · {tour.category?.name}</span>
                  </Td>
                  <Td className="whitespace-nowrap"><NextDeparture tour={tour} /></Td>
                  <Td>
                    <div className="flex flex-wrap items-center gap-1">
                      <StatusBadge status={tour.status} />
                      {tour.moderated_at && <Badge tone="red">Locked by admin</Badge>}
                    </div>
                  </Td>
                  <Td className="text-right">{formatMoney(tour.price_cents)}</Td>
                  <Td className="whitespace-nowrap text-right">{tour.bookings_count ?? 0}</Td>
                  <Td>
                    <div className="flex justify-end gap-2">
                      {tour.status === 'published' && (
                        <Link to={`/tours/${tour.id}`} className="rounded-lg px-2 py-1.5 text-sm text-stone-600 hover:bg-stone-100">View</Link>
                      )}
                      <ButtonLink to={`/operator/tours/${tour.id}/edit`} variant="secondary" size="sm">Edit</ButtonLink>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                          remove.reset()
                          setToDelete(tour)
                        }}
                      >
                        Delete
                      </Button>
                    </div>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
          <Pagination meta={tours.data.meta} onPageChange={setPage} />
        </>
      )}

      <ConfirmDialog
        open={toDelete !== null}
        title={`Delete "${toDelete?.title ?? ''}"?`}
        description="This cannot be undone. Tours that already have bookings cannot be deleted; archive them instead (edit → status)."
        confirmLabel="Delete tour"
        loading={remove.isPending}
        // e.g. code "tour_has_bookings" from BusinessRuleException
        error={remove.isError && <Alert tone="error">{parseApiError(remove.error).message}</Alert>}
        onCancel={() => setToDelete(null)}
        onConfirm={() => toDelete && remove.mutate(toDelete.id, { onSuccess: () => setToDelete(null) })}
      />
    </>
  )
}
