import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { referenceDataKey, tourKeys } from '@/features/tours/queries'
import { api } from '@/lib/api'
import type {
  AdminDashboard, Booking, Category, City, Discount, DiscountType, Envelope, Paginated, Region, Tour, TourStatus, User,
} from '@/lib/types'

/*
 * Every admin query key starts with 'admin', so a single
 * invalidateQueries({ queryKey: ['admin'] }) refreshes the whole admin area.
 * Mutations here invalidate broadly on purpose: admin pages are low traffic,
 * and stale numbers on a dashboard are more confusing than one extra request.
 */
const adminKey = ['admin'] as const

type Params = Record<string, string>

function useAdminList<T>(path: string, params: Params) {
  return useQuery({
    queryKey: [...adminKey, path, params],
    queryFn: async () => (await api.get<Paginated<T>>(`/admin/${path}`, { params })).data,
    placeholderData: keepPreviousData,
  })
}

function useAdminMutation<TVariables, TResult = unknown>(mutationFn: (variables: TVariables) => Promise<TResult>, alsoInvalidate: readonly (readonly string[])[] = []) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: adminKey })
      for (const key of alsoInvalidate) void queryClient.invalidateQueries({ queryKey: key })
    },
  })
}

// --- Dashboard ----------------------------------------------------------------

export function useAdminDashboard(year: number | null) {
  return useQuery({
    queryKey: [...adminKey, 'dashboard', year],
    queryFn: async () => (await api.get<Envelope<AdminDashboard>>('/admin/dashboard', { params: year ? { year } : {} })).data.data,
    placeholderData: keepPreviousData,
  })
}

// --- Users & operators ----------------------------------------------------------

export const useAdminUsers = (params: Params) => useAdminList<User>('users', params)
export const useAdminOperators = (params: Params) => useAdminList<User>('operators', params)

export function useSuspendUser() {
  return useAdminMutation(({ id, suspend }: { id: number; suspend: boolean }) =>
    api.post(`/admin/users/${id}/${suspend ? 'suspend' : 'unsuspend'}`))
}

export function useCreditWallet() {
  return useAdminMutation(({ id, amount_cents, description }: { id: number; amount_cents: number; description: string }) =>
    api.post(`/admin/users/${id}/wallet-credits`, { amount_cents, description }))
}

export function useCreateAdmin() {
  return useAdminMutation((payload: { name: string; email: string }) => api.post('/admin/admins', payload))
}

export function useOperatorDecision() {
  return useAdminMutation(({ id, action }: { id: number; action: 'approve' | 'suspend' }) =>
    api.post(`/admin/operators/${id}/${action}`), [tourKeys.all])
}

// --- Tours & bookings -------------------------------------------------------

export const useAdminTours = (params: Params) => useAdminList<Tour>('tours', params)
export const useAdminBookings = (params: Params) => useAdminList<Booking>('bookings', params)

export function useSetTourStatus() {
  return useAdminMutation(({ id, status }: { id: number; status: TourStatus }) =>
    api.patch(`/admin/tours/${id}/status`, { status }), [tourKeys.all])
}

// --- Discounts --------------------------------------------------------------------

export const useDiscounts = (params: Params) => useAdminList<Discount>('discounts', params)

export interface DiscountPayload {
  code: string
  description: string | null
  type: DiscountType
  value: number
  max_redemptions: number | null
  starts_at: string | null
  expires_at: string | null
  is_active: boolean
}

export function useSaveDiscount(id: number | null) {
  return useAdminMutation((payload: DiscountPayload) =>
    id === null ? api.post('/admin/discounts', payload) : api.put(`/admin/discounts/${id}`, payload))
}

export function useDeleteDiscount() {
  return useAdminMutation((id: number) => api.delete(`/admin/discounts/${id}`))
}

// --- Catalog (not paginated) ----------------------------------------------------

export type CatalogKind = 'regions' | 'cities' | 'categories'
type CatalogItem = { regions: Region; cities: City; categories: Category }

export function useCatalog<K extends CatalogKind>(kind: K) {
  return useQuery({
    queryKey: [...adminKey, 'catalog', kind],
    queryFn: async () => (await api.get<{ data: CatalogItem[K][] }>(`/admin/${kind}`)).data.data,
  })
}

/** Create (id null), rename or delete a region, city or category. */
export function useCatalogMutation(kind: CatalogKind) {
  return useAdminMutation(
    ({ id, payload, remove = false }: { id: number | null; payload?: Record<string, string | number>; remove?: boolean }) => {
      if (remove && id !== null) return api.delete(`/admin/${kind}/${id}`)
      return id === null ? api.post(`/admin/${kind}`, payload) : api.put(`/admin/${kind}/${id}`, payload)
    },
    // Forms and filters across the site use this list.
    [referenceDataKey],
  )
}
