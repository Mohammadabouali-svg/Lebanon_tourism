import { isRouteErrorResponse, useRouteError } from 'react-router'
import { ButtonLink } from '@/components/ui/Button'
import { DocumentTitle } from '@/components/ui/PageHeader'
import { NotFoundPage } from './NotFoundPage'

/*
 * Shown when a page throws while rendering (a bug), or when a lazily loaded
 * page fails to download (e.g. after a deploy replaced the old files).
 * Without it, one broken component would blank the whole screen.
 */
export function RouteErrorPage() {
  const error = useRouteError()

  if (isRouteErrorResponse(error) && error.status === 404) return <NotFoundPage />

  // Details go to the console for developers, not to the screen.
  console.error(error)

  return (
    <div className="mx-auto max-w-xl px-4 py-20 text-center">
      <DocumentTitle title="Something went wrong" />
      <h1 className="text-3xl font-semibold text-stone-900">Something went wrong</h1>
      <p className="mt-2 text-stone-600">Please reload the page. If the problem persists, try again later.</p>
      <div className="mt-6 flex justify-center gap-3">
        <button type="button" onClick={() => window.location.reload()} className="rounded-lg bg-brand-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-800">
          Reload
        </button>
        <ButtonLink to="/" variant="secondary">Home</ButtonLink>
      </div>
    </div>
  )
}
