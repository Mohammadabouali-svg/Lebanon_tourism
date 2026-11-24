import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { userKey } from '@/features/auth/hooks'
import { api } from '@/lib/api'
import type { Envelope, User, WalletPage } from '@/lib/types'

export function useWallet(page: number) {
  return useQuery({
    queryKey: ['wallet', page],
    queryFn: async () => (await api.get<WalletPage>('/user/wallet', { params: { page } })).data,
    placeholderData: keepPreviousData,
  })
}

export function useUpdateProfile() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (payload: { name: string; phone: string }) =>
      (await api.put<Envelope<User>>('/user/profile', { ...payload, phone: payload.phone || null })).data.data,
    // The response is the updated user: put it straight into the cache.
    onSuccess: (user) => queryClient.setQueryData(userKey, user),
  })
}

export interface PasswordPayload {
  current_password: string
  password: string
  password_confirmation: string
}

export function useUpdatePassword() {
  return useMutation({
    mutationFn: async (payload: PasswordPayload) => {
      await api.put('/user/password', payload)
    },
  })
}
