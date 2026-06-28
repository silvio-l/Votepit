const API_BASE = '/api'

async function getCsrfToken(): Promise<string> {
  const resp = await fetch(`${API_BASE}/csrf`, { credentials: 'include' })
  const data = await resp.json()
  return data.token
}

async function request<T>(
  method: string,
  path: string,
  body?: unknown,
): Promise<T> {
  const headers: Record<string, string> = {
    'Accept': 'application/json',
  }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    headers['X-CSRF-Token'] = await getCsrfToken()
  }

  const resp = await fetch(`${API_BASE}${path}`, {
    method,
    headers,
    credentials: 'include',
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })

  if (!resp.ok) {
    const error = await resp.json().catch(() => ({ message: resp.statusText }))
    throw new ApiError(resp.status, error.message ?? 'Unknown error')
  }

  return resp.json()
}

export class ApiError extends Error {
  status: number

  constructor(status: number, message: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
  }
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
  delete: <T>(path: string) => request<T>('DELETE', path),
}
