import type { ReactNode } from 'react'
import { Card } from '@/components/ui/Card'
import { DocumentTitle } from '@/components/ui/PageHeader'

/** Narrow centered card used by every authentication page. */
export function AuthCard({ title, subtitle, children, footer }: { title: string; subtitle?: ReactNode; children: ReactNode; footer?: ReactNode }) {
  return (
    <div className="mx-auto w-full max-w-md px-4 py-12">
      <DocumentTitle title={title} />
      <Card className="p-6 sm:p-8">
        <h1 className="text-2xl font-semibold text-stone-900">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-stone-500">{subtitle}</p>}
        <div className="mt-6">{children}</div>
      </Card>
      {footer && <div className="mt-6 text-center text-sm text-stone-600">{footer}</div>}
    </div>
  )
}
