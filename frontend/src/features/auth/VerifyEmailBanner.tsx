import { useCurrentUser } from './hooks'
import { ResendVerificationButton } from './ResendVerificationButton'

/** Shown on every page while the logged-in user has not confirmed their e-mail. */
export function VerifyEmailBanner() {
  const { user } = useCurrentUser()
  if (!user || user.email_verified) return null

  return (
    <div className="border-b border-amber-200 bg-amber-50">
      <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-2 text-sm text-amber-900">
        <p>
          Please confirm your e-mail address (<strong>{user.email}</strong>) to book tours and write reviews.
        </p>
        <ResendVerificationButton size="sm" />
      </div>
    </div>
  )
}
