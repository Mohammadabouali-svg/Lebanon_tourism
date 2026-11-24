import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { TextField } from '@/components/ui/Field'
import { useFormFields } from '@/hooks/useFormFields'
import { parseApiError } from '@/lib/api'
import { AuthCard } from './AuthCard'
import { useRegister } from './hooks'

/*
 * Traveler and operator sign-up share this component. Operators fill in two
 * extra company fields and start in the "pending approval" state.
 *
 * Notice there is no "role" field: the backend decides the role from the
 * endpoint. Sending `role: "admin"` would simply be ignored.
 */
export function RegisterPage({ kind = 'traveler' }: { kind?: 'traveler' | 'operator' }) {
  const navigate = useNavigate()
  const register = useRegister(kind)
  const { values, field } = useFormFields({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    company_name: '',
    business_phone: '',
  })
  const error = register.error ? parseApiError(register.error) : null
  const isOperator = kind === 'operator'

  function submit(event: FormEvent) {
    event.preventDefault()
    const { company_name, business_phone, ...personal } = values
    register.mutate(isOperator ? { ...personal, company_name, business_phone } : personal, {
      onSuccess: () => navigate('/verify-email', { replace: true }),
    })
  }

  return (
    <AuthCard
      title={isOperator ? 'Register your tour company' : 'Create your account'}
      subtitle={
        isOperator
          ? 'Publish your tours on Lebanon Tourism. An administrator reviews every new company before its tours go live.'
          : 'Book guided tours across Lebanon in a few clicks.'
      }
      footer={
        <>
          Already registered? <Link to="/login" className="font-medium text-brand-700 hover:underline">Log in</Link>
          {!isOperator && (
            <>
              {' · '}
              <Link to="/register/operator" className="font-medium text-brand-700 hover:underline">Register as a tour operator</Link>
            </>
          )}
        </>
      }
    >
      {error && Object.keys(error.fieldErrors).length === 0 && <Alert tone="error" className="mb-4">{error.message}</Alert>}

      <form onSubmit={submit} className="space-y-4" noValidate>
        <TextField label="Full name" autoComplete="name" required {...field('name')} error={error?.fieldErrors.name} />
        <TextField label="E-mail" type="email" autoComplete="email" required {...field('email')} error={error?.fieldErrors.email} />
        <TextField label="Phone (optional)" type="tel" autoComplete="tel" placeholder="+961 70 123 456" {...field('phone')} error={error?.fieldErrors.phone} />

        {isOperator && (
          <>
            <TextField label="Company name" autoComplete="organization" required {...field('company_name')} error={error?.fieldErrors.company_name} />
            <TextField label="Business phone" type="tel" required {...field('business_phone')} error={error?.fieldErrors.business_phone} />
          </>
        )}

        <TextField
          label="Password"
          type="password"
          autoComplete="new-password"
          required
          hint="At least 8 characters."
          {...field('password')}
          error={error?.fieldErrors.password}
        />
        <TextField label="Confirm password" type="password" autoComplete="new-password" required {...field('password_confirmation')} />

        <Button type="submit" className="w-full" loading={register.isPending}>
          {isOperator ? 'Register company' : 'Create account'}
        </Button>
      </form>
    </AuthCard>
  )
}
