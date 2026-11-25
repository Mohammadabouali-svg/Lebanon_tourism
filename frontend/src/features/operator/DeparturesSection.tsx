import { useState, type FormEvent } from 'react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { CardSection } from '@/components/ui/Card'
import { ConfirmDialog } from '@/components/ui/Dialog'
import { Input, TextField } from '@/components/ui/Field'
import { Spinner } from '@/components/ui/Spinner'
import { TBody, THead, Table, Td, Th, Tr } from '@/components/ui/Table'
import { useFormFields } from '@/hooks/useFormFields'
import { parseApiError } from '@/lib/api'
import { formatDate, formatDateRange, todayIso } from '@/lib/dates'
import type { Departure, Tour } from '@/lib/types'
import { useAddDepartures, useDeleteDeparture, useTourDepartures, useUpdateDeparture } from './queries'

const MAX_WEEKS = 26 // mirrors StoreDepartureRequest::MAX_WEEKS

/*
 * The dates a tour runs on (Exercise 10). Shown on the tour's edit page,
 * outside the tour form (a form cannot contain another form).
 *
 * Every rule is the server's: a booked date cannot move, capacity cannot go
 * below the booked spots, booked or past departures cannot be deleted. The
 * buttons hidden here are a convenience; the API refuses with a `code`.
 */
export function DeparturesSection({ tour }: { tour: Tour }) {
  const departures = useTourDepartures(tour.id)
  const [toDelete, setToDelete] = useState<Departure | null>(null)
  const remove = useDeleteDeparture()

  return (
    <CardSection
      title="Departures"
      description={`The dates this ${tour.duration_days}-day tour runs on. Each date has its own group size. Only published tours with an upcoming date appear in the catalogue.`}
    >
      <AddDeparturesForm tour={tour} />

      <div className="mt-6">
        {departures.isPending ? (
          <Spinner />
        ) : departures.isError ? (
          <Alert tone="error">{parseApiError(departures.error).message}</Alert>
        ) : departures.data.length === 0 ? (
          <p className="text-sm text-stone-500">No dates yet. Add the first one above.</p>
        ) : (
          <Table>
            <THead>
              <tr>
                <Th>Dates</Th>
                <Th className="text-right">Group size</Th>
                <Th className="text-right">Booked spots</Th>
                <Th className="text-right">Bookings</Th>
                <Th><span className="sr-only">Actions</span></Th>
              </tr>
            </THead>
            <TBody>
              {departures.data.map((departure) => (
                <DepartureRow
                  key={departure.id}
                  departure={departure}
                  onDelete={() => {
                    remove.reset()
                    setToDelete(departure)
                  }}
                />
              ))}
            </TBody>
          </Table>
        )}
      </div>

      <ConfirmDialog
        open={toDelete !== null}
        title={`Delete the departure of ${toDelete ? formatDate(toDelete.starts_on) : ''}?`}
        description="Departures that have bookings cannot be deleted."
        confirmLabel="Delete departure"
        loading={remove.isPending}
        // e.g. code "departure_has_bookings" from BusinessRuleException
        error={remove.isError && <Alert tone="error">{parseApiError(remove.error).message}</Alert>}
        onCancel={() => setToDelete(null)}
        onConfirm={() => {
          if (toDelete) remove.mutate(toDelete.id, { onSuccess: () => setToDelete(null) })
        }}
      />
    </CardSection>
  )
}

