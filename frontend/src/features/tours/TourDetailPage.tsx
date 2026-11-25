import { Link, useParams } from 'react-router'
import { StarRating } from '@/components/StarRating'
import { Alert } from '@/components/ui/Alert'
import { ButtonLink } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { DocumentTitle } from '@/components/ui/PageHeader'
import { PageSpinner } from '@/components/ui/Spinner'
import { useCurrentUser } from '@/features/auth/hooks'
import { isStatus, parseApiError } from '@/lib/api'
import { formatDate, formatDateRange } from '@/lib/dates'
import { formatMoney } from '@/lib/money'
import type { Departure, ItineraryItem, Tour, User } from '@/lib/types'
import { NotFoundPage } from '@/pages/NotFoundPage'
import { ReviewsSection } from './ReviewsSection'
import { TourImage } from './TourImage'
import { useTour } from './queries'

export function TourDetailPage() {
  const id = Number(useParams().id)
  const tour = useTour(id)

  if (tour.isPending) return <PageSpinner />
  if (tour.isError) {
    if (isStatus(tour.error, 404)) return <NotFoundPage />
    return (
      <div className="mx-auto max-w-3xl px-4 py-10">
        <Alert tone="error">{parseApiError(tour.error).message}</Alert>
      </div>
    )
  }

  const t = tour.data

  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <DocumentTitle title={t.title} />
      <nav aria-label="Breadcrumb" className="mb-4 text-sm text-stone-500">
        <Link to="/tours" className="hover:underline">Tours</Link> <span aria-hidden="true">/</span> {t.city?.name}
      </nav>

      <TourImage tour={t} className="h-64 w-full rounded-2xl sm:h-96" />

      <div className="mt-8 grid gap-10 lg:grid-cols-[1fr_22rem]">
        <div className="min-w-0">
          <p className="text-sm font-medium uppercase tracking-wide text-brand-700">{t.category?.name}</p>
          <h1 className="mt-1 text-3xl font-semibold tracking-tight text-stone-900">{t.title}</h1>
          <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-stone-600">
            <StarRating value={t.rating_average} count={t.reviews_count} />
            <span>{t.city?.name}, {t.city?.region?.name}</span>
            {t.operator?.company_name && <span>by {t.operator.company_name}</span>}
          </div>

          <dl className="mt-6 grid grid-cols-2 gap-4 rounded-xl border border-stone-200 bg-white p-4 text-sm sm:grid-cols-4">
            <Fact label="Next date" value={t.next_departure ? formatDate(t.next_departure.starts_on) : 'None planned'} />
            <Fact label="Duration" value={`${t.duration_days} day${t.duration_days > 1 ? 's' : ''}`} />
            <Fact label="Upcoming dates" value={String(t.upcoming_departures?.length ?? 0)} />
            <Fact label="Price" value={`${formatMoney(t.price_cents)} / person`} />
          </dl>

          {/* Outside the 4-column grid above: a meeting point is often a full sentence. */}
          {t.meeting_point && (
            <p className="mt-4 text-sm text-stone-700">
              <span className="font-medium text-stone-900">Meeting point:</span> {t.meeting_point}
            </p>
          )}

          {/* Plain text rendering: React escapes it, so an operator cannot inject HTML. */}
          <p className="mt-6 whitespace-pre-line leading-relaxed text-stone-700">{t.description}</p>

          {t.itinerary && t.itinerary.length > 0 && <Itinerary items={t.itinerary} />}

          <ReviewsSection tourId={t.id} />
        </div>

        <aside>
          <BookingBox tour={t} />
        </aside>
      </div>
    </div>
  )
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-stone-500">{label}</dt>
      <dd className="mt-0.5 font-medium text-stone-900">{value}</dd>
    </div>
  )
}

