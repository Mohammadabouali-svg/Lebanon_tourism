import { useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { TextField } from '@/components/ui/Field'
import { parseApiError } from '@/lib/api'
import { AuthCard } from './AuthCard'
import { useForgotPassword } from './hooks'

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const forgot = useForgotPassword()
  const error = forgot.error ? parseApiError(forgot.error) : null

  function submit(event: FormEvent) {
    event.preventDefault()
    forgot.mutate(email)
  }

  return (
    <AuthCard
      title="Forgot your password?"
      subtitle="Enter your e-mail and we will send you a link to choose a new password."
      footer={<Link to="/login" className="font-medium text-brand-700 hover:underline">Back to log in</Link>}
    >
      {forgot.isSuccess ? (
        // Same answer whether or not the address has an account (see the backend).
        <Alert tone="success" title="Check your inbox">
          {forgot.data} The link is valid for 60 minutes.
        </Alert>
      ) : (
        <form onSubmit={submit} className="space-y-4" noValidate>
          {error && !error.fieldErrors.email && <Alert tone="error">{error.message}</Alert>}
          <TextField
            label="E-mail"
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            error={error?.fieldErrors.email}
          />
          <Button type="submit" className="w-full" loading={forgot.isPending}>
            Send reset link
          </Button>
        </form>
      )}
    </AuthCard>
  )
}
