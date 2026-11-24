/*
 * Money in the browser.
 *
 * The API speaks integer cents both ways (12999 = $129.99). This file is the
 * only place that converts between cents and what a human reads or types.
 *
 * Why not `Math.round(parseFloat("19.99") * 100)`? Floating point cannot
 * represent most decimal fractions exactly: 1.005 * 100 is 100.49999999999999.
 * Parsing the text into its dollar and cent digits avoids the problem entirely.
 *
 * Remember: these are *display* helpers. The browser never decides what a
 * booking costs; the server computes every price (see the quote endpoint).
 */

const usd = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' })

/** 12999 → "$129.99". Dividing by 100 is fine for display: Intl rounds to cents. */
export function formatMoney(cents: number): string {
  return usd.format(cents / 100)
}

/**
 * "129.99", "$1,299.5", "40" → cents. Returns null for anything that is not
 * a non-negative amount with at most two decimals.
 */
export function parseDollarsToCents(input: string): number | null {
  const cleaned = input.trim().replace(/^\$/, '').replaceAll(',', '')
  const match = /^(\d{1,9})(?:\.(\d{0,2}))?$/.exec(cleaned)
  if (!match) return null

  const dollars = Number(match[1])
  const cents = Number((match[2] ?? '').padEnd(2, '0'))
  return dollars * 100 + cents
}

/** 12999 → "129.99", for pre-filling an input the user will edit. */
export function centsToDollarInput(cents: number): string {
  return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`
}
