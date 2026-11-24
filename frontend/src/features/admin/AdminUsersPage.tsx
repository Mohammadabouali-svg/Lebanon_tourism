import { useState, type FormEvent } from 'react'
import { Alert } from '@/components/ui/Alert'
import { Badge, StatusBadge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog, Dialog } from '@/components/ui/Dialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { Select, TextField } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Pagination } from '@/components/ui/Pagination'
import { PageSpinner } from '@/components/ui/Spinner'
import { TBody, THead, Table, Td, Th, Tr } from '@/components/ui/Table'
import { useCurrentUser } from '@/features/auth/hooks'
import { useFormFields } from '@/hooks/useFormFields'
import { useQueryFilters } from '@/hooks/useQueryFilters'
import { parseApiError } from '@/lib/api'
import { formatMoney, parseDollarsToCents } from '@/lib/money'
import type { User } from '@/lib/types'
import { SearchInput } from './SearchInput'
import { useAdminUsers, useCreateAdmin, useCreditWallet, useSuspendUser } from './queries'

export function AdminUsersPage() {
  const { user: me } = useCurrentUser()
  const { values, params, update, setPage } = useQueryFilters(['role', 'search'] as const)
  const users = useAdminUsers(params)
  const [creditFor, setCreditFor] = useState<User | null>(null)
  const [suspendTarget, setSuspendTarget] = useState<User | null>(null)
  const [inviting, setInviting] = useState(false)
  const suspend = useSuspendUser()

  return (
    <>
      <PageHeader title="Users" actions={<Button onClick={() => setInviting(true)}>Invite an admin</Button>} />

      <div className="mb-6 flex flex-col gap-3 sm:flex-row">
        <Select aria-label="Role" value={values.role} onChange={(event) => update('role', event.target.value)} className="w-full sm:w-48">
          <option value="">All roles</option>
          <option value="traveler">Travelers</option>
          <option value="operator">Operators</option>
          <option value="admin">Admins</option>
        </Select>
        <SearchInput key={values.search} value={values.search} onSearch={(value) => update('search', value)} placeholder="Name or e-mail" />
      </div>

      {users.isPending ? (
        <PageSpinner />
      ) : users.isError ? (
        <Alert tone="error">{parseApiError(users.error).message}</Alert>
      ) : users.data.data.length === 0 ? (
        <EmptyState title="No users found" />
      ) : (
        <>
          <Table>
            <THead>
              <tr><Th>User</Th><Th>Role</Th><Th>E-mail</Th><Th className="text-right">Wallet</Th><Th>Status</Th><Th><span className="sr-only">Actions</span></Th></tr>
            </THead>
            <TBody>
              {users.data.data.map((user) => (
                <Tr key={user.id}>
                  <Td>
                    <span className="font-medium text-stone-900">{user.name}</span>
                    <span className="block text-xs text-stone-500">{user.email}</span>
                  </Td>
                  <Td><Badge tone={user.role === 'admin' ? 'blue' : 'neutral'}>{user.role}</Badge></Td>
                  <Td>{user.email_verified ? <Badge tone="green">Verified</Badge> : <Badge tone="yellow">Unverified</Badge>}</Td>
                  <Td className="whitespace-nowrap text-right">{formatMoney(user.wallet_balance_cents ?? 0)}</Td>
                  <Td><StatusBadge status={user.suspended ? 'suspended' : 'active'} /></Td>
                  <Td>
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" size="sm" onClick={() => setCreditFor(user)}>Add credit</Button>
                      {user.id !== me?.id && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => {
                            suspend.reset()
                            setSuspendTarget(user)
                          }}
                        >
                          {user.suspended ? 'Reactivate' : 'Suspend'}
                        </Button>
                      )}
                    </div>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
          <Pagination meta={users.data.meta} onPageChange={setPage} />
        </>
      )}

      {creditFor && <CreditWalletDialog user={creditFor} onClose={() => setCreditFor(null)} />}
      {inviting && <InviteAdminDialog onClose={() => setInviting(false)} />}

      <ConfirmDialog
        open={suspendTarget !== null}
        title={suspendTarget?.suspended ? `Reactivate ${suspendTarget.name}?` : `Suspend ${suspendTarget?.name ?? ''}?`}
        description={
          suspendTarget?.suspended
            ? 'They will be able to log in again.'
            : 'They are logged out immediately and cannot log in until reactivated. Their bookings are kept.'
        }
        confirmLabel={suspendTarget?.suspended ? 'Reactivate' : 'Suspend'}
        tone={suspendTarget?.suspended ? 'primary' : 'danger'}
        loading={suspend.isPending}
        error={suspend.isError && <Alert tone="error">{parseApiError(suspend.error).message}</Alert>}
        onCancel={() => setSuspendTarget(null)}
        onConfirm={() =>
          suspendTarget && suspend.mutate({ id: suspendTarget.id, suspend: !suspendTarget.suspended }, { onSuccess: () => setSuspendTarget(null) })
        }
      />
    </>
  )
}

function CreditWalletDialog({ user, onClose }: { user: User; onClose: () => void }) {
  const credit = useCreditWallet()
  const { values, field } = useFormFields({ amount: '', description: '' })
  const [amountError, setAmountError] = useState<string>()
  const error = credit.error ? parseApiError(credit.error) : null

  function submit(event: FormEvent) {
    event.preventDefault()
    const cents = parseDollarsToCents(values.amount)
    if (cents === null) {
      setAmountError('Enter an amount like 50 or 49.99')
      return
    }
    setAmountError(undefined)
    credit.mutate({ id: user.id, amount_cents: cents, description: values.description }, { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} title={`Add credit for ${user.name}`}>
      <form onSubmit={submit} className="space-y-4" noValidate>
        <p className="text-sm text-stone-600">Current balance: {formatMoney(user.wallet_balance_cents ?? 0)}. Every credit is recorded in the wallet ledger with your name.</p>
        {error && Object.keys(error.fieldErrors).length === 0 && <Alert tone="error">{error.message}</Alert>}
        <TextField label="Amount (USD)" inputMode="decimal" placeholder="50.00" {...field('amount')} error={amountError ?? error?.fieldErrors.amount_cents} hint="Between $1 and $10,000." />
        <TextField label="Reason" placeholder="Goodwill gesture for the delayed bus" {...field('description')} error={error?.fieldErrors.description} />
        <div className="flex justify-end gap-3">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={credit.isPending}>Add credit</Button>
        </div>
      </form>
    </Dialog>
  )
}

function InviteAdminDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateAdmin()
  const { values, field } = useFormFields({ name: '', email: '' })
  const error = create.error ? parseApiError(create.error) : null

  function submit(event: FormEvent) {
    event.preventDefault()
    create.mutate(values)
  }

  return (
    <Dialog open onClose={onClose} title="Invite an administrator">
      {create.isSuccess ? (
        <>
          <Alert tone="success">Invitation sent to {values.email}.</Alert>
          <div className="mt-4 flex justify-end"><Button onClick={onClose}>Close</Button></div>
        </>
      ) : (
        <form onSubmit={submit} className="space-y-4" noValidate>
          {/* Nobody types a password for someone else: the new admin chooses it from the e-mail link. */}
          <p className="text-sm text-stone-600">
            The new administrator receives an e-mail with a link to choose their own password. You never see or send their password.
          </p>
          {error && Object.keys(error.fieldErrors).length === 0 && <Alert tone="error">{error.message}</Alert>}
          <TextField label="Full name" {...field('name')} error={error?.fieldErrors.name} />
          <TextField label="E-mail" type="email" {...field('email')} error={error?.fieldErrors.email} />
          <div className="flex justify-end gap-3">
            <Button variant="secondary" onClick={onClose}>Cancel</Button>
            <Button type="submit" loading={create.isPending}>Send invitation</Button>
          </div>
        </form>
      )}
    </Dialog>
  )
}
