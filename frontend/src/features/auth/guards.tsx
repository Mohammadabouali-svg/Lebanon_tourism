import type { ReactNode } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { ButtonLink } from '@/components/ui/Button'
import { PageHeader } from '@/components/ui/PageHeader'
import { PageSpinner } from '@/components/ui/Spinner'
import type { Role } from '@/lib/types'
import { hasRole, useCurrentUser } from './hooks'
import { ResendVerificationButton } from './ResendVerificationButton'

/*
 * Route guards decide what to *show*. They are a convenience for honest
 * users, NOT security: anyone can edit the JavaScript in their browser and
 * render the admin pages anyway. What they cannot do is get data, because
 * every API route checks the session, the role and the record ownership on
 * the server (see backend/routes/api.php). If a guard here and a rule there
 * ever disagree, the server wins.
 */

interface RequireAuthProps {
  roles?: Role[]
  /** The API answers 403 to unverified users on these routes; explain it up front. */
  requireVerified?: boolean
}

export function RequireAuth({ roles, requireVerified = false }: RequireAuthProps) {
  const { user, isLoading } = useCurrentUser()
  const location = useLocation()

  if (isLoading) return <PageSpinner />

  if (!user) {
    const redirect = encodeURIComponent(location.pathname + location.search)
    return <Navigate to={`/login?redirect=${redirect}`} replace />
  }

  if (roles && !hasRole(user, ...roles)) {
    return (
      <GuardMessage title="Not available for your account">
        This area is reserved for {roles.join(' / ')} accounts.
      </GuardMessage>
    )
  }

  if (requireVerified && !user.email_verified) {
    return (
      <GuardMessage title="Please verify your e-mail address" action={<ResendVerificationButton />}>
        We sent a confirmation link to <strong>{user.email}</strong>. Click it to unlock this page.
      </GuardMessage>
    )
  }

  return <Outlet />
}

/** Operators can log in before an admin approved their company, but cannot manage tours yet. */
export function RequireApprovedOperator() {
  const { user } = useCurrentUser()
  const status = user?.operator?.status

  if (status !== 'approved') {
    return (
      <GuardMessage title={status === 'suspended' ? 'Your operator account is suspended' : 'Waiting for approval'}>
        {status === 'suspended'
          ? 'Your tours are hidden from the public site. Please contact the Lebanon Tourism team.'
          : `Thank you for registering ${user?.operator?.company_name ?? 'your company'}. An administrator reviews every new operator; you will receive an e-mail as soon as you can publish tours.`}
      </GuardMessage>
    )
  }

  return <Outlet />
}

function GuardMessage({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) {
  return (
    <div className="mx-auto max-w-xl px-4 py-16">
      <PageHeader title={title} />
      <Alert tone="info">{children}</Alert>
      <div className="mt-6 flex flex-wrap gap-3">
        {action}
        <ButtonLink to="/" variant="secondary">
          Back to the home page
        </ButtonLink>
      </div>
    </div>
  )
}