function Itinerary({ items }: { items: ItineraryItem[] }) {
  // Group items by day: { 1: [...], 2: [...] }. Items arrive sorted by day.
  const days = new Map<number, ItineraryItem[]>()
  for (const item of items) days.set(item.day, [...(days.get(item.day) ?? []), item])

  return (
    <section className="mt-10">
      <h2 className="text-xl font-semibold text-stone-900">Itinerary</h2>
      <ol className="mt-4 space-y-6 border-l-2 border-brand-200 pl-6">
        {[...days.entries()].map(([day, dayItems]) => (
          <li key={day} className="relative">
            <span className="absolute -left-[2.15rem] top-0 flex size-6 items-center justify-center rounded-full bg-brand-700 text-xs font-semibold text-white">
              {day}
            </span>
            <h3 className="font-semibold text-stone-900">Day {day}</h3>
            <ul className="mt-2 space-y-3">
              {dayItems.map((item) => (
                <li key={item.id}>
                  <p className="font-medium text-stone-800">{item.title}</p>
                  {item.description && <p className="text-sm text-stone-600">{item.description}</p>}
                </li>
              ))}
            </ul>
          </li>
        ))}
      </ol>
    </section>
  )
}

/**
 * The sticky box: one row per upcoming departure (Exercise 10). What each
 * row offers depends on who is looking; the server re-checks everything.
 */
function BookingBox({ tour }: { tour: Tour }) {
  const { user } = useCurrentUser()
  const departures = tour.upcoming_departures ?? []

  return (
    <Card className="sticky top-6 p-5">
      <p>
        <span className="text-2xl font-semibold text-stone-900">{formatMoney(tour.price_cents)}</span>
        <span className="text-sm text-stone-500"> / person</span>
      </p>
      <h2 className="mt-4 text-sm font-semibold text-stone-900">Choose a date</h2>
      {departures.length === 0 ? (
        <Alert tone="info" className="mt-2">No upcoming dates for this tour yet.</Alert>
      ) : (
        <>
          <ul className="mt-2 divide-y divide-stone-100">
            {departures.map((departure) => (
              <li key={departure.id} className="flex items-center justify-between gap-3 py-2.5">
                <div className="text-sm">
                  <p className="font-medium text-stone-900">{formatDateRange(departure.starts_on, departure.ends_on)}</p>
                  <p className={departure.available_spots === 0 ? 'text-red-700' : 'text-stone-500'}>
                    {departure.available_spots === 0 ? 'Sold out' : `${departure.available_spots} of ${departure.capacity} spots left`}
                  </p>
                </div>
                {departure.is_bookable && user?.role === 'traveler' && user.email_verified && (
                  <ButtonLink to={bookingPath(tour, departure)} size="sm">
                    Book
                  </ButtonLink>
                )}
              </li>
            ))}
          </ul>
          <div className="mt-3">
            <BookingHint tour={tour} departures={departures} user={user} />
          </div>
        </>
      )}
      <p className="mt-4 text-xs text-stone-500">Free cancellation until the day before departure, refunded as travel credit.</p>
    </Card>
  )
}

function bookingPath(tour: Tour, departure: Departure): string {
  return `/tours/${tour.id}/departures/${departure.id}/book`
}

/** Explains why there is no Book button, when there is none. */
function BookingHint({ tour, departures, user }: { tour: Tour; departures: Departure[]; user: User | null }) {
  const firstBookable = departures.find((departure) => departure.is_bookable)
  if (!firstBookable) {
    return <Alert tone="info">All upcoming dates are fully booked.</Alert>
  }
  if (!user) {
    return (
      <ButtonLink to={`/login?redirect=${encodeURIComponent(`/tours/${tour.id}`)}`} className="w-full">
        Log in to book
      </ButtonLink>
    )
  }
  if (user.role !== 'traveler') {
    return <Alert tone="info">Bookings are made with a traveler account.</Alert>
  }
  if (!user.email_verified) {
    return <Alert tone="warning">Confirm your e-mail address to book this tour.</Alert>
  }
  return null
}
