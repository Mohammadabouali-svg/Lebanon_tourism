import { Alert } from '@/components/ui/Alert'
import { StatusBadge } from '@/components/ui/Badge'
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
import { useAdminOperators, useOperatorDecision } from './queries'

export function AdminOperatorsPage() {
  const { values, params, update, setPage } = useQueryFilters(['status'] as const)
  const operators = useAdminOperators(params)
  const decide = useOperatorDecision()

  return (
    <>
      <PageHeader title="Tour operators" description="Approve companies before their tours can be published." />

      <Select aria-label="Status" value={values.status} onChange={(event) => update('status', event.target.value)} className="mb-6 w-full sm:w-56">
        <option value="">All statuses</option>
        <option value="pending">Waiting for approval</option>
        <option value="approved">Approved</option>
        <option value="suspended">Suspended</option>
      </Select>

      {decide.isError && <Alert tone="error" className="mb-4">{parseApiError(decide.error).message}</Alert>}

      {operators.isPending ? (
        <PageSpinner />
      ) : operators.isError ? (
        <Alert tone="error">{parseApiError(operators.error).message}</Alert>
      ) : operators.data.data.length === 0 ? (
        <EmptyState title="No operators found" />
      ) : (
        <>
          <Table>
            <THead>
              <tr><Th>Company</Th><Th>Contact</Th><Th>Status</Th><Th>Registered</Th><Th><span className="sr-only">Actions</span></Th></tr>
            </THead>
            <TBody>
              {operators.data.data.map((operator) => {
                const status = operator.operator?.status ?? 'pending'
                const busy = decide.isPending && decide.variables?.id === operator.id
                return (
                  <Tr key={operator.id}>
                    <Td>
                      <span className="font-medium text-stone-900">{operator.operator?.company_name}</span>
                      <span className="block text-xs text-stone-500">{operator.operator?.business_phone}</span>
                    </Td>
                    <Td>
                      {operator.name}
                      <span className="block text-xs text-stone-500">{operator.email}{operator.email_verified ? '' : ' (unverified)'}</span>
                    </Td>
                    <Td><StatusBadge status={status} /></Td>
                    <Td className="whitespace-nowrap text-xs">{formatDateTime(operator.created_at)}</Td>
                    <Td>
                      <div className="flex justify-end gap-2">
                        {status !== 'approved' && (
                          <Button size="sm" loading={busy && decide.variables?.action === 'approve'} onClick={() => decide.mutate({ id: operator.id, action: 'approve' })}>
                            Approve
                          </Button>
                        )}
                        {status !== 'suspended' && (
                          <Button variant="ghost" size="sm" loading={busy && decide.variables?.action === 'suspend'} onClick={() => decide.mutate({ id: operator.id, action: 'suspend' })}>
                            Suspend
                          </Button>
                        )}
                      </div>
                    </Td>
                  </Tr>
                )
              })}
            </TBody>
          </Table>
          <Pagination meta={operators.data.meta} onPageChange={setPage} />
        </>
      )}
    </>
  )
}
