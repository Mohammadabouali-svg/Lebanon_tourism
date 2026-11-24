import { Button } from '@/components/ui/Button'
import { parseApiError } from '@/lib/api'
import { useResendVerification } from './hooks'

export function ResendVerificationButton({ size = 'md' }: { size?: 'sm' | 'md' }) {
  const resend = useResendVerification()

  if (resend.isSuccess) return <span className="text-sm font-medium">{resend.data}</span>

  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      <Button size={size} variant="secondary" loading={resend.isPending} onClick={() => resend.mutate()}>
        Resend the link
      </Button>
      {resend.isError && <span className="text-sm text-red-700">{parseApiError(resend.error).message}</span>}
    </span>
  )
}
