import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Envelope, Paginated, ReferenceData, Review, Tour } from '@/lib/types'

/*
 * Query keys are arrays, organised from general to specific:
 *   ['tours']                       everything about tours
 *   ['tours', 'list', {filters}]    one filtered list
 *   ['tours', 'detail', 5]          one tour
 *   ['tours', 'reviews', 5, page]   one page of its reviews
 * invalidateQueries({ queryKey: ['tours'] }) refreshes all of them at once,
 * e.g. after a booking changed the number of free spots.
 */
export const tourKeys = {
  all: ['tours'] as const,
  list: (params: TourFilters) => ['tours', 'list', params] as const,
  detail: (id: number) => ['tours', 'detail', id] as const,
  reviews: (id: number, page: number) => ['tours', 'reviews', id, page] as const,
}

export const referenceDataKey = ['reference-data'] as const

/** Query-string parameters accepted by GET /api/tours (all optional). */
export interface TourFilters {
  search?: string
  region_id?: string
  city_id?: string
  category_id?: string
  max_price_cents?: string
  min_days?: string
  max_days?: string
  min_rating?: string
  sort?: string
  page?: string
  per_page?: string
}

export function useTours(filters: TourFilters) {
  return useQuery({
    queryKey: tourKeys.list(filters),
    queryFn: async () => (await api.get<Paginated<Tour>>('/tours', { params: filters })).data,
    // Keep showing the previous results while the next page/filter loads,
    // instead of flashing an empty list.
    placeholderData: keepPreviousData,
  })
}

export function useTour(id: number) {
  return useQuery({
    queryKey: tourKeys.detail(id),
    queryFn: async () => (await api.get<Envelope<Tour>>(`/tours/${id}`)).data.data,
    enabled: Number.isInteger(id) && id > 0,
  })
}

/** Categories and regions with their cities. They rarely change: cache for an hour. */
export function useReferenceData() {
  return useQuery({
    queryKey: referenceDataKey,
    queryFn: async () => (await api.get<ReferenceData>('/reference-data')).data,
    staleTime: 60 * 60_000,
  })
}

export function useTourReviews(tourId: number, page: number) {
  return useQuery({
    queryKey: tourKeys.reviews(tourId, page),
    queryFn: async () => (await api.get<Paginated<Review>>(`/tours/${tourId}/reviews`, { params: { page } })).data,
    placeholderData: keepPreviousData,
  })
}

export function useCreateReview(tourId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    // FormData, because the review may include photos (`photos[]`). Axios sets the
    // multipart Content-Type (with its boundary) automatically.
    mutationFn: async (form: FormData) => (await api.post<Envelope<Review>>(`/tours/${tourId}/reviews`, form)).data.data,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tours', 'reviews', tourId] })
      void queryClient.invalidateQueries({ queryKey: tourKeys.detail(tourId) }) // average rating changed
    },
  })
}

export function useDeleteReview(tourId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (reviewId: number) => {
      await api.delete(`/reviews/${reviewId}`)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tours', 'reviews', tourId] })
      void queryClient.invalidateQueries({ queryKey: tourKeys.detail(tourId) })
    },
  })
}
