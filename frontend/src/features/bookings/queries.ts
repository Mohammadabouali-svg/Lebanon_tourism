import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { userKey } from '@/features/auth/hooks'
import { tourKeys } from '@/features/tours/queries'
import { api } from '@/lib/api'
import type { Booking, Envelope, Paginated, PaymentMethod, Quote } from '@/lib/types'

export const bookingKeys = {
  all: ['bookings'] as const,
  mine: (page: number) => ['bookings', 'mine', page] as const,
  quote: (tourId: number, spots: number, code: string | null) => ['bookings', 'quote', tourId, spots, code] as const,
}

export function useMyBookings(page: number) {
  return useQuery({
    queryKey: bookingKeys.mine(page),
    queryFn: async () => (await api.get<Paginated<Booking>>('/bookings', { params: { page } })).data,
    placeholderData: keepPreviousData,
  })
}

/*
 * The price is asked from the server, never computed here.
 *
 * It is tempting to write `total = price * spots - discount` in React, but
 * then two implementations exist (here and in PHP) and they will drift
 * apart: rounding of percentages, expired codes, codes already used... The
 * quote endpoint runs the exact same PricingService that the booking will use.
 *
 * It is a POST (it needs the user's identity to check "already used" codes),
 * but it changes nothing, so it is safe to use as a query.
 */
export function useQuote(tourId: number, spots: number, discountCode: string | null, enabled: boolean) {
  return useQuery({
    queryKey: bookingKeys.quote(tourId, spots, discountCode),
    queryFn: async () =>
      (await api.post<Envelope<Quote>>(`/tours/${tourId}/quote`, { spots, discount_code: discountCode })).data.data,
    enabled,
    placeholderData: keepPreviousData,
    staleTime: 0,
  })
}

export interface CreateBookingPayload {
  spots: number
  payment_method: PaymentMethod
  discount_code: string | null
}

export function useCreateBooking(tourId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (payload: CreateBookingPayload) =>
      (await api.post<Envelope<Booking>>(`/tours/${tourId}/bookings`, payload)).data.data,
    onSuccess: () => {
      // A booking changes: my bookings, the tour's free spots, my wallet balance.
      void queryClient.invalidateQueries({ queryKey: bookingKeys.all })
      void queryClient.invalidateQueries({ queryKey: tourKeys.all })
      void queryClient.invalidateQueries({ queryKey: userKey })
      void queryClient.invalidateQueries({ queryKey: ['wallet'] })
    },
  })
}

export function useCancelBooking() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (bookingId: number) => (await api.post<Envelope<Booking>>(`/bookings/${bookingId}/cancel`)).data.data,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: bookingKeys.all })
      void queryClient.invalidateQueries({ queryKey: tourKeys.all })
      void queryClient.invalidateQueries({ queryKey: userKey })
      void queryClient.invalidateQueries({ queryKey: ['wallet'] })
    },
  })
}
