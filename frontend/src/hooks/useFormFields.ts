import { useState, type ChangeEvent } from 'react'

type Control = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement

/*
 * Tiny helper for controlled forms, so each input does not need its own
 * useState + onChange:
 *
 *   const { values, field } = useFormFields({ email: '', password: '' })
 *   <TextField label="E-mail" {...field('email')} />
 *
 * It only stores what the user typed. Deciding whether the input is valid is
 * Laravel's job; its 422 answer is shown next to each field.
 */
export function useFormFields<T extends Record<string, string>>(initial: T) {
  const [values, setValues] = useState<T>(initial)

  function set<K extends keyof T>(name: K, value: T[K]) {
    setValues((current) => ({ ...current, [name]: value }))
  }

  function field<K extends keyof T & string>(name: K) {
    return {
      name,
      value: values[name],
      onChange: (event: ChangeEvent<Control>) => set(name, event.target.value as T[K]),
    }
  }

  return { values, set, field, setValues, reset: () => setValues(initial) }
}
