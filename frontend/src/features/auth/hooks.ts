import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, isStatus } from '@/lib/api'
import type { Envelope, Role, User } from '@/lib/types'

/*
 * "Who is logged in?" is server state like any other: it lives in the React
 * Query cache under the key ['user'].
 *
 * Login, logout and registration write the new answer into that cache
 * (setQueryData). Every component that calls useCurrentUser() re-renders,
 * from the navbar to the route guards, without a separate auth "store".
 */
export const userKey = ['user'] as const

async function fetchCurrentUser(): Promise<User | null> {
  try {
    const { data } = await api.get<Envelope<User>>('/user')
    return data.data
  } catch (error) {
    // 401 simply means "nobody is logged in". It is an answer, not a failure.
    if (isStatus(error, 401)) return null
    throw error
  }
}

export function useCurrentUser() {
  const query = useQuery({ queryKey: userKey, queryFn: fetchCurrentUser, staleTime: 5 * 60_000 })
  return { user: query.data ?? null, isLoading: query.isPending, query }
}

export function hasRole(user: User | null, ...roles: Role[]): boolean {
  return user !== null && roles.includes(user.role)
}

/** Where each kind of user lands after logging in. */
export function homePathFor(user: User): string {
  switch (user.role) {
    case 'admin':
      return '/admin'
    case 'operator':
      return '/operator'
    default:
      return '/tours'
  }
}

/**
 * Only follow ?redirect= values that are paths on this site. Accepting any
 * URL would be an "open redirect": a phishing e-mail could link to our real
 * login page with ?redirect=https://evil.example and users would trust it.
 * "//evil.example" and "/\evil.example" are refused too: browsers read both
 * as "another host".
 */
export function safeRedirect(target: string | null): string | null {
  if (!target || !target.startsWith('/') || target.startsWith('//') || target.startsWith('/\\')) return null
  return target
}

// --- Mutations ---------------------------------------------------------------

export interface LoginPayload {
  email: string
  password: string
  remember: boolean
}

export function useLogin() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (payload: LoginPayload) => (await api.post<Envelope<User>>('/login', payload)).data.data,
    onSuccess: (user) => {
      // Cached data may depend on who is asking (e.g. a review's can_delete).
      queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== userKey[0] })
      queryClient.setQueryData(userKey, user)
    },
  })
}

export function useLogout() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      await api.post('/logout')
    },
    onSuccess: () => {
      // Forget everything the previous user could see.
      queryClient.clear()
      queryClient.setQueryData(userKey, null)
    },
  })
}

export interface RegisterPayload {
  name: string
  email: string
  phone: string
  password: string
  password_confirmation: string
}

export interface OperatorRegisterPayload extends RegisterPayload {
  company_name: string
  business_phone: string
}

export function useRegister(kind: 'traveler' | 'operator') {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (payload: RegisterPayload | OperatorRegisterPayload) => {
      const url = kind === 'operator' ? '/register/operator' : '/register'
      return (await api.post<Envelope<User>>(url, payload)).data.data
    },
    onSuccess: (user) => queryClient.setQueryData(userKey, user),
  })
}

export function useForgotPassword() {
  return useMutation({
    mutationFn: async (email: string) => (await api.post<{ message: string }>('/forgot-password', { email })).data.message,
  })
}

export interface ResetPasswordPayload {
  token: string
  email: string
  password: string
  password_confirmation: string
}

export function useResetPassword() {
  return useMutation({
    mutationFn: async (payload: ResetPasswordPayload) => (await api.post<{ message: string }>('/reset-password', payload)).data.message,
  })
}

export interface VerifyEmailParams {
  id: string
  hash: string
  expires: string
  signature: string
}

export function useVerifyEmail() {
  const queryClient = useQueryClient()
  return useMutation({
    /*
     * The e-mail link carries a *signed* API path. The signature covers the
     * path and the `expires` value, so we rebuild exactly
     * /api/email/verify/{id}/{hash}?expires=...&signature=...
     * Changing any part (another id, a later expiry) makes Laravel reject it.
     */
    mutationFn: async ({ id, hash, expires, signature }: VerifyEmailParams) => {
      const path = `/email/verify/${encodeURIComponent(id)}/${encodeURIComponent(hash)}`
      const query = `?expires=${encodeURIComponent(expires)}&signature=${encodeURIComponent(signature)}`
      return (await api.post<{ message: string }>(path + query)).data.message
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: userKey }),
  })
}

export function useResendVerification() {
  return useMutation({
    mutationFn: async () => (await api.post<{ message: string }>('/email/verification-notification')).data.message,
  })
}
