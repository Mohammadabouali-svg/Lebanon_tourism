import { ButtonLink } from '@/components/ui/Button'
import { DocumentTitle } from '@/components/ui/PageHeader'

export function NotFoundPage() {
  return (
    <div className="mx-auto max-w-xl px-4 py-20 text-center">
      <DocumentTitle title="Page not found" />
      <p className="text-sm font-semibold text-brand-700">404</p>
      <h1 className="mt-2 text-3xl font-semibold text-stone-900">This page does not exist</h1>
      <p className="mt-2 text-stone-600">The link may be broken, or the tour is no longer published.</p>
      <div className="mt-6 flex justify-center gap-3">
        <ButtonLink to="/">Home</ButtonLink>
        <ButtonLink to="/tours" variant="secondary">Browse tours</ButtonLink>
      </div>
    </div>
  )
}