function AddDeparturesForm({ tour }: { tour: Tour }) {
  const add = useAddDepartures(tour.id)
  const { values, field } = useFormFields({ starts_on: '', capacity: '12', weeks: '1' })
  const [added, setAdded] = useState<number | null>(null)
  const errors = add.error ? parseApiError(add.error).fieldErrors : {}

  function submit(event: FormEvent) {
    event.preventDefault()
    setAdded(null)
    add.mutate(
      { starts_on: values.starts_on, capacity: Number(values.capacity), weeks: Number(values.weeks) || 1 },
      { onSuccess: (created) => setAdded(created.length) },
    )
  }

  return (
    <form onSubmit={submit} noValidate className="rounded-lg border border-stone-200 p-4">
      <h3 className="text-sm font-semibold text-stone-900">Add dates</h3>
      <div className="mt-3 grid gap-4 sm:grid-cols-3">
        <TextField label="First date" type="date" min={todayIso(1)} {...field('starts_on')} error={errors.starts_on} />
        <TextField label="Group size" type="number" min={1} max={500} {...field('capacity')} error={errors.capacity} />
        <TextField
          label="Repeat weekly for"
          type="number"
          min={1}
          max={MAX_WEEKS}
          {...field('weeks')}
          error={errors.weeks}
          hint="1 = just this date; 4 = this date and the same weekday for 3 more weeks."
        />
      </div>
      <div className="mt-4 flex flex-wrap items-center gap-3">
        <Button type="submit" size="sm" loading={add.isPending}>Add</Button>
        {added !== null && <span className="text-sm text-green-700" role="status">{added} date{added > 1 ? 's' : ''} added.</span>}
        {add.isError && Object.keys(errors).length === 0 && <span className="text-sm text-red-600">{parseApiError(add.error).message}</span>}
      </div>
    </form>
  )
}

function DepartureRow({ departure, onDelete }: { departure: Departure; onDelete: () => void }) {
  const [editing, setEditing] = useState(false)
  // Display only: the server decides "started" with its own clock.
  const started = departure.starts_on <= todayIso()

  if (editing) return <EditDepartureRow departure={departure} onDone={() => setEditing(false)} />

  return (
    <Tr className={started ? 'text-stone-400' : undefined}>
      <Td className="whitespace-nowrap">
        {formatDateRange(departure.starts_on, departure.ends_on)}
        {started && <Badge className="ml-2">Started</Badge>}
      </Td>
      <Td className="text-right">{departure.capacity}</Td>
      <Td className="text-right">{departure.booked_spots}</Td>
      <Td className="text-right">{departure.bookings_count ?? 0}</Td>
      <Td>
        {!started && (
          <div className="flex justify-end gap-2">
            <Button variant="secondary" size="sm" onClick={() => setEditing(true)}>Edit</Button>
            {(departure.bookings_count ?? 0) === 0 && (
              <Button variant="ghost" size="sm" onClick={onDelete}>Delete</Button>
            )}
          </div>
        )}
      </Td>
    </Tr>
  )
}

function EditDepartureRow({ departure, onDone }: { departure: Departure; onDone: () => void }) {
  const update = useUpdateDeparture()
  const [startsOn, setStartsOn] = useState(departure.starts_on)
  const [capacity, setCapacity] = useState(String(departure.capacity))
  const error = update.error ? parseApiError(update.error) : null
  // The date of a departure with any booking, even a cancelled one, cannot
  // change (code departure_has_bookings).
  const dateLocked = (departure.bookings_count ?? 0) > 0

  function save() {
    update.mutate({ id: departure.id, starts_on: startsOn, capacity: Number(capacity) }, { onSuccess: onDone })
  }

  return (
    <Tr>
      <Td colSpan={5}>
        <div className="flex flex-wrap items-end gap-3">
          <label className="text-sm">
            <span className="block text-stone-600">Date</span>
            <Input type="date" min={todayIso(1)} value={startsOn} disabled={dateLocked} onChange={(event) => setStartsOn(event.target.value)} />
          </label>
          <label className="text-sm">
            <span className="block text-stone-600">Group size</span>
            <Input type="number" min={Math.max(1, departure.booked_spots)} max={500} value={capacity} onChange={(event) => setCapacity(event.target.value)} className="w-24" />
          </label>
          <Button size="sm" loading={update.isPending} onClick={save}>Save</Button>
          <Button variant="ghost" size="sm" onClick={onDone}>Cancel</Button>
        </div>
        {dateLocked && <p className="mt-2 text-xs text-stone-500">Travelers booked this date, so it cannot move. You can still change the group size.</p>}
        {error && (
          <p className="mt-2 text-sm text-red-600" role="alert">
            {error.fieldErrors.starts_on ?? error.fieldErrors.capacity ?? error.message}
          </p>
        )}
      </Td>
    </Tr>
  )
}
