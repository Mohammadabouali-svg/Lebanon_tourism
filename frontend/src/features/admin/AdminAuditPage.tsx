import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { Select } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Pagination } from '@/components/ui/Pagination'
import { PageSpinner } from '@/components/ui/Spinner'
import { TBody, THead, Table, Td, Th, Tr } from '@/components/ui/Table'
import { useQueryFilters } from '@/hooks/useQueryFilters'
import { parseApiError } from '@/lib/api'
import { formatDateTime } from '@/lib/dates'
import { formatMoney } from '@/lib/money'
import type { AuditAction, AuditChange, AuditEvent } from '@/lib/types'
import { useAuditEvents } from './queries'

/*
 * The admin audit log (Exercise 12, D25): who changed what, newest first.
 * Read-only. Events are written by the API in the same transaction as the
 * change, so there is nothing to create or edit here. Filters live in the URL;
 * clicking an admin's name shows only their actions.
 */

const actionLabels: Record<AuditAction, string> = {
  'admin.created': 'Admin created',
  'operator.approved': 'Operator approved',
  'operator.suspended': 'Operator suspended',
  'tour.status_changed': 'Tour status changed',
  'user.suspended': 'User suspended',
  'user.unsuspended': 'User unsuspended',
  'wallet.credited': 'Wallet credited',
}

export function AdminAuditPage() {
  const { values, params, update, setPage } = useQueryFilters(['action', 'actor_id'] as const)
  const events = useAuditEvents(params)
  const filtered = values.action !== '' || values.actor_id !== ''

  return (
    <>
      <PageHeader title="Audit log" description="Every approval, suspension, wallet credit and tour status change, with the admin who made it." />

      <div className="mb-6 flex flex-wrap items-center gap-3">
        <Select aria-label="Action" value={values.action} onChange={(event) => update('action', event.target.value)} className="w-full sm:w-64">
          <option value="">All actions</option>
          {Object.entries(actionLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </Select>
        {values.actor_id !== '' && (
          <Button variant="ghost" size="sm" onClick={() => update('actor_id', '')}>Show all admins</Button>
        )}
      </div>

      {events.isPending ? (
        <PageSpinner />
      ) : events.isError ? (
        <Alert tone="error">{parseApiError(events.error).message}</Alert>
      ) : events.data.data.length === 0 ? (
        <EmptyState title={filtered ? 'No events match these filters' : 'Nothing recorded yet'} description="Admin actions appear here as soon as they are made." />
      ) : (
        <>
          <Table>
            <THead>
              <tr><Th>When</Th><Th>Admin</Th><Th>Action</Th><Th>Subject</Th><Th>Change</Th></tr>
            </THead>
            <TBody>
              {events.data.data.map((event) => (
                <Tr key={event.id}>
                  <Td className="whitespace-nowrap text-xs">{formatDateTime(event.created_at)}</Td>
                  <Td>
                    <button
                      type="button"
                      className="text-left font-medium text-stone-900 hover:underline focus-visible:underline"
                      onClick={() => update('actor_id', String(event.actor.id))}
                      title="Show only this admin's actions"
                    >
                      {event.actor.name}
                    </button>
                    <span className="block text-xs text-stone-500">{event.actor.email}</span>
                  </Td>
                  <Td>{actionLabels[event.action]}</Td>
                  <Td>
                    {event.subject.label ?? <span className="text-stone-500">(deleted)</span>}
                    <span className="block text-xs text-stone-500">{event.subject.type} #{event.subject.id}</span>
                  </Td>
                  <Td className="text-sm">{describeChanges(event)}</Td>
                </Tr>
              ))}
            </TBody>
          </Table>
          <Pagination meta={events.data.meta} onPageChange={setPage} />
        </>
      )}
    </>
  )
}

/** "status: pending → approved", "locked: no → yes", or "+$50.00 (ledger entry #12)". */
function describeChanges(event: AuditEvent): string {
  const changes = event.changes
  if (changes === null) return '—'

  if (event.action === 'wallet.credited') {
    const amount = changes.amount_cents
    const entry = changes.wallet_transaction_id
    return typeof amount === 'number' ? `+${formatMoney(amount)} (ledger entry #${String(entry)})` : '—'
  }

  return Object.entries(changes)
    .filter((entry): entry is [string, AuditChange] => typeof entry[1] === 'object')
    .map(([field, change]) => `${field}: ${show(change.from)} → ${show(change.to)}`)
    .join(', ')
}

function show(value: AuditChange['from']): string {
  if (typeof value === 'boolean') return value ? 'yes' : 'no'
  return value ?? '—'
}
