/**
 * Cookie-less HTTP client of the widget iframe (ADR 0005 §5). Every request
 * is `credentials: 'omit'`: the iframe never sends or receives cookies, so
 * there is no CSRF surface. Identified requests carry the short-lived widget
 * token as a Bearer header; it lives in memory only.
 */

export interface WidgetIdea {
  id: number
  title: string
  body: string
  status: string
  up_count: number
  down_count: number
  comment_count: number
  my_vote?: 'up' | 'down' | 'none'
}

export interface WidgetComment {
  id: number
  body: string
  created_at: string
}

export class WidgetApiError extends Error {
  readonly status: number
  readonly key: string

  constructor(status: number, key: string) {
    super(key)
    this.status = status
    this.key = key
  }
}

async function call<T>(
  method: string,
  url: string,
  token: string | null,
  body?: unknown,
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (token !== null) headers.Authorization = `Bearer ${token}`
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  const resp = await fetch(url, {
    method,
    headers,
    credentials: 'omit',
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })
  if (!resp.ok) {
    let key = 'http_error'
    try {
      const json = (await resp.json()) as { error?: { key?: unknown } }
      if (typeof json.error?.key === 'string') key = json.error.key
    } catch {
      // keep the generic key
    }
    throw new WidgetApiError(resp.status, key)
  }
  return resp.json() as Promise<T>
}

/** Exchanges the host's signed identify JWT for a widget token. */
export function identify(base: string, jwt: string) {
  return call<{ token: string; expires_in: number; user: { name: string | null } }>(
    'POST',
    `${base}/widget/identify`,
    jwt,
  )
}

/** Idea list — public read, or personalised (my_vote) with a token. */
export function listIdeas(base: string, token: string | null) {
  return call<{ ideas: WidgetIdea[] }>('GET', token === null ? base : `${base}/widget/ideas`, token)
}

/** Public idea detail with its comments. */
export function getIdea(base: string, id: number) {
  return call<{ idea: WidgetIdea; comments: WidgetComment[] }>('GET', `${base}/ideas/${id}`, null)
}

export function vote(base: string, token: string, id: number, value: 'up' | 'down') {
  return call<{ my_vote: 'up' | 'down' | 'none'; up_count: number; down_count: number }>(
    'POST',
    `${base}/widget/ideas/${id}/vote`,
    token,
    { value },
  )
}

export function comment(base: string, token: string, id: number, body: string) {
  return call<unknown>('POST', `${base}/widget/ideas/${id}/comments`, token, { body })
}

/** Time-trap stamp for the submit form (public GET). */
export function submitStamp(base: string) {
  return call<{ form_at: string }>('GET', `${base}/ideas/new`, null)
}

export function submitIdea(
  base: string,
  token: string,
  title: string,
  body: string,
  formAt: string,
) {
  return call<{ id: number; moderation_state?: 'pending' }>('POST', `${base}/widget/ideas`, token, {
    title,
    body,
    website: '',
    _form_at: formAt,
  })
}
