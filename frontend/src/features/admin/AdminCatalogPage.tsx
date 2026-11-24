import { useState, type FormEvent } from 'react'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { CardSection } from '@/components/ui/Card'
import { Input, Select } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Spinner } from '@/components/ui/Spinner'
import { parseApiError } from '@/lib/api'
import type { City, Region } from '@/lib/types'
import { useCatalog, useCatalogMutation, type CatalogKind } from './queries'

/*
 * Regions, cities and categories: the reference data every tour points to.
 * Deleting something still in use is refused by the API with a clear
 * message ("still_in_use"), which is shown next to the list.
 */
export function AdminCatalogPage() {
  return (
    <>
      <PageHeader title="Catalog" description="Regions, cities and categories used by tours and search filters." />
      <div className="grid gap-6 xl:grid-cols-2">
        <NamedListSection kind="regions" title="Regions" countLabel={(item) => `${item.cities_count ?? 0} cities`} />
        <NamedListSection kind="categories" title="Categories" countLabel={(item) => `${item.tours_count ?? 0} tours`} />
        <CitiesSection />
      </div>
    </>
  )
}

interface Named {
  id: number
  name: string
  cities_count?: number
  tours_count?: number
}

function NamedListSection({ kind, title, countLabel }: { kind: Exclude<CatalogKind, 'cities'>; title: string; countLabel: (item: Named) => string }) {
  const list = useCatalog(kind)
  const mutation = useCatalogMutation(kind)
  const [name, setName] = useState('')
  const error = mutation.error ? parseApiError(mutation.error) : null

  function create(event: FormEvent) {
    event.preventDefault()
    mutation.mutate({ id: null, payload: { name } }, { onSuccess: () => setName('') })
  }

  return (
    <CardSection title={title}>
      <form onSubmit={create} className="mb-4 flex gap-2">
        <Input aria-label={`New ${title.toLowerCase()} name`} placeholder="Name" value={name} onChange={(event) => setName(event.target.value)} className="min-w-0 flex-1" />
        <Button type="submit" variant="secondary" loading={mutation.isPending && mutation.variables?.id === null}>Add</Button>
      </form>
      {error && <Alert tone="error" className="mb-4">{error.fieldErrors.name ?? error.message}</Alert>}
      {list.isPending ? (
        <Spinner />
      ) : list.isError ? (
        <Alert tone="error">{parseApiError(list.error).message}</Alert>
      ) : (
        <ul className="divide-y divide-stone-100">
          {(list.data as Named[]).map((item) => (
            <EditableRow
              key={item.id}
              label={item.name}
              meta={countLabel(item)}
              busy={mutation.isPending && mutation.variables?.id === item.id}
              onRename={(newName) => mutation.mutate({ id: item.id, payload: { name: newName } })}
              onDelete={() => mutation.mutate({ id: item.id, remove: true })}
            />
          ))}
        </ul>
      )}
    </CardSection>
  )
}

function CitiesSection() {
  const cities = useCatalog('cities')
  const regions = useCatalog('regions')
  const mutation = useCatalogMutation('cities')
  const [name, setName] = useState('')
  const [regionId, setRegionId] = useState('')
  const error = mutation.error ? parseApiError(mutation.error) : null

  function create(event: FormEvent) {
    event.preventDefault()
    mutation.mutate({ id: null, payload: { name, region_id: Number(regionId) } }, { onSuccess: () => setName('') })
  }

  return (
    <CardSection title="Cities" className="xl:col-span-2">
      <form onSubmit={create} className="mb-4 flex flex-col gap-2 sm:flex-row">
        <Select aria-label="Region of the new city" value={regionId} onChange={(event) => setRegionId(event.target.value)} className="w-full sm:w-56">
          <option value="">Choose a region</option>
          {(regions.data as Region[] | undefined)?.map((region) => (
            <option key={region.id} value={region.id}>{region.name}</option>
          ))}
        </Select>
        <Input aria-label="New city name" placeholder="City name" value={name} onChange={(event) => setName(event.target.value)} className="min-w-0 flex-1" />
        <Button type="submit" variant="secondary" loading={mutation.isPending && mutation.variables?.id === null}>Add city</Button>
      </form>
      {error && (
        <Alert tone="error" className="mb-4">{error.fieldErrors.name ?? error.fieldErrors.region_id ?? error.message}</Alert>
      )}
      {cities.isPending ? (
        <Spinner />
      ) : cities.isError ? (
        <Alert tone="error">{parseApiError(cities.error).message}</Alert>
      ) : (
        <ul className="grid divide-y divide-stone-100 md:grid-cols-2 md:gap-x-8">
          {(cities.data as City[]).map((city) => (
            <EditableRow
              key={city.id}
              label={city.name}
              meta={`${city.region?.name ?? ''} · ${city.tours_count ?? 0} tours`}
              busy={mutation.isPending && mutation.variables?.id === city.id}
              onRename={(newName) => mutation.mutate({ id: city.id, payload: { name: newName, region_id: city.region_id } })}
              onDelete={() => mutation.mutate({ id: city.id, remove: true })}
            />
          ))}
        </ul>
      )}
    </CardSection>
  )
}

/** A list row that switches to an inline text input for renaming. */
function EditableRow({ label, meta, busy, onRename, onDelete }: {
  label: string
  meta: string
  busy: boolean
  onRename: (name: string) => void
  onDelete: () => void
}) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState(label)

  function submit(event: FormEvent) {
    event.preventDefault()
    if (draft.trim() && draft.trim() !== label) onRename(draft.trim())
    setEditing(false)
  }

  return (
    <li className="flex items-center justify-between gap-3 py-2">
      {editing ? (
        <form onSubmit={submit} className="flex flex-1 gap-2">
          <Input aria-label={`Rename ${label}`} value={draft} onChange={(event) => setDraft(event.target.value)} autoFocus className="min-w-0 flex-1" />
          <Button type="submit" size="sm">Save</Button>
          <Button size="sm" variant="ghost" onClick={() => { setDraft(label); setEditing(false) }}>Cancel</Button>
        </form>
      ) : (
        <>
          <span>
            <span className="font-medium text-stone-900">{label}</span>
            <span className="ml-2 text-xs text-stone-500">{meta}</span>
          </span>
          <span className="flex shrink-0 gap-1">
            {busy && <Spinner className="size-4" />}
            <Button size="sm" variant="ghost" onClick={() => setEditing(true)}>Rename</Button>
            <Button size="sm" variant="ghost" onClick={onDelete} aria-label={`Delete ${label}`}>Delete</Button>
          </span>
        </>
      )}
    </li>
  )
}
