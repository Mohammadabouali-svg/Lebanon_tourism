import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { tourKeys } from '@/features/tours/queries'
import { api } from '@/lib/api'
import type { Booking, Envelope, OperatorDashboard, Paginated, Tour } from '@/lib/types'

export const operatorKeys = {
  all: ['operator'] as const,
  dashboard: (year: number | null) => ['operator', 'dashboard', year] as const,
  tours: (page: number) => ['operator', 'tours', page] as const,
  tour: (id: number) => ['operator', 'tour', id] as const,
  bookings: (params: Record<string, string>) => ['operator', 'bookings', params] as const,
}

export function useOperatorDashboard(year: number | null) {
  return useQuery({
    queryKey: operatorKeys.dashboard(year),
    queryFn: async () =>
      (await api.get<Envelope<OperatorDashboard>>('/operator/dashboard', { params: year ? { year } : {} })).data.data,
    placeholderData: keepPreviousData,
  })
}

export function useOperatorTours(page: number) {
  return useQuery({
    queryKey: operatorKeys.tours(page),
    queryFn: async () => (await api.get<Paginated<Tour>>('/operator/tours', { params: { page } })).data,
    placeholderData: keepPreviousData,
  })
}

export function useOperatorTour(id: number | null) {
  return useQuery({
    queryKey: operatorKeys.tour(id ?? 0),
    queryFn: async () => (await api.get<Envelope<Tour>>(`/operator/tours/${id}`)).data.data,
    enabled: id !== null,
  })
}

/*
 * Create and update share one mutation. The body is FormData (multipart),
 * because it may carry an image.
 *
 * PHP only parses multipart bodies for POST requests, so an update is sent
 * as POST with a `_method=PUT` field. Laravel reads `_method` and routes the
 * request to the PUT route ("method spoofing").
 */
export function useSaveTour(id: number | null) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (form: FormData) => {
      if (id !== null) form.append('_method', 'PUT')
      const url = id === null ? '/operator/tours' : `/operator/tours/${id}`
      return (await api.post<Envelope<Tour>>(url, form)).data.data
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: operatorKeys.all })
      void queryClient.invalidateQueries({ queryKey: tourKeys.all })
    },
  })
}

export function useDeleteTour() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/operator/tours/${id}`)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: operatorKeys.all })
      void queryClient.invalidateQueries({ queryKey: tourKeys.all })
    },
  })
}

export function useOperatorBookings(params: Record<string, string>) {
  return useQuery({
    queryKey: operatorKeys.bookings(params),
    queryFn: async () => (await api.get<Paginated<Booking>>('/operator/bookings', { params })).data,
    placeholderData: keepPreviousData,
  })
}
