import { useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { Button, ButtonLink } from '@/components/ui/Button'
import { CardSection } from '@/components/ui/Card'
import { Field, Input, SelectField, TextField, TextareaField } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { PageSpinner } from '@/components/ui/Spinner'
import { useReferenceData } from '@/features/tours/queries'
import { useFilePreview } from '@/hooks/useFilePreview'
import { useFormFields } from '@/hooks/useFormFields'
import { isStatus, parseApiError, type FieldErrors } from '@/lib/api'
import { todayIso } from '@/lib/dates'
import { centsToDollarInput, parseDollarsToCents } from '@/lib/money'
import type { ReferenceData, Tour, TourStatus } from '@/lib/types'
import { NotFoundPage } from '@/pages/NotFoundPage'
import { useOperatorTour, useSaveTour } from './queries'

/** /operator/tours/new and /operator/tours/:id/edit */
export function TourFormPage() {
  const params = useParams()
  const id = params.id ? Number(params.id) : null
  const existing = useOperatorTour(id)
  const reference = useReferenceData()

  if ((id !== null && existing.isPending) || reference.isPending) return <PageSpinner />
  if (existing.isError) {
    // TourPolicy answers 403 for tours of other operators.
    if (isStatus(existing.error, 404) || isStatus(existing.error, 403)) return <NotFoundPage />
    return <Alert tone="error">{parseApiError(existing.error).message}</Alert>
  }
  if (reference.isError) return <Alert tone="error">{parseApiError(reference.error).message}</Alert>

  // `key` gives a fresh form (fresh state) when switching between tours.
  return <TourForm key={id ?? 'new'} tour={existing.data ?? null} reference={reference.data} />
}

interface ItineraryRow {
  key: string // stable React key, never sent to the server
  day: string
  title: string
  description: string
}

let rowCounter = 0
const newRow = (day = '1'): ItineraryRow => ({ key: `row-${++rowCounter}`, day, title: '', description: '' })

function TourForm({ tour, reference }: { tour: Tour | null; reference: ReferenceData }) {
  const navigate = useNavigate()
  const save = useSaveTour(tour?.id ?? null)
  const { values, field, set } = useFormFields({
    title: tour?.title ?? '',
    description: tour?.description ?? '',
    meeting_point: tour?.meeting_point ?? '',
    category_id: tour?.category ? String(tour.category.id) : '',
    region_id: tour?.city ? String(tour.city.region_id) : '',
    city_id: tour?.city ? String(tour.city.id) : '',
    price: tour ? centsToDollarInput(tour.price_cents) : '',
    capacity: tour ? String(tour.capacity) : '12',
    starts_on: tour?.starts_on ?? '',
    ends_on: tour?.ends_on ?? '',
    status: (tour?.status ?? 'draft') as TourStatus,
  })
  const [itinerary, setItinerary] = useState<ItineraryRow[]>(() =>
    tour?.itinerary?.length
      ? tour.itinerary.map((item) => ({ ...newRow(String(item.day)), title: item.title, description: item.description ?? '' }))
      : [newRow()],
  )
  const { file: image, previewUrl: preview, setFile: setImage } = useFilePreview()
  const [priceError, setPriceError] = useState<string>()

  const error = save.error ? parseApiError(save.error) : null
  const errors: FieldErrors = error?.fieldErrors ?? {}

  const cities = reference.regions.find((region) => String(region.id) === values.region_id)?.cities ?? []

  function updateRow(key: string, changes: Partial<ItineraryRow>) {
    setItinerary((rows) => rows.map((row) => (row.key === key ? { ...row, ...changes } : row)))
  }

  function submit(event: FormEvent) {
    event.preventDefault()

    // Convert dollars to cents here; the API only accepts integer cents.
    const priceCents = parseDollarsToCents(values.price)
    if (priceCents === null) {
      setPriceError('Enter a price like 65 or 65.50')
      return
    }
    setPriceError(undefined)

    // Laravel reads `itinerary[0][title]` as the array itinerary.0.title,
    // and reports errors back with the same dotted names.
    const form = new FormData()
    form.append('title', values.title)
    form.append('description', values.description)
    // Always sent, even empty: an empty string becomes null on the server,
    // which is how an operator removes a meeting point.
    form.append('meeting_point', values.meeting_point)
    form.append('category_id', values.category_id)
    form.append('city_id', values.city_id)
    form.append('price_cents', String(priceCents))
    form.append('capacity', values.capacity)
    form.append('starts_on', values.starts_on)
    form.append('ends_on', values.ends_on)
    form.append('status', values.status)
    itinerary.forEach((row, index) => {
      form.append(`itinerary[${index}][day]`, row.day)
      form.append(`itinerary[${index}][title]`, row.title)
      form.append(`itinerary[${index}][description]`, row.description)
    })
    if (image) form.append('image', image)

    save.mutate(form, {
      onSuccess: (saved) => navigate('/operator/tours', { state: { flash: `"${saved.title}" has been saved.` } }),
    })
  }

  return (
    <>
      <PageHeader title={tour ? 'Edit tour' : 'New tour'} description={tour?.title} />

      <form onSubmit={submit} className="space-y-6" noValidate>
        {error && Object.keys(errors).length === 0 && <Alert tone="error">{error.message}</Alert>}
        {Object.keys(errors).length > 0 && <Alert tone="error">Please correct the highlighted fields.</Alert>}

        <CardSection title="Basics">
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Title" fieldClassName="sm:col-span-2" {...field('title')} error={errors.title} />
            <TextareaField label="Description" fieldClassName="sm:col-span-2" rows={6} {...field('description')} error={errors.description} />
            <TextField
              label="Meeting point (optional)"
              fieldClassName="sm:col-span-2"
              hint="Where and when travelers meet the guide, e.g. “National Museum main gate, Beirut, 7:30 AM”."
              maxLength={255}
              {...field('meeting_point')}
              error={errors.meeting_point}
            />
            <SelectField label="Category" {...field('category_id')} error={errors.category_id}>
              <option value="">Choose a category</option>
              {reference.categories.map((category) => (
                <option key={category.id} value={category.id}>{category.name}</option>
              ))}
            </SelectField>
            <SelectField
              label="Status"
              {...field('status')}
              error={errors.status}
              hint="Only published tours are visible and bookable. Archive a tour to hide it while keeping its bookings."
            >
              <option value="draft">Draft</option>
              <option value="published">Published</option>
              <option value="archived">Archived</option>
            </SelectField>
            <SelectField
              label="Region"
              value={values.region_id}
              onChange={(event) => {
                set('region_id', event.target.value)
                set('city_id', '')
              }}
            >
              <option value="">Choose a region</option>
              {reference.regions.map((region) => (
                <option key={region.id} value={region.id}>{region.name}</option>
              ))}
            </SelectField>
            <SelectField label="City" {...field('city_id')} error={errors.city_id} disabled={!values.region_id}>
              <option value="">{values.region_id ? 'Choose a city' : 'Choose a region first'}</option>
              {cities.map((city) => (
                <option key={city.id} value={city.id}>{city.name}</option>
              ))}
            </SelectField>
          </div>
        </CardSection>

        <CardSection title="Dates, price and capacity">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <TextField label="First day" type="date" min={tour ? undefined : todayIso(1)} {...field('starts_on')} error={errors.starts_on} />
            <TextField label="Last day" type="date" min={values.starts_on || undefined} {...field('ends_on')} error={errors.ends_on} />
            <TextField label="Price per person (USD)" inputMode="decimal" placeholder="65.00" {...field('price')} error={priceError ?? errors.price_cents} />
            <TextField
              label="Capacity"
              type="number"
              min={1}
              max={500}
              {...field('capacity')}
              error={errors.capacity}
              hint={tour ? `${tour.booked_spots} spot(s) already booked` : undefined}
            />
          </div>
        </CardSection>

        <CardSection title="Itinerary" description="What happens on each day of the tour.">
          {errors.itinerary && <Alert tone="error" className="mb-4">{errors.itinerary}</Alert>}
          <ol className="space-y-4">
            {itinerary.map((row, index) => (
              <li key={row.key} className="grid gap-3 rounded-lg border border-stone-200 p-4 sm:grid-cols-[6rem_1fr_auto]">
                <Field label="Day" error={errors[`itinerary.${index}.day`]}>
                  {({ id, describedBy, invalid }) => (
                    <Input id={id} type="number" min={1} value={row.day} aria-invalid={invalid || undefined} aria-describedby={describedBy} onChange={(event) => updateRow(row.key, { day: event.target.value })} className="w-full" />
                  )}
                </Field>
                <div className="space-y-3">
                  <TextField label="Title" value={row.title} onChange={(event) => updateRow(row.key, { title: event.target.value })} error={errors[`itinerary.${index}.title`]} />
                  <TextareaField label="Description (optional)" rows={2} value={row.description} onChange={(event) => updateRow(row.key, { description: event.target.value })} error={errors[`itinerary.${index}.description`]} />
                </div>
                <div className="sm:pt-6">
                  <Button variant="ghost" size="sm" disabled={itinerary.length === 1} onClick={() => setItinerary((rows) => rows.filter((r) => r.key !== row.key))}>
                    Remove
                  </Button>
                </div>
              </li>
            ))}
          </ol>
          <Button variant="secondary" size="sm" className="mt-4" onClick={() => setItinerary((rows) => [...rows, newRow(rows.at(-1)?.day ?? '1')])}>
            Add itinerary item
          </Button>
        </CardSection>

        <CardSection title="Photo" description="JPG, PNG or WebP, at least 400×250 pixels, up to 5 MB.">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
            {(preview ?? tour?.image_url) && (
              <img src={preview ?? tour?.image_url ?? ''} alt="Tour" className="h-32 w-48 rounded-lg object-cover" />
            )}
            <Field label={tour?.image_url ? 'Replace photo' : 'Upload photo'} error={errors.image}>
              {({ id, describedBy }) => (
                <input
                  id={id}
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  aria-describedby={describedBy}
                  onChange={(event) => setImage(event.target.files?.[0] ?? null)}
                  className="block w-full text-sm text-stone-600 file:mr-3 file:rounded-lg file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-sm file:font-medium"
                />
              )}
            </Field>
          </div>
        </CardSection>

        <div className="flex flex-wrap gap-3">
          <Button type="submit" loading={save.isPending}>{tour ? 'Save changes' : 'Create tour'}</Button>
          <ButtonLink to="/operator/tours" variant="secondary">Cancel</ButtonLink>
        </div>
      </form>
    </>
  )
}
