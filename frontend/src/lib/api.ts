import axios, { AxiosError } from 'axios'

/**
 * Axios client for the REST API. Same origin as the web app (Traefik routes /api),
 * so no base URL or CORS is needed. Adds the bearer token and an X-Request-Id.
 */
export const api = axios.create({
  baseURL: '/api',
  headers: { Accept: 'application/json' },
  timeout: 20_000,
})

let tokenProvider: () => string | null = () => null
let onUnauthorized: () => void = () => {}

export function configureApi(opts: { token: () => string | null; onUnauthorized: () => void }) {
  tokenProvider = opts.token
  onUnauthorized = opts.onUnauthorized
}

api.interceptors.request.use((config) => {
  const token = tokenProvider()
  if (token) config.headers.Authorization = `Bearer ${token}`
  config.headers['X-Request-Id'] = crypto.randomUUID()
  return config
})

api.interceptors.response.use(
  (res) => res,
  (error: AxiosError) => {
    if (error.response?.status === 401 && !error.config?.url?.startsWith('/auth/login')) onUnauthorized()
    return Promise.reject(error)
  },
)

export interface ApiErrorBody {
  message: string
  errors?: Record<string, string[]>
}

/** Human readable message from any API / network error. */
export function errorMessage(error: unknown, fallback = 'Something went wrong'): string {
  if (axios.isAxiosError(error)) {
    const body = error.response?.data as ApiErrorBody | undefined
    if (body?.message) return body.message
    if (!error.response) return 'You are offline or the server is unreachable'
  }
  if (error instanceof Error) return error.message
  return fallback
}

export function fieldErrors(error: unknown): Record<string, string> {
  if (axios.isAxiosError(error)) {
    const errs = (error.response?.data as ApiErrorBody | undefined)?.errors ?? {}
    return Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0]]))
  }
  return {}
}
