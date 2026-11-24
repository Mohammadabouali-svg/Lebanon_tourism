import { ButtonLink } from '@/components/ui/Button'
import { PageHeader } from '@/components/ui/PageHeader'

export function AboutPage() {
  return (
    <div className="mx-auto max-w-3xl px-4 py-12">
      <PageHeader title="About Lebanon Tourism" />
      <div className="space-y-4 leading-relaxed text-stone-700">
        <p>
          Lebanon packs Phoenician ports, Roman temples, Crusader castles, cedar forests and Mediterranean beaches into a country you can
          cross in a few hours. Lebanon Tourism connects travelers with local tour operators who know it best.
        </p>
        <p>
          Every operator is reviewed by our team before their tours are published. Reviews come only from travelers who booked the tour, and
          cancellations are refunded as travel credit to your wallet.
        </p>
        <h2 className="pt-4 text-xl font-semibold text-stone-900">About this project</h2>
        <p>
          This site is a tutorial application: a Laravel API with a React front end, written to show how a booking platform is built safely.
          The <code className="rounded bg-stone-100 px-1">docs/</code> folder of the repository explains every decision.
        </p>
      </div>
      <div className="mt-8 flex flex-wrap gap-3">
        <ButtonLink to="/tours">Browse tours</ButtonLink>
        <ButtonLink to="/register/operator" variant="secondary">List your tours</ButtonLink>
      </div>
    </div>
  )
}
