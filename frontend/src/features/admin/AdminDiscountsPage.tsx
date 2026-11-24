import { useState, type FormEvent } from 'react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog, Dialog } from '@/components/ui/Dialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { Checkbox, SelectField, TextField } from '@/components/ui/Field'
import { PageHeader } from '@/components/ui/PageHeader'
import { Pagination } from '@/components/ui/Pagination'
import { PageSpinner } from '@/components/ui/Spinner'
import { TBody, THead, Table, Td, Th, Tr } from '@/components/ui/Table'
import { useFormFields } from '@/hooks/useFormFields'
import { useQueryFilters } from '@/hooks/useQueryFilters'
import { parseApiError } from '@/lib/api'
import { formatDateTime, fromDateTimeLocal, toDateTimeLocal } from '@/lib/dates'
import { centsToDollarInput, formatMoney, parseDollarsToCents } from '@/lib/money'
import type { Discount, DiscountType } from '@/lib/types'
import { useDeleteDiscount, useDiscounts, useSaveDiscount } from './queries'

function describeValue(discount: Pick<Discount, 'type' | 'value'>) {
  return discount.type === 'percent' ? `${discount.value}% off` : `${formatMoney(discount.value)} off`
}

export function AdminDiscountsPage() {
  const { params, setPage } = useQueryFilters([] as const)
  const discounts = useDiscounts(params)
  // undefined = dialog closed, null = creating, Discount = editing
  const [editing, setEditing] = useState<Discount | null | undefined>(undefined)
  const [toDelete, setToDelete] = useState<Discount | null>(null)
  const remove = useDeleteDiscount()

  return (
    <>
      <PageHeader title="Discount codes" actions={<Button onClick={() => setEditing(null)}>New code</Button>} />

      {discounts.isPending ? (
        <PageSpinner />
      ) : discounts.isError ? (
        <Alert tone="error">{parseApiError(discounts.error).message}</Alert>
      ) : discounts.data.data.length === 0 ? (
        <EmptyState title="No discount codes" action={<Button onClick={() => setEditing(null)}>Create one</Button>} />
      ) : (
        <>
          <Table>
            <THead>
              <tr><Th>Code</Th><Th>Value</Th><Th>Used</Th><Th>Valid</Th><Th>Status</Th><Th><span className="sr-only">Actions</span></Th></tr>
            </THead>
            <TBody>
              {discounts.data.data.map((discount) => (
                <Tr key={discount.id}>
                  <Td>
                    <span className="font-mono font-medium text-stone-900">{discount.code}</span>
                    {discount.description && <span className="block text-xs text-stone-500">{discount.description}</span>}
                  </Td>
                  <Td className="whitespace-nowrap">{describeValue(discount)}</Td>
                  <Td>{discount.redemptions_count}{discount.max_redemptions !== null && ` / ${discount.max_redemptions}`}</Td>
                  <Td className="text-xs">
                    {discount.starts_at ? `From ${formatDateTime(discount.starts_at)}` : 'From now'}
                    <span className="block">{discount.expires_at ? `Until ${formatDateTime(discount.expires_at)}` : 'No end date'}</span>
                  </Td>
                  <Td>
                    {/* The backend explains why a code cannot be used right now. */}
                    {discount.unavailable_reason ? (
                      <Badge tone="neutral" className="whitespace-normal">{discount.unavailable_reason}</Badge>
                    ) : (
                      <Badge tone="green">Usable</Badge>
                    )}
                  </Td>
                  <Td>
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" size="sm" onClick={() => setEditing(discount)}>Edit</Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                          remove.reset()
                          setToDelete(discount)
                        }}
                      >
                        Delete
                      </Button>
                    </div>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
          <Pagination meta={discounts.data.meta} onPageChange={setPage} />
        </>
      )}

      {editing !== undefined && <DiscountDialog discount={editing} onClose={() => setEditing(undefined)} />}

      <ConfirmDialog
        open={toDelete !== null}
        title={`Delete ${toDelete?.code ?? ''}?`}
        description="Codes that were already used are kept for the booking history and cannot be deleted. Deactivate them instead."
        confirmLabel="Delete"
        loading={remove.isPending}
        error={remove.isError && <Alert tone="error">{parseApiError(remove.error).message}</Alert>}
        onCancel={() => setToDelete(null)}
        onConfirm={() => toDelete && remove.mutate(toDelete.id, { onSuccess: () => setToDelete(null) })}
      />
    </>
  )
}

function DiscountDialog({ discount, onClose }: { discount: Discount | null; onClose: () => void }) {
  const save = useSaveDiscount(discount?.id ?? null)
  const { values, field } = useFormFields({
    code: discount?.code ?? '',
    description: discount?.description ?? '',
    type: (discount?.type ?? 'percent') as DiscountType,
    // Fixed amounts are typed in dollars; percentages as a plain number.
    value: discount ? (discount.type === 'fixed' ? centsToDollarInput(discount.value) : String(discount.value)) : '',
    max_redemptions: discount?.max_redemptions ? String(discount.max_redemptions) : '',
    starts_at: toDateTimeLocal(discount?.starts_at ?? null),
    expires_at: toDateTimeLocal(discount?.expires_at ?? null),
  })
  const [isActive, setIsActive] = useState(discount?.is_active ?? true)
  const [valueError, setValueError] = useState<string>()
  const error = save.error ? parseApiError(save.error) : null

  function submit(event: FormEvent) {
    event.preventDefault()
    const value = values.type === 'fixed' ? parseDollarsToCents(values.value) : /^\d+$/.test(values.value) ? Number(values.value) : null
    if (value === null) {
      setValueError(values.type === 'fixed' ? 'Enter an amount like 25 or 12.50' : 'Enter a whole number from 1 to 100')
      return
    }
    setValueError(undefined)

    save.mutate(
      {
        code: values.code,
        description: values.description || null,
        type: values.type,
        value,
        max_redemptions: values.max_redemptions ? Number(values.max_redemptions) : null,
        // datetime-local is the admin's local time; the API stores UTC.
        starts_at: fromDateTimeLocal(values.starts_at),
        expires_at: fromDateTimeLocal(values.expires_at),
        is_active: isActive,
      },
      { onSuccess: onClose },
    )
  }

  return (
    <Dialog open onClose={onClose} title={discount ? `Edit ${discount.code}` : 'New discount code'}>
      <form onSubmit={submit} className="space-y-4" noValidate>
        {error && Object.keys(error.fieldErrors).length === 0 && <Alert tone="error">{error.message}</Alert>}
        <TextField label="Code" className="uppercase" placeholder="SUMMER25" {...field('code')} error={error?.fieldErrors.code} hint="Letters, numbers, dashes. Customers can type it in any case." />
        <TextField label="Description (internal)" {...field('description')} error={error?.fieldErrors.description} />
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField label="Type" {...field('type')} error={error?.fieldErrors.type}>
            <option value="percent">Percentage</option>
            <option value="fixed">Fixed amount</option>
          </SelectField>
          <TextField
            label={values.type === 'fixed' ? 'Amount off (USD)' : 'Percent off'}
            inputMode="decimal"
            {...field('value')}
            error={valueError ?? error?.fieldErrors.value}
          />
        </div>
        <TextField label="Maximum uses (empty = unlimited)" type="number" min={1} {...field('max_redemptions')} error={error?.fieldErrors.max_redemptions} />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Valid from (optional)" type="datetime-local" {...field('starts_at')} error={error?.fieldErrors.starts_at} />
          <TextField label="Valid until (optional)" type="datetime-local" {...field('expires_at')} error={error?.fieldErrors.expires_at} />
        </div>
        <Checkbox label="Active" checked={isActive} onChange={(event) => setIsActive(event.target.checked)} />
        <div className="flex justify-end gap-3">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={save.isPending}>Save</Button>
        </div>
      </form>
    </Dialog>
  )
}
