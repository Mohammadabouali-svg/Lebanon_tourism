import { useLocation } from 'react-router'

/*
 * One-off messages after a redirect ("Booking confirmed!"), passed through
 * the router's history state:
 *
 *   navigate('/bookings', { state: { flash: 'Booking LT-ABC confirmed.' } })
 *
 * History state is not part of the URL, so a refreshed or shared link does
 * not show the message again.
 */
export function useFlash(): string | null {
  const location = useLocation()
  const state = location.state as { flash?: unknown } | null
  return typeof state?.flash === 'string' ? state.flash : null
}
