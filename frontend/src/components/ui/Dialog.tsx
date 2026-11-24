import { useEffect, useRef, type ReactNode } from 'react'
import { Button } from './Button'

/*
 * Modal dialogs use the native <dialog> element. `showModal()` gives us, for
 * free, what used to need a library: focus moves into the dialog, the rest
 * of the page cannot be clicked, Esc closes it, and the backdrop is styled
 * with `dialog::backdrop` (see index.css).
 */
export function Dialog({ open, onClose, title, children }: { open: boolean; onClose: () => void; title: ReactNode; children: ReactNode }) {
  const ref = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    if (open && !dialog.open) dialog.showModal()
    if (!open && dialog.open) dialog.close()
  }, [open])

  return (
    <dialog
      ref={ref}
      // Esc fires "cancel"; we let React state decide whether it closes.
      onCancel={(event) => {
        event.preventDefault()
        onClose()
      }}
      className="m-auto w-[calc(100%-2rem)] max-w-lg rounded-xl border border-stone-200 bg-white p-0 shadow-xl"
    >
      {open && (
        <div className="p-6">
          <h2 className="text-lg font-semibold text-stone-900">{title}</h2>
          <div className="mt-4">{children}</div>
        </div>
      )}
    </dialog>
  )
}

interface ConfirmDialogProps {
  open: boolean
  title: ReactNode
  description?: ReactNode
  confirmLabel?: string
  tone?: 'danger' | 'primary'
  loading?: boolean
  /** Shown inside the dialog, e.g. a server error after confirming. */
  error?: ReactNode
  onConfirm: () => void
  onCancel: () => void
}

/** "Are you sure?" before destructive actions such as cancelling a booking. */
export function ConfirmDialog({ open, title, description, confirmLabel = 'Confirm', tone = 'danger', loading, error, onConfirm, onCancel }: ConfirmDialogProps) {
  return (
    <Dialog open={open} onClose={onCancel} title={title}>
      {description && <div className="text-sm text-stone-600">{description}</div>}
      {error && <div className="mt-4">{error}</div>}
      <div className="mt-6 flex justify-end gap-3">
        <Button variant="secondary" onClick={onCancel} disabled={loading}>
          Keep it
        </Button>
        <Button variant={tone === 'danger' ? 'danger' : 'primary'} onClick={onConfirm} loading={loading}>
          {confirmLabel}
        </Button>
      </div>
    </Dialog>
  )
}
