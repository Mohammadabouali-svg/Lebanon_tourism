import { useEffect, useRef } from 'react'
import { Link, useSearchParams } from 'react-router'
import { Alert } from '@/components/ui/Alert'
import { ButtonLink } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { parseApiError } from '@/lib/api'
import { AuthCard } from './AuthCard'
import { homePathFor, useCurrentUser, useVerifyEmail } from './hooks'
import { ResendVerificationButton } from './ResendVerificationButton'

/*
 * Two situations share this page:
 *   /verify-email                                    "check your inbox" (after sign-up)
 *   /verify-email?id=..&hash=..&expires=..&signature=..   the link from the e-mail
 */
export function VerifyEmailPage() {
  const [searchParams] = useSearchParams()
  const { user } = useCurrentUser()
  const verify = useVerifyEmail()
  const id = searchParams.get('id') ?? ''
  const hash = searchParams.get('hash') ?? ''
  const expires = searchParams.get('expires') ?? ''
  const signature = searchParams.get('signature') ?? ''
  const hasLink = Boolean(id && hash && expires && signature)

  // In development React's StrictMode runs effects twice; the ref makes sure
  // the verification request is sent only once.
  const sent = useRef(false)
  const { mutate } = verify
  useEffect(() => {
    if (hasLink && !sent.current) {
      sent.current = true
      mutate({ id, hash, expires, signature })
    }
  }, [hasLink, mutate, id, hash, expires, signature])

  if (!hasLink) {
    return (
      <AuthCard title="Check your inbox">
        <p className="text-sm text-stone-600">
          We sent a confirmation link to {user ? <strong>{user.email}</strong> : 'your e-mail address'}. Click it to activate your account.
        </p>
        <p className="mt-2 text-xs text-stone-500">In local development, e-mails land in Mailpit: http://localhost:8025</p>
        {user && !user.email_verified && (
          <div className="mt-6">
            <ResendVerificationButton />
          </div>
        )}
      </AuthCard>
    )
  }

  return (
    <AuthCard title="E-mail verification">
      {verify.isPending || verify.isIdle ? (
        <p className="flex items-center gap-3 text-sm text-stone-600">
          <Spinner /> Checking your link…
        </p>
      ) : verify.isSuccess ? (
        <>
          <Alert tone="success">{verify.data}</Alert>
          <div className="mt-6">
            {user ? (
              <ButtonLink to={homePathFor(user)}>Continue</ButtonLink>
            ) : (
              <ButtonLink to="/login">Log in</ButtonLink>
            )}
          </div>
        </>
      ) : (
        <>
          <Alert tone="error" title="This link cannot be used">
            {parseApiError(verify.error).status === 403
              ? 'The link is invalid or has expired.'
              : parseApiError(verify.error).message}
          </Alert>
          <div className="mt-6 text-sm">
            {user ? <ResendVerificationButton /> : <Link to="/login" className="font-medium text-brand-700 hover:underline">Log in to request a new link</Link>}
          </div>
        </>
      )}
    </AuthCard>
  )
}
