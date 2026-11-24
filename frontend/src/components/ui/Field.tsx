import { useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

/*
 * Form controls.
 *
 * Every control is connected to its <label> (htmlFor/id) and to its error
 * message (aria-describedby), so screen readers announce "Email, invalid,
 * The email has already been taken". The error text comes straight from
 * Laravel's validation, which is the single source of truth for what is
 * valid. The browser's `required`/`min` attributes are only a convenience.
 */

const controlBase =
  'block rounded-lg border bg-white px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 disabled:bg-stone-100'

/*
 * Labelled fields always fill their column (w-full). Bare controls get no
 * width at all, so the caller decides (`w-28`, `flex-1`, ...). Two competing
 * width classes on one element would make the result depend on CSS order.
 */
function controlClasses(invalid: boolean, className?: string, fullWidth = true) {
  return cn(
    controlBase,
    fullWidth && 'w-full',
    invalid ? 'border-red-500 focus-visible:outline-red-600' : 'border-stone-300',
    className,
  )
}

interface FieldProps {
  label: ReactNode
  error?: string
  hint?: ReactNode
  className?: string
  /** Receives the generated id and the aria-describedby value for the control. */
  children: (ids: { id: string; describedBy: string | undefined; invalid: boolean }) => ReactNode
}

/** Label + control + hint + error. The control is passed as a render function. */
export function Field({ label, error, hint, className, children }: FieldProps) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined

  return (
    <div className={cn('space-y-1', className)}>
      <label htmlFor={id} className="block text-sm font-medium text-stone-700">
        {label}
      </label>
      {children({ id, describedBy, invalid: Boolean(error) })}
      {hint && !error && (
        <p id={hintId} className="text-xs text-stone-500">
          {hint}
        </p>
      )}
      {error && (
        <p id={errorId} className="text-sm text-red-600">
          {error}
        </p>
      )}
    </div>
  )
}

type CommonProps = { label: ReactNode; error?: string; hint?: ReactNode; fieldClassName?: string }

export function TextField({ label, error, hint, fieldClassName, className, ...props }: CommonProps & InputHTMLAttributes<HTMLInputElement>) {
  return (
    <Field label={label} error={error} hint={hint} className={fieldClassName}>
      {({ id, describedBy, invalid }) => (
        <input id={id} aria-invalid={invalid || undefined} aria-describedby={describedBy} className={controlClasses(invalid, className)} {...props} />
      )}
    </Field>
  )
}

export function SelectField({ label, error, hint, fieldClassName, className, children, ...props }: CommonProps & SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <Field label={label} error={error} hint={hint} className={fieldClassName}>
      {({ id, describedBy, invalid }) => (
        <select id={id} aria-invalid={invalid || undefined} aria-describedby={describedBy} className={controlClasses(invalid, className)} {...props}>
          {children}
        </select>
      )}
    </Field>
  )
}

export function TextareaField({ label, error, hint, fieldClassName, className, ...props }: CommonProps & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return (
    <Field label={label} error={error} hint={hint} className={fieldClassName}>
      {({ id, describedBy, invalid }) => (
        <textarea id={id} aria-invalid={invalid || undefined} aria-describedby={describedBy} className={controlClasses(invalid, className)} rows={4} {...props} />
      )}
    </Field>
  )
}

/** Bare controls for places without a visible label (always pass aria-label and a width class). */
export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input className={controlClasses(false, className, false)} {...props} />
}

export function Select({ className, children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select className={controlClasses(false, className, false)} {...props}>
      {children}
    </select>
  )
}

export function Checkbox({ label, ...props }: { label: ReactNode } & InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label className="inline-flex items-center gap-2 text-sm text-stone-700">
      <input type="checkbox" className="size-4 rounded border-stone-300 accent-brand-700" {...props} />
      {label}
    </label>
  )
}
