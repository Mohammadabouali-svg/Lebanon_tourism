import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { StatusBadge } from '@/components/ui/Badge'
import { Button, ButtonLink } from '@/components/ui/Button'
import { buttonClasses } from '@/components/ui/buttonStyles'
import { Card } from '@/components/ui/Card'
import { ConfirmDialog } from '@/components/ui/Dialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { PageHeader } from '@/components/ui/PageHeader'
import { Pagination } from '@/components/ui/Pagination'
import { PageSpinner } from '@/components/ui/Spinner'
import { TourImage } from '@/features/tours/TourImage'
import { useFlash } from '@/hooks/useFlash'
import { parseApiError } from '@/lib/api'
import { formatDateRange, formatDateTime } from '@/lib/dates'
import { formatMoney } from '@/lib/money'
import type { Booking } from '@/lib/types'
import { useCancelBooking, useMyBookings } from './queries'

export function MyBookingsPage() {
  const [page, setPage] = useState(1)
  const bookings = useMyBookings(page)
  const flash = useFlash()
  // Stripe sends the traveler back here with ?paid=<reference> (Exercise 9).
  // Coming back proves nothing (anyone can open this URL): the notice only
  // reflects the status the server reports, which Stripe's webhook sets.
  const [searchParams] = useSearchParams()
  const paidReference = searchParams.get('paid')
  const returned = paidReference ? bookings.data?.data.find((booking) => booking.reference === paidReference) : undefined

  return (
    <div className="mx-auto max-w-4xl px-4 py-10">
      <PageHeader title="My bookings" actions={<ButtonLink to="/tours" variant="secondary">Find more tours</ButtonLink>} />
      {flash && <Alert tone="success" className="mb-6">{flash}</Alert>}
      {returned?.status === 'pending' && (
        <Alert tone="info" className="mb-6">Thank you! We are waiting for the payment provider to confirm booking {returned.reference}. This page updates by itself.</Alert>
      )}
      {returned?.status === 'confirmed' && (
        <Alert tone="success" className="mb-6">Payment received: booking {returned.reference} is confirmed. Have a great trip!</Alert>
      )}

      {bookings.isPending ? (
        <PageSpinner />
      ) : bookings.isError ? (
        <Alert tone="error">{parseApiError(bookings.error).message}</Alert>
      ) : bookings.data.data.length === 0 ? (
        <EmptyState title="No bookings yet" description="When you book a tour it shows up here." action={<ButtonLink to="/tours">Browse tours</ButtonLink>} />
      ) : (
        <>
          <ul className="space-y-4">
            {bookings.data.data.map((booking) => (
              <BookingCard key={booking.id} booking={booking} />
            ))}
          </ul>
          <Pagination meta={bookings.data.meta} onPageChange={setPage} />
        </>
      )}
    </div>
  )
}

function BookingCard({ booking }: { booking: Booking }) {
  const [confirming, setConfirming] = useState(false)
  const cancel = useCancelBooking()
  const tour = booking.tour

  return (
    <li>
      <Card className="flex flex-col gap-4 p-4 sm:flex-row">
        {tour && <TourImage tour={tour} className="h-28 w-full shrink-0 rounded-lg sm:w-40" />}
        <div className="flex-1">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              {tour ? (
                <Link to={`/tours/${tour.id}`} className="font-semibold text-stone-900 hover:underline">{tour.title}</Link>
              ) : (
                <span className="font-semibold">Tour</span>
              )}
              {booking.departure && (
                <p className="text-sm text-stone-500">{formatDateRange(booking.departure.starts_on, booking.departure.ends_on)} · {tour?.city?.name}</p>
              )}
            </div>
            <StatusBadge status={booking.status} />
          </div>

          <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
            <div><dt className="text-stone-500">Reference</dt><dd className="font-mono">{booking.reference}</dd></div>
            <div><dt className="text-stone-500">Travelers</dt><dd>{booking.spots}</dd></div>
            <div><dt className="text-stone-500">Paid with</dt><dd className="capitalize">{booking.payment_method}</dd></div>
            <div>
              <dt className="text-stone-500">Total</dt>
              <dd className="font-semibold">
                {formatMoney(booking.total_cents)}
                {booking.discount_code && <span className="block text-xs font-normal text-brand-700">{booking.discount_code}: −{formatMoney(booking.discount_cents)}</span>}
              </dd>
            </div>
          </dl>
          <p className="mt-2 text-xs text-stone-500">
            Booked {formatDateTime(booking.created_at)}
            {booking.cancelled_at && ` · cancelled ${formatDateTime(booking.cancelled_at)}`}
          </p>

          {booking.checkout_url && (
            <div className="mt-3 flex flex-wrap items-center gap-3">
              {/* A plain link: it leaves our site for Stripe's payment page. */}
              <a href={booking.checkout_url} className={buttonClasses('primary', 'sm')}>
                Continue to payment
              </a>
              <span className="text-xs text-stone-500">Awaiting payment. Unpaid bookings are released after about 30 minutes.</span>
            </div>
          )}

          {booking.can_cancel && (
            <div className="mt-3">
              <Button variant="secondary" size="sm" onClick={() => setConfirming(true)}>
                Cancel booking
              </Button>
            </div>
          )}
        </div>
      </Card>

      <ConfirmDialog
        open={confirming}
        title={`Cancel booking ${booking.reference}?`}
        description={`${formatMoney(booking.total_cents)} will be refunded to your wallet as travel credit, and your ${booking.spots} spot(s) are released.`}
        confirmLabel="Cancel booking"
        loading={cancel.isPending}
        error={cancel.isError && <Alert tone="error">{parseApiError(cancel.error).message}</Alert>}
        onCancel={() => setConfirming(false)}
        onConfirm={() => cancel.mutate(booking.id, { onSuccess: () => setConfirming(false) })}
      />
    </li>
  )
}
