import { request } from './client'

/** The three operator-nudge kinds; keys match OperatorNudgeKind::value on the server. */
export type OperatorNudgeKey = 'onboarding' | 'quiet_board' | 'plan_limit'

export type OperatorNudgePrefs = Record<OperatorNudgeKey, boolean>

/** GET /account/operator-nudges — the caller's own three switches (default on). */
export async function getOperatorNudges(): Promise<OperatorNudgePrefs> {
  return request<OperatorNudgePrefs>('GET', '/account/operator-nudges')
}

/** PUT /account/operator-nudges — sets the given switches; the caller's own row only. */
export async function saveOperatorNudges(
  prefs: Partial<OperatorNudgePrefs>,
): Promise<OperatorNudgePrefs & { ok: boolean }> {
  return request<OperatorNudgePrefs & { ok: boolean }>('PUT', '/account/operator-nudges', prefs)
}

/**
 * POST /operator-nudges/unsubscribe — one-click unsubscribe of ONE kind from a
 * signed mail link. No login; the token can only switch its own kind off.
 */
export async function unsubscribeOperatorNudge(
  token: string,
): Promise<{ ok: boolean; kind: OperatorNudgeKey }> {
  return request<{ ok: boolean; kind: OperatorNudgeKey }>('POST', '/operator-nudges/unsubscribe', {
    token,
  })
}
