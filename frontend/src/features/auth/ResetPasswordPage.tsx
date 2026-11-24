import type { FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { TextField } from '@/components/ui/Field'
import { useFormFields } from '@/hooks/useFormFields'
import { parseApiError } from '@/lib/api'
import { AuthCard } from './AuthCard'
import { useResetPassword } from './hooks'

/** Opened from the reset e-mail: /reset-password?token=...&email=... */
export function ResetPasswordPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token') ?? ''
  const email = searchParams.get('email') ?? ''
  const reset = useResetPassword()
  const { values, field } = useFormFields({ password: '', password_confirmation: '' })
  const error = reset.error ? parseApiError(reset.error) : null

  function submit(event: FormEvent) {
    event.preventDefault()
    reset.mutate(
      { token, email, ...values },
      { onSuccess: (message) => navigate('/login', { replace: true, state: { flash: message } }) },
    )
  }

  if (!token || !email) {
    return (
      <AuthCard title="Invalid reset link">
        <Alert tone="error">This link is incomplete. Please use the button from the e-mail, or request a new link.</Alert>
        <Link to="/forgot-password" className="mt-4 inline-block font-medium text-brand-700 hover:underline">
          Request a new link
        </Link>
      </AuthCard>
    )
  }

  return (
    <AuthCard title="Choose a new password" subtitle={<>For <strong>{email}</strong></>}>
      <form onSubmit={submit} className="space-y-4" noValidate>
        {/* Invalid or expired tokens are reported on the "email" field by Laravel. */}
        {error?.fieldErrors.email && (
          <Alert tone="error">
            {error.fieldErrors.email} <Link to="/forgot-password" className="font-medium underline">Request a new link</Link>
          </Alert>
        )}
        {error && Object.keys(error.fieldErrors).length === 0 && <Alert tone="error">{error.message}</Alert>}
        <TextField label="New password" type="password" autoComplete="new-password" required {...field('password')} error={error?.fieldErrors.password} />
        <TextField label="Confirm new password" type="password" autoComplete="new-password" required {...field('password_confirmation')} />
        <Button type="submit" className="w-full" loading={reset.isPending}>
          Reset password
        </Button>
      </form>
    </AuthCard>
  )
}
