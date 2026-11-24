import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Field'

/** Search box that applies on submit (Enter), not on every key press. */
export function SearchInput({ value, onSearch, placeholder }: { value: string; onSearch: (value: string) => void; placeholder: string }) {
  const [draft, setDraft] = useState(value)

  function submit(event: FormEvent) {
    event.preventDefault()
    onSearch(draft.trim())
  }

  return (
    <form role="search" onSubmit={submit} className="flex gap-2">
      <Input type="search" aria-label="Search" placeholder={placeholder} value={draft} onChange={(event) => setDraft(event.target.value)} className="min-w-0 flex-1 sm:w-72" />
      <Button type="submit" variant="secondary">Search</Button>
    </form>
  )
}
