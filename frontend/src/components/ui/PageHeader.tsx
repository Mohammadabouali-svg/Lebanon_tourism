import type { ReactNode } from 'react'

/*
 * Page heading + the document <title>.
 *
 * React 19 hoists a <title> rendered anywhere in the tree into <head>, so
 * each page sets the browser tab title without any extra library.
 */
export function PageHeader({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <DocumentTitle title={title} />
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-stone-900 sm:text-3xl">{title}</h1>
        {description && <p className="mt-1 text-sm text-stone-500">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}

export function DocumentTitle({ title }: { title: string }) {
  return <title>{`${title} · Lebanon Tourism`}</title>
}
