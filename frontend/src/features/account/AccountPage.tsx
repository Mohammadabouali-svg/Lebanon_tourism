import { useState, type FormEvent } from 'react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { CardSection } from '@/components/ui/Card'
import { TextField } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Pagination } from '@/components/ui/Pagination'
import { Spinner } from '@/components/ui/Spinner'
import { TBody, THead, Table, Td, Th, Tr } from '@/components/ui/Table'
import { useCurrentUser } from '@/features/auth/hooks'
import { useFormFields } from '@/hooks/useFormFields'
import { parseApiError } from '@/lib/api'
import { formatDateTime } from '@/lib/dates'
import { formatMoney } from '@/lib/money'
import type { User } from '@/lib/types'
import { useUpdatePassword, useUpdateProfile, useWallet } from './queries'

export function AccountPage() {
  const { user } = useCurrentUser()
  if (!user) return null // RequireAuth guarantees a user; this keeps TypeScript happy.

  return (
    <div className="mx-auto max-w-4xl space-y-6 px-4 py-10">
      <PageHeader title="My account" description={user.email} />
      <ProfileForm user={user} />
      <PasswordForm />
      {user.role === 'traveler' && <WalletSection />}
    </div>
  )
}

function ProfileForm({ user }: { user: User }) {
  const update = useUpdateProfile()
  const { values, field } = useFormFields({ name: user.name, phone: user.phone ?? '' })
  const error = update.error ? parseApiError(update.error) : null

  function submit(event: FormEvent) {
    event.preventDefault()
    update.mutate(values)
  }

  return (
    <CardSection title="Profile" description="Your e-mail address cannot be changed here.">
      <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
        <TextField label="Full name" autoComplete="name" {...field('name')} error={error?.fieldErrors.name} />
        <TextField label="Phone" type="tel" autoComplete="tel" {...field('phone')} error={error?.fieldErrors.phone} />
        <div className="flex items-center gap-3 sm:col-span-2">
          <Button type="submit" loading={update.isPending}>Save profile</Button>
          {update.isSuccess && <span role="status" className="text-sm text-brand-700">Saved.</span>}
        </div>
      </form>
    </CardSection>
  )
}

function PasswordForm() {
  const update = useUpdatePassword()
  const { values, field, reset } = useFormFields({ current_password: '', password: '', password_confirmation: '' })
  const error = update.error ? parseApiError(update.error) : null

  function submit(event: FormEvent) {
    event.preventDefault()
    update.mutate(values, { onSuccess: reset })
  }

  return (
    <CardSection title="Password" description="Changing your password signs you out on every other device.">
      <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3" noValidate>
        <TextField label="Current password" type="password" autoComplete="current-password" {...field('current_password')} error={error?.fieldErrors.current_password} />
        <TextField label="New password" type="password" autoComplete="new-password" {...field('password')} error={error?.fieldErrors.password} />
        <TextField label="Confirm new password" type="password" autoComplete="new-password" {...field('password_confirmation')} />
        <div className="flex items-center gap-3 sm:col-span-3">
          <Button type="submit" loading={update.isPending}>Change password</Button>
          {update.isSuccess && <span role="status" className="text-sm text-brand-700">Password changed.</span>}
        </div>
      </form>
    </CardSection>
  )
}

function WalletSection() {
  const [page, setPage] = useState(1)
  const wallet = useWallet(page)

  return (
    <CardSection
      title="Wallet"
      description="Travel credit added by our team and refunds of cancelled bookings."
      actions={wallet.data && <p className="text-2xl font-semibold text-stone-900">{formatMoney(wallet.data.balance_cents)}</p>}
    >
      {wallet.isPending ? (
        <Spinner />
      ) : wallet.isError ? (
        <Alert tone="error">{parseApiError(wallet.error).message}</Alert>
      ) : wallet.data.data.length === 0 ? (
        <p className="text-sm text-stone-500">No transactions yet.</p>
      ) : (
        <>
          <Table>
            <THead>
              <tr><Th>Date</Th><Th>Description</Th><Th className="text-right">Amount</Th><Th className="text-right">Balance</Th></tr>
            </THead>
            <TBody>
              {wallet.data.data.map((transaction) => (
                <Tr key={transaction.id}>
                  <Td className="whitespace-nowrap">{formatDateTime(transaction.created_at)}</Td>
                  <Td>{transaction.description}</Td>
                  <Td className="whitespace-nowrap text-right">
                    <Badge tone={transaction.type === 'credit' ? 'green' : 'neutral'}>
                      {transaction.type === 'credit' ? '+' : '−'} {formatMoney(transaction.amount_cents)}
                    </Badge>
                  </Td>
                  <Td className="whitespace-nowrap text-right">{formatMoney(transaction.balance_after_cents)}</Td>
                </Tr>
              ))}
            </TBody>
          </Table>
          <Pagination meta={wallet.data.meta} onPageChange={setPage} />
        </>
      )}
    </CardSection>
  )
}
