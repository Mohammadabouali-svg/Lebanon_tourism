import { QueryClient } from '@tanstack/react-query'
import axios from 'axios'

/*
 * React Query keeps a cache of everything fetched from the API ("server
 * state"): who is logged in, tour lists, bookings...
 *
 * Without it every page would need its own `useEffect` + `useState` +
 * loading flag + error flag, and two components showing the same data would
 * fetch it twice and could disagree. With it, components *subscribe* to a
 * query key (e.g. ['tours', 'detail', 5]); after a change we *invalidate* the
 * key and every subscriber refreshes.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Data younger than 30 s is served from cache without refetching.
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      // Retrying a 403 or 404 will not change the answer; retry network
      // hiccups and 5xx errors once.
      retry: (failureCount, error) => {
        if (axios.isAxiosError(error) && error.response && error.response.status < 500) return false
        return failureCount < 1
      },
    },
  },
})
