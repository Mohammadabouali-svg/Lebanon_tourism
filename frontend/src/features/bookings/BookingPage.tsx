import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Input } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { PageSpinner, Spinner } from '@/components/ui/Spinner'
import { useCurrentUser } from '@/features/auth/hooks'
import { useTour } from '@/features/tours/queries'
import { TourImage } from '@/features/tours/TourImage'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { isStatus, parseApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { formatDateRange } from '@/lib/dates'
import { formatMoney } from '@/lib/money'
import type { PaymentMethod, Tour } from '@/lib/types'
import { NotFoundPage } from '@/pages/NotFoundPage'
import { useCreateBooking, useQuote } from './queries'

const MAX_SPOTS_PER_BOOKING = 20 // mirrors StoreBookingRequest (max:20)

export function BookingPage() {
  const id = Number(useParams().id)
  const tour = useTour(id)

  if (tour.isPending) return <PageSpinner />
  if (tour.isError) {
    if (isStatus(tour.error, 404)) return <NotFoundPage />
    return <Alert tone="error" className="m-6">{parseApiError(tour.error).message}</Alert>
  }

  return <Checkout tour={tour.data} />
}

function Checkout({ tour }: { tour: Tour }) {
  const navigate = useNavigate()
  const { user } = useCurrentUser()
  const [spots, setSpots] = useState(1)
  const [codeInput, setCodeInput] = useState('')
  const [appliedCode, setAppliedCode] = useState<string | null>(null)
  const [method, setMethod] = useState<PaymentMethod>('wallet')
  const book = useCreateBooking(tour.id)

  const maxSpots = Math.max(1, Math.min(tour.available_spots, MAX_SPOTS_PER_BOOKING))
  const debouncedSpots = useDebouncedValue(spots, 300)
  const quote = useQuote(tour.id, debouncedSpots, appliedCode, tour.is_bookable)

  // An invalid code makes the whole quote fail with code "invalid_discount".
  const quoteError = quote.error ? parseApiError(quote.error) : null
  const discountError = quoteError?.code === 'invalid_discount' ? quoteError.message : null
  const bookError = book.error ? parseApiError(book.error) : null
  const balance = user?.wallet_balance_cents ?? 0
  const total = quote.data?.total_cents
  const walletTooLow = method === 'wallet' && total !== undefined && total > balance

  function submit(event: FormEvent) {
    event.preventDefault()
    book.mutate(
      { spots, payment_method: method, discount_code: appliedCode },
      {
        onSuccess: (booking) =>
          navigate('/bookings', { state: { flash: `Booking ${booking.reference} is confirmed. Have a great trip!` } }),
      },
    )
  }

  if (!tour.is_bookable) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-10">
        <PageHeader title="Booking unavailable" />
        <Alert tone="info">This tour is sold out or no longer open for booking.</Alert>
        <Link to={`/tours/${tour.id}`} className="mt-4 inline-block text-brand-700 hover:underline">Back to the tour</Link>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-5xl px-4 py-10">
      <PageHeader title="Book your tour" />

      <form onSubmit={submit} className="grid gap-8 lg:grid-cols-[1fr_20rem]" noValidate>
        <div className="space-y-6">
          <Card className="flex gap-4 p-4">
            <TourImage tour={tour} className="size-20 shrink-0 rounded-lg" />
            <div>
              <h2 className="font-semibold text-stone-900">{tour.title}</h2>
              <p className="text-sm text-stone-500">{formatDateRange(tour.starts_on, tour.ends_on)} · {tour.city?.name}</p>
              <p className="text-sm text-stone-500">{formatMoney(tour.price_cents)} per person · {tour.available_spots} spots left</p>
            </div>
          </Card>

          <Card className="p-5">
            <h2 className="font-semibold text-stone-900">Travelers</h2>
            <div className="mt-3 flex items-center gap-3">
              <Button variant="secondary" aria-label="One traveler less" disabled={spots <= 1} onClick={() => setSpots((s) => Math.max(1, s - 1))}>
                −
              </Button>
              <Input
                aria-label="Number of travelers"
                type="number"
                min={1}
                max={maxSpots}
                value={spots}
                onChange={(event) => setSpots(Math.min(maxSpots, Math.max(1, Number(event.target.value) || 1)))}
                className="w-20 text-center"
              />
              <Button variant="secondary" aria-label="One traveler more" disabled={spots >= maxSpots} onClick={() => setSpots((s) => Math.min(maxSpots, s + 1))}>
                +
              </Button>
            </div>
            {bookError?.fieldErrors.spots && <p className="mt-2 text-sm text-red-600">{bookError.fieldErrors.spots}</p>}
          </Card>

          <Card className="p-5">
            <h2 className="font-semibold text-stone-900">Discount code</h2>
            {appliedCode && !discountError ? (
              <div className="mt-3 flex items-center justify-between rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800">
                <span>
                  Code <strong>{appliedCode}</strong> applied
                </span>
                <button type="button" className="font-medium underline" onClick={() => setAppliedCode(null)}>
                  Remove
                </button>
              </div>
            ) : (
              <div className="mt-3 flex gap-2">
                <Input
                  aria-label="Discount code"
                  placeholder="e.g. WELCOME10"
                  value={codeInput}
                  onChange={(event) => setCodeInput(event.target.value)}
                  className="min-w-0 flex-1 uppercase"
                />
                <Button variant="secondary" disabled={codeInput.trim() === ''} onClick={() => setAppliedCode(codeInput.trim())}>
                  Apply
                </Button>
              </div>
            )}
            {discountError && (
              <p className="mt-2 text-sm text-red-600">
                {discountError}{' '}
                <button type="button" className="font-medium underline" onClick={() => setAppliedCode(null)}>
                  Continue without code
                </button>
              </p>
            )}
          </Card>

          <Card className="p-5">
            <fieldset>
              <legend className="font-semibold text-stone-900">Payment</legend>
              <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <PaymentOption
                  value="wallet"
                  current={method}
                  onSelect={setMethod}
                  title="Wallet"
                  description={`Balance: ${formatMoney(balance)}`}
                />
                <PaymentOption value="card" current={method} onSelect={setMethod} title="Card" description="Paid through our payment provider" />
              </div>
            </fieldset>
            {method === 'card' && (
              // In production the provider's own form (e.g. Stripe Elements)
              // collects the card number, so it never touches our servers.
              <Alert tone="info" className="mt-4">
                Development mode: the fake payment gateway approves the payment. No card number is collected by this site.
              </Alert>
            )}
            {walletTooLow && (
              <Alert tone="warning" className="mt-4">
                Your wallet balance is lower than the total. Choose card payment, or ask an administrator for credit.
              </Alert>
            )}
          </Card>
        </div>

        <aside>
          <Card className="sticky top-6 p-5">
            <h2 className="font-semibold text-stone-900">Price</h2>
            {quote.isPending ? (
              <div className="mt-4"><Spinner /></div>
            ) : quote.data ? (
              <dl className={cn('mt-4 space-y-2 text-sm', (quote.isFetching || spots !== debouncedSpots) && 'opacity-60')}>
                <Row label={`${formatMoney(quote.data.unit_price_cents)} × ${quote.data.spots}`} value={formatMoney(quote.data.subtotal_cents)} />
                {quote.data.discount_cents > 0 && (
                  <Row label={`Discount (${quote.data.discount_code})`} value={`− ${formatMoney(quote.data.discount_cents)}`} />
                )}
                <div className="border-t border-stone-200 pt-2">
                  <Row label="Total" value={formatMoney(quote.data.total_cents)} strong />
                </div>
              </dl>
            ) : (
              quoteError && !discountError && <Alert tone="error" className="mt-4">{quoteError.message}</Alert>
            )}

            {/* Business-rule errors from BookingService: sold out, wallet too low, ... */}
            {bookError && Object.keys(bookError.fieldErrors).length === 0 && (
              <Alert tone="error" className="mt-4">{bookError.message}</Alert>
            )}

            <Button type="submit" className="mt-5 w-full" loading={book.isPending} disabled={!quote.data || Boolean(discountError)}>
              Confirm and pay {total !== undefined ? formatMoney(total) : ''}
            </Button>
            <p className="mt-3 text-xs text-stone-500">The final price is always calculated by our server.</p>
          </Card>
        </aside>
      </form>
    </div>
  )
}

function PaymentOption({ value, current, onSelect, title, description }: {
  value: PaymentMethod
  current: PaymentMethod
  onSelect: (value: PaymentMethod) => void
  title: string
  description: string
}) {
  const selected = value === current
  return (
    <label
      className={cn(
        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 has-focus-visible:outline-2 has-focus-visible:outline-brand-600',
        selected ? 'border-brand-600 bg-brand-50' : 'border-stone-300 bg-white',
      )}
    >
      <input type="radio" name="payment_method" value={value} checked={selected} onChange={() => onSelect(value)} className="mt-1 accent-brand-700" />
      <span>
        <span className="block text-sm font-medium text-stone-900">{title}</span>
        <span className="block text-xs text-stone-500">{description}</span>
      </span>
    </label>
  )
}

function Row({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={cn('flex justify-between gap-4', strong && 'text-base font-semibold text-stone-900')}>
      <dt>{label}</dt>
      <dd>{value}</dd>
    </div>
  )
}
