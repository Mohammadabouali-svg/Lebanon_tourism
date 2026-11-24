/*
 * Date formatting.
 *
 * Calendar dates ("2026-11-18", a tour's start day) have no time zone. If you
 * write `new Date("2026-11-18")`, JavaScript reads it as midnight UTC, and a
 * visitor in New York would see "Nov 17". Formatting those in UTC keeps the
 * day the operator entered.
 *
 * Timestamps ("2026-11-06T15:30:49+00:00", when a booking was made) are real
 * moments, so they are shown in the visitor's own time zone.
 */

const dateFormat = new Intl.DateTimeFormat('en-GB', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  timeZone: 'UTC',
})

const shortDateFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' })

const dateTimeFormat = new Intl.DateTimeFormat('en-GB', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
})

const monthFormat = new Intl.DateTimeFormat('en-GB', { month: 'short', timeZone: 'UTC' })

/** "2026-11-18" → "18 Nov 2026" */
export function formatDate(isoDate: string): string {
  return dateFormat.format(new Date(`${isoDate}T00:00:00Z`))
}

/** "2026-11-18", "2026-11-20" → "18 Nov – 20 Nov 2026" (or a single date) */
export function formatDateRange(start: string, end: string): string {
  if (start === end) return formatDate(start)
  return `${shortDateFormat.format(new Date(`${start}T00:00:00Z`))} – ${formatDate(end)}`
}

/** ISO timestamp → "6 Nov 2026, 17:30" in the visitor's time zone */
export function formatDateTime(isoTimestamp: string | null): string {
  return isoTimestamp ? dateTimeFormat.format(new Date(isoTimestamp)) : '—'
}

/** 1 → "Jan" */
export function monthName(month: number): string {
  return monthFormat.format(new Date(Date.UTC(2000, month - 1, 1)))
}

/** Today as "YYYY-MM-DD" in the visitor's time zone, for <input type="date" min>. */
export function todayIso(offsetDays = 0): string {
  const date = new Date()
  date.setDate(date.getDate() + offsetDays)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/** ISO timestamp → value for <input type="datetime-local"> in local time. */
export function toDateTimeLocal(isoTimestamp: string | null): string {
  if (!isoTimestamp) return ''
  const date = new Date(isoTimestamp)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** <input type="datetime-local"> value (local time) → ISO timestamp in UTC, or null. */
export function fromDateTimeLocal(value: string): string | null {
  return value ? new Date(value).toISOString() : null
}
