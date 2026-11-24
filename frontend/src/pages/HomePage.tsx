import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { Button, ButtonLink } from '@/components/ui/Button'
import { DocumentTitle } from '@/components/ui/PageHeader'
import { PageSpinner } from '@/components/ui/Spinner'
import { TourCard } from '@/features/tours/TourCard'
import { useReferenceData, useTours } from '@/features/tours/queries'
import { parseApiError } from '@/lib/api'

export function HomePage() {
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const upcoming = useTours({ per_page: '6' })
  const reference = useReferenceData()

  function submit(event: FormEvent) {
    event.preventDefault()
    const query = search.trim()
    navigate(query ? `/tours?search=${encodeURIComponent(query)}` : '/tours')
  }

  return (
    <>
      <DocumentTitle title="Guided tours across Lebanon" />
      <section className="bg-linear-to-br from-brand-900 via-brand-800 to-sea-700 text-white">
        <div className="mx-auto max-w-6xl px-4 py-20 sm:py-28">
          <p className="text-sm font-semibold uppercase tracking-widest text-brand-200">Discover Lebanon</p>
          <h1 className="mt-3 max-w-2xl text-4xl font-semibold tracking-tight sm:text-5xl">
            From Byblos to the Cedars, with people who live there.
          </h1>
          <p className="mt-4 max-w-xl text-lg text-brand-100">Small-group tours led by local operators, booked in a few clicks.</p>

          <form role="search" onSubmit={submit} className="mt-8 flex max-w-xl flex-col gap-2 sm:flex-row">
            <label htmlFor="home-search" className="sr-only">Search tours</label>
            <input
              id="home-search"
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Where do you want to go?"
              className="flex-1 rounded-lg border-0 bg-white px-4 py-3 text-stone-900 placeholder:text-stone-400"
            />
            <Button type="submit" variant="secondary" className="py-3">
              Search tours
            </Button>
          </form>

          {reference.data && (
            <div className="mt-6 flex flex-wrap gap-2">
              {reference.data.categories.map((category) => (
                <Link
                  key={category.id}
                  to={`/tours?category_id=${category.id}`}
                  className="rounded-full bg-white/10 px-3 py-1 text-sm text-white ring-1 ring-white/20 hover:bg-white/20"
                >
                  {category.name}
                </Link>
              ))}
            </div>
          )}
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-14">
        <div className="mb-6 flex items-end justify-between gap-4">
          <h2 className="text-2xl font-semibold text-stone-900">Upcoming departures</h2>
          <Link to="/tours" className="text-sm font-medium text-brand-700 hover:underline">See all tours →</Link>
        </div>
        {upcoming.isPending ? (
          <PageSpinner />
        ) : upcoming.isError ? (
          <Alert tone="error">{parseApiError(upcoming.error).message}</Alert>
        ) : (
          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {upcoming.data.data.map((tour) => (
              <TourCard key={tour.id} tour={tour} />
            ))}
          </div>
        )}
      </section>

      <section className="border-t border-stone-200 bg-white">
        <div className="mx-auto grid max-w-6xl gap-8 px-4 py-14 md:grid-cols-3">
          <Feature title="Approved local operators" text="Every company is reviewed by our team before it can publish a single tour." />
          <Feature title="Honest reviews" text="Only travelers who booked a tour can review it, once." />
          <Feature title="Flexible plans" text="Cancel before departure and get your money back as travel credit." />
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-14 text-center">
        <h2 className="text-2xl font-semibold text-stone-900">Run tours in Lebanon?</h2>
        <p className="mt-2 text-stone-600">Reach travelers from around the world. Registration is free.</p>
        <ButtonLink to="/register/operator" className="mt-6">Register your company</ButtonLink>
      </section>
    </>
  )
}

function Feature({ title, text }: { title: string; text: string }) {
  return (
    <div>
      <h3 className="font-semibold text-stone-900">{title}</h3>
      <p className="mt-1 text-sm text-stone-600">{text}</p>
    </div>
  )
}
