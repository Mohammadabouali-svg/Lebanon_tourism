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

export interface EmailChangePayload {
  email: string
  current_password: string
}

/*
 * Asks for a new address. The server only stores it as `pending_email` and
 * mails a link to it; `email` changes when that link is clicked.
 */
export function useRequestEmailChange() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (payload: EmailChangePayload) => {
      await api.post('/user/email-change', payload)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: userKey }),
  })
}

export function useCancelEmailChange() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      await api.delete('/user/email-change')
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: userKey }),
  })
}

export interface ConfirmEmailChangeParams {
  id: string
  hash: string
  expires: string
  signature: string
}

export function useConfirmEmailChange() {
  const queryClient = useQueryClient()
  return useMutation({
    /*
     * Same idea as useVerifyEmail: the link carries a signed API path, which
     * we rebuild exactly. Changing any part makes Laravel answer 403.
     */
    mutationFn: async ({ id, hash, expires, signature }: ConfirmEmailChangeParams) => {
      const path = `/email/change/${encodeURIComponent(id)}/${encodeURIComponent(hash)}`
      const query = `?expires=${encodeURIComponent(expires)}&signature=${encodeURIComponent(signature)}`
      return (await api.post<{ message: string }>(path + query)).data.message
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: userKey }),
  })
}

export function useUpdatePassword() {
  return useMutation({
    mutationFn: async (payload: PasswordPayload) => {
      await api.put('/user/password', payload)
    },
  })
}
