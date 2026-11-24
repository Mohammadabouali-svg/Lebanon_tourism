import { useEffect, useState } from 'react'

/**
 * Returns `value`, but only after it stopped changing for `delayMs`.
 * Clicking "+" five times quickly then triggers one price quote, not five.
 */
export function useDebouncedValue<T>(value: T, delayMs = 300): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delayMs)
    return () => window.clearTimeout(timer)
  }, [value, delayMs])

  return debounced
}
