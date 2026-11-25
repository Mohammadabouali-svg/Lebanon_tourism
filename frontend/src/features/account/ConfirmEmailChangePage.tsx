import { useEffect, useRef } from 'react'
import { useSearchParams } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { ButtonLink } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { AuthCard } from '@/features/auth/AuthCard'
import { useCurrentUser } from '@/features/auth/hooks'
import { parseApiError } from '@/lib/api'
import { useConfirmEmailChange } from './queries'

/*
 * The page behind the link mailed to a NEW address:
 *   /confirm-email-change?id=..&hash=..&expires=..&signature=..
 *
 * Public on purpose (like /verify-email): the link is often opened on a
 * phone where the user is not logged in. The signature, not the session,
 * proves the click came from the new inbox.
 */
export function ConfirmEmailChangePage() {
  const [searchParams] = useSearchParams()
  const { user } = useCurrentUser()
  const confirm = useConfirmEmailChange()
  const id = searchParams.get('id') ?? ''
  const hash = searchParams.get('hash') ?? ''
  const expires = searchParams.get('expires') ?? ''
  const signature = searchParams.get('signature') ?? ''
  const hasLink = Boolean(id && hash && expires && signature)

  // StrictMode runs effects twice in development; send the request once.
  const sent = useRef(false)
  const { mutate } = confirm
  useEffect(() => {
    if (hasLink && !sent.current) {
      sent.current = true
      mutate({ id, hash, expires, signature })
    }
  }, [hasLink, mutate, id, hash, expires, signature])

  const next = user ? <ButtonLink to="/account">Go to my account</ButtonLink> : <ButtonLink to="/login">Log in</ButtonLink>

  if (!hasLink) {
    return (
      <AuthCard title="Confirm your new e-mail address">
        <Alert tone="error">This page needs the link from the confirmation e-mail.</Alert>
        <div className="mt-6">{next}</div>
      </AuthCard>
    )
  }

  if (confirm.isPending || confirm.isIdle) {
    return (
      <AuthCard title="Confirm your new e-mail address">
        <p className="flex items-center gap-3 text-sm text-stone-600">
          <Spinner /> Checking your link…
        </p>
      </AuthCard>
    )
  }

  if (confirm.isSuccess) {
    return (
      <AuthCard title="Confirm your new e-mail address">
        <Alert tone="success">{confirm.data}</Alert>
        <div className="mt-6">{next}</div>
      </AuthCard>
    )
  }

  const error = parseApiError(confirm.error)
  return (
    <AuthCard title="Confirm your new e-mail address">
      <Alert tone="error" title="This link cannot be used">
        {error.status === 403
          ? 'The link is invalid, has expired, or a newer change was requested. Request the change again from your account page.'
          : error.message}
      </Alert>
      <div className="mt-6">{next}</div>
    </AuthCard>
  )
}
