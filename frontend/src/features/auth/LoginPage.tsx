import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Checkbox, TextField } from '@/components/ui/Field'
import { useFlash } from '@/hooks/useFlash'
import { useFormFields } from '@/hooks/useFormFields'
import { parseApiError } from '@/lib/api'
import { AuthCard } from './AuthCard'
import { homePathFor, safeRedirect, useLogin } from './hooks'

export function LoginPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const flash = useFlash()
  const login = useLogin()
  const { values, field } = useFormFields({ email: '', password: '' })
  const [remember, setRemember] = useState(false)

  // Errors are derived from the mutation, so there is no extra state to reset.
  const error = login.error ? parseApiError(login.error) : null

  function submit(event: FormEvent) {
    event.preventDefault()
    login.mutate(
      { ...values, remember },
      {
        onSuccess: (user) => {
          navigate(safeRedirect(searchParams.get('redirect')) ?? homePathFor(user), { replace: true })
        },
      },
    )
  }

  return (
    <AuthCard
      title="Log in"
      subtitle="Welcome back! Log in to book tours and manage your trips."
      footer={
        <>
          New here? <Link to="/register" className="font-medium text-brand-700 hover:underline">Create an account</Link>
        </>
      }
    >
      {flash && <Alert tone="success" className="mb-4">{flash}</Alert>}
      {/* The API never says whether the e-mail or the password was wrong. */}
      {error && !error.fieldErrors.email && !error.fieldErrors.password && <Alert tone="error" className="mb-4">{error.message}</Alert>}

      <form onSubmit={submit} className="space-y-4" noValidate>
        <TextField label="E-mail" type="email" autoComplete="email" required {...field('email')} error={error?.fieldErrors.email} />
        <TextField label="Password" type="password" autoComplete="current-password" required {...field('password')} error={error?.fieldErrors.password} />
        <div className="flex items-center justify-between">
          <Checkbox label="Remember me" checked={remember} onChange={(event) => setRemember(event.target.checked)} />
          <Link to="/forgot-password" className="text-sm font-medium text-brand-700 hover:underline">
            Forgot password?
          </Link>
        </div>
        <Button type="submit" className="w-full" loading={login.isPending}>
          Log in
        </Button>
      </form>
    </AuthCard>
  )
}
