import axios, { AxiosError, type InternalAxiosRequestConfig } from 'axios'

/*
 * The one HTTP client of the app. Every request to Laravel goes through here.
 *
 * Authentication uses Laravel's *session cookie*, not a token:
 *
 *   - The cookie is HttpOnly: JavaScript (ours, or an attacker's injected
 *     script) cannot read it. A token kept in localStorage can be stolen by
 *     any XSS bug; an HttpOnly cookie cannot.
 *   - The browser attaches the cookie automatically (`withCredentials`).
 *
 * Cookies alone would allow CSRF: another website making your browser send a
 * request to our API with your cookie. Laravel blocks that by requiring an
 * X-XSRF-TOKEN header on every POST/PUT/PATCH/DELETE. Axios copies the value
 * of the XSRF-TOKEN cookie into that header (`withXSRFToken`). A foreign site
 * cannot read our cookies, so it cannot produce the header.
 */
export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

let csrfCookieRequest: Promise<void> | null = null

/**
 * Makes sure the XSRF-TOKEN cookie exists. Laravel sets it in response to
 * GET /sanctum/csrf-cookie. We request it once and share the promise, so ten
 * simultaneous form submissions trigger a single extra request.
 */
export function ensureCsrfCookie(force = false): Promise<void> {
  if (force || csrfCookieRequest === null) {
    csrfCookieRequest = axios
      .get('/sanctum/csrf-cookie', { withCredentials: true })
      .then(() => undefined)
      .catch((error: unknown) => {
        csrfCookieRequest = null // try again next time
        throw error
      })
  }
  return csrfCookieRequest
}

const MUTATING_METHODS = new Set(['post', 'put', 'patch', 'delete'])

api.interceptors.request.use(async (config) => {
  if (MUTATING_METHODS.has((config.method ?? 'get').toLowerCase())) {
    await ensureCsrfCookie()
  }
  return config
})

type RetriableConfig = InternalAxiosRequestConfig & { _csrfRetried?: boolean }

// HTTP 419 = "CSRF token mismatch", typically because the session expired
// while the tab was open. Fetch a fresh token and replay the request once.
api.interceptors.response.use(undefined, async (error: AxiosError) => {
  const config = error.config as RetriableConfig | undefined
  if (error.response?.status === 419 && config && !config._csrfRetried) {
    config._csrfRetried = true
    await ensureCsrfCookie(true)
    return api.request(config)
  }
  return Promise.reject(error)
})

// --- Error helpers -----------------------------------------------------------

/** Field name → first error message, e.g. { email: "The email has already been taken." } */
export type FieldErrors = Record<string, string>

export interface ApiError {
  /** HTTP status, or null when the server could not be reached at all. */
  status: number | null
  /** A message that is safe to show to the user. */
  message: string
  /** Business-rule code from Laravel's BusinessRuleException, e.g. "insufficient_funds". */
  code: string | null
  fieldErrors: FieldErrors
}

interface LaravelErrorBody {
  message?: string
  code?: string
  errors?: Record<string, string[]>
}

/*
 * Turns any thrown value into an ApiError.
 *
 * Laravel answers with a few well-known shapes:
 *   422 validation      { message, errors: { field: ["...", ...] } }
 *   422 business rule   { message, code }
 *   401/403/404/429     { message }
 *   500                 { message } (generic in production)
 */
export function parseApiError(error: unknown): ApiError {
  if (!axios.isAxiosError(error)) {
    return { status: null, message: 'Something unexpected happened.', code: null, fieldErrors: {} }
  }

  if (!error.response) {
    return {
      status: null,
      message: 'Cannot reach the server. Check your connection and try again.',
      code: null,
      fieldErrors: {},
    }
  }

  const { status } = error.response
  const body = (error.response.data ?? {}) as LaravelErrorBody

  const fieldErrors: FieldErrors = {}
  for (const [field, messages] of Object.entries(body.errors ?? {})) {
    if (messages.length > 0) fieldErrors[field] = messages[0]
  }

  // Server errors may contain technical details in development: keep them in
  // the console, show the user something friendly.
  const message =
    status >= 500
      ? 'The server ran into a problem. Please try again in a moment.'
      : (body.message ?? defaultMessage(status))

  return { status, message, code: body.code ?? null, fieldErrors }
}

function defaultMessage(status: number): string {
  switch (status) {
    case 401:
      return 'Please log in to continue.'
    case 403:
      return 'You are not allowed to do this.'
    case 404:
      return 'This page or item does not exist.'
    case 429:
      return 'Too many attempts. Please wait a minute and try again.'
    default:
      return 'The request could not be completed.'
  }
}

export function isStatus(error: unknown, status: number): boolean {
  return axios.isAxiosError(error) && error.response?.status === status
}
