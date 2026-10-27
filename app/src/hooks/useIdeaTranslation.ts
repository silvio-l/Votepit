import { useEffect, useState } from 'react'
import {
  ApiError,
  type TranslatedComment,
  type TranslatedNotification,
  type TranslatedSupportMessage,
  type TranslateSupportMessagesResponse,
  translateBoardIntro,
  translateComments,
  translateIdea,
  translateNotifications,
} from '../lib/api'

/** True for a transient failure (rate-limited) that must never be cached — a retry (re-mount, reload) should always get a fresh attempt rather than being stuck on a stale "unavailable" verdict for the rest of the session. */
function isTransientFailure(err: unknown): boolean {
  return err instanceof ApiError && err.status === 429
}

/**
 * Module-level, session-lifetime cache — never persisted, cleared on a full
 * page reload. Keyed by `${ideaId}:${targetLocale}` so a language switch
 * always re-evaluates, but re-viewing the same idea/locale combination
 * within the session (ticket 07, AC "repeated views… don't re-request")
 * never re-hits the network, on top of the server-side cache from
 * tickets 05/06.
 */
type IdeaTranslationEntry =
  | { ok: true; translatedTitle: string | null; translatedBody: string; sameLanguage: boolean }
  | { ok: false }

const ideaCache = new Map<string, IdeaTranslationEntry>()

function ideaCacheKey(ideaId: number, targetLocale: string): string {
  return `${ideaId}:${targetLocale}`
}

/**
 * Test-only escape hatch — the caches above are intentionally module-level
 * (session-lifetime), which would otherwise leak a translation result from
 * one test's fake idea id/locale into another test reusing the same
 * id/locale. Not used by production code.
 */
export function _resetTranslationCachesForTests(): void {
  ideaCache.clear()
  commentsCache.clear()
  boardIntroCache.clear()
  notificationsCache.clear()
  supportMessagesCache.clear()
}

export interface IdeaTranslationResult {
  /** Idea title as it should currently render (translated or original, per showOriginal). */
  displayTitle: string
  /** Idea body as it should currently render (translated or original, per showOriginal). */
  displayBody: string
  /** True once a translation was actually fetched AND differs from the viewer's language. */
  isTranslated: boolean
  /** Whether the viewer is currently looking at the original despite isTranslated. */
  showOriginal: boolean
  setShowOriginal: (value: boolean) => void
  /** A translation was attempted and failed (503 / network error) — original still fully usable. */
  translationFailed: boolean
  /** Still waiting on the translate call (only true right after a cache miss). */
  loading: boolean
}

/**
 * Ticket 07: auto-translates an idea's title/body into `targetLocale` for a
 * logged-in voter, with no manual click. Call only when translation is
 * known to be available (bootstrap()'s `translation_available` flag) — pass
 * `enabled: false` otherwise and this hook never fires a request.
 */
export function useIdeaTranslation(
  boardSlug: string,
  ideaId: number,
  originalTitle: string,
  originalBody: string,
  targetLocale: string,
  enabled: boolean,
): IdeaTranslationResult {
  const [entry, setEntry] = useState<IdeaTranslationEntry | null>(null)
  const [showOriginal, setShowOriginal] = useState(false)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    setShowOriginal(false)

    if (!enabled) {
      setEntry(null)
      setLoading(false)
      return
    }

    const key = ideaCacheKey(ideaId, targetLocale)
    const cached = ideaCache.get(key)
    if (cached) {
      setEntry(cached)
      setLoading(false)
      return
    }

    let cancelled = false
    setEntry(null)
    setLoading(true)

    translateIdea(boardSlug, ideaId, targetLocale)
      .then((res) => {
        if (cancelled) return
        const value: IdeaTranslationEntry = {
          ok: true,
          translatedTitle: res.translated_title,
          translatedBody: res.translated_body,
          sameLanguage: res.detected_source_locale === targetLocale,
        }
        ideaCache.set(key, value)
        setEntry(value)
      })
      .catch((err) => {
        if (cancelled) return
        const value: IdeaTranslationEntry = { ok: false }
        if (!isTransientFailure(err)) {
          ideaCache.set(key, value)
        }
        setEntry(value)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [boardSlug, ideaId, targetLocale, enabled])

  const translationFailed = entry !== null && !entry.ok
  const isTranslated = Boolean(entry?.ok && !entry.sameLanguage)
  const useTranslated = isTranslated && !showOriginal

  return {
    displayTitle:
      useTranslated && entry?.ok ? (entry.translatedTitle ?? originalTitle) : originalTitle,
    displayBody: useTranslated && entry?.ok ? entry.translatedBody : originalBody,
    isTranslated,
    showOriginal,
    setShowOriginal,
    translationFailed,
    loading,
  }
}

// ── Comments (ticket 06's bulk endpoint, ticket 08's per-comment rendering) ────

type CommentTranslationMap = Map<number, TranslatedComment>

/**
 * Cached by idea/locale/comment-id-set — a new/removed comment changes the
 * id-set signature and therefore the cache key, which is a deliberate,
 * pragmatic choice: ticket 06 only ships a bulk (whole-thread) translate
 * endpoint, not a per-comment one, so a comment arriving after the initial
 * cascade re-triggers a bulk call for the WHOLE thread rather than a
 * dedicated single-comment request. The server-side cache (ticket 05/06)
 * makes re-translating the already-cached comments in that call cheap —
 * only the new comment actually reaches the provider.
 */
const commentsCache = new Map<string, CommentTranslationMap>()

function commentsCacheKey(ideaId: number, targetLocale: string, commentIds: number[]): string {
  return `${ideaId}:${targetLocale}:${commentIds
    .slice()
    .sort((a, b) => a - b)
    .join(',')}`
}

export interface CommentTranslationsResult {
  /** Per-comment result, present once the batch resolves; absent while loading/disabled. */
  results: CommentTranslationMap
  /** The whole batch call failed (translation not configured, network error) — comments render as originals. */
  batchFailed: boolean
  loading: boolean
}

/**
 * Ticket 07/08: bulk-translates every comment currently on the idea into
 * `targetLocale`, fired alongside useIdeaTranslation() for the same idea.
 * `commentIds` should be every comment id currently rendered — pass a new
 * array identity (e.g. from `comments.map(c => c.id)`) whenever the thread
 * changes so the effect below re-evaluates.
 */
export function useCommentTranslations(
  boardSlug: string,
  ideaId: number,
  commentIds: number[],
  targetLocale: string,
  enabled: boolean,
): CommentTranslationsResult {
  const [results, setResults] = useState<CommentTranslationMap>(new Map())
  const [batchFailed, setBatchFailed] = useState(false)
  const [loading, setLoading] = useState(false)

  const idsKey = commentIds
    .slice()
    .sort((a, b) => a - b)
    .join(',')

  // biome-ignore lint/correctness/useExhaustiveDependencies: idsKey is the real dependency — commentIds's array identity changes every render.
  useEffect(() => {
    if (!enabled || commentIds.length === 0) {
      setResults(new Map())
      setBatchFailed(false)
      setLoading(false)
      return
    }

    const key = commentsCacheKey(ideaId, targetLocale, commentIds)
    const cached = commentsCache.get(key)
    if (cached) {
      setResults(cached)
      setBatchFailed(false)
      setLoading(false)
      return
    }

    let cancelled = false
    setBatchFailed(false)
    setLoading(true)

    translateComments(boardSlug, ideaId, targetLocale)
      .then((res) => {
        if (cancelled) return
        const map: CommentTranslationMap = new Map(res.comments.map((c) => [c.id, c]))
        commentsCache.set(key, map)
        setResults(map)
      })
      .catch(() => {
        if (cancelled) return
        // Unlike ideaCache, a failed batch is never written to
        // commentsCache — the next mount's cache lookup above just misses
        // and retries, so a transient (429) failure already self-heals
        // without any special-casing here.
        setResults(new Map())
        setBatchFailed(true)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [boardSlug, ideaId, targetLocale, enabled, idsKey])

  return { results, batchFailed, loading }
}

// ── Board intro (single item, mirrors useIdeaTranslation) ──────────────────────

type BoardIntroTranslationEntry =
  | { ok: true; translatedBody: string; sameLanguage: boolean }
  | { ok: false }

const boardIntroCache = new Map<string, BoardIntroTranslationEntry>()

function boardIntroCacheKey(boardSlug: string, targetLocale: string): string {
  return `${boardSlug}:${targetLocale}`
}

export interface BoardIntroTranslationResult {
  displayIntro: string
  isTranslated: boolean
  showOriginal: boolean
  setShowOriginal: (value: boolean) => void
  translationFailed: boolean
  loading: boolean
}

/**
 * Single-item board-intro translation — same auto-translate/cache/toggle
 * shape as useIdeaTranslation(), for a board's intro text instead of an
 * idea's title+body. Call only when translation is known to be available
 * AND the board has opted in (`translation_enabled`) — pass `enabled: false`
 * otherwise.
 */
export function useBoardIntroTranslation(
  boardSlug: string,
  originalIntro: string,
  targetLocale: string,
  enabled: boolean,
): BoardIntroTranslationResult {
  const [entry, setEntry] = useState<BoardIntroTranslationEntry | null>(null)
  const [showOriginal, setShowOriginal] = useState(false)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    setShowOriginal(false)

    if (!enabled || originalIntro === '') {
      setEntry(null)
      setLoading(false)
      return
    }

    const key = boardIntroCacheKey(boardSlug, targetLocale)
    const cached = boardIntroCache.get(key)
    if (cached) {
      setEntry(cached)
      setLoading(false)
      return
    }

    let cancelled = false
    setEntry(null)
    setLoading(true)

    translateBoardIntro(boardSlug, targetLocale)
      .then((res) => {
        if (cancelled) return
        const value: BoardIntroTranslationEntry = {
          ok: true,
          translatedBody: res.translated_body,
          sameLanguage: res.detected_source_locale === targetLocale,
        }
        boardIntroCache.set(key, value)
        setEntry(value)
      })
      .catch((err) => {
        if (cancelled) return
        const value: BoardIntroTranslationEntry = { ok: false }
        if (!isTransientFailure(err)) {
          boardIntroCache.set(key, value)
        }
        setEntry(value)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [boardSlug, originalIntro, targetLocale, enabled])

  const translationFailed = entry !== null && !entry.ok
  const isTranslated = Boolean(entry?.ok && !entry.sameLanguage)
  const useTranslated = isTranslated && !showOriginal

  return {
    displayIntro: useTranslated && entry?.ok ? entry.translatedBody : originalIntro,
    isTranslated,
    showOriginal,
    setShowOriginal,
    translationFailed,
    loading,
  }
}

// ── Notifications (bulk, mirrors useCommentTranslations) ───────────────────────

type NotificationTranslationMap = Map<number, TranslatedNotification>

const notificationsCache = new Map<string, NotificationTranslationMap>()

function notificationsCacheKey(targetLocale: string, notificationIds: number[]): string {
  return `${targetLocale}:${notificationIds
    .slice()
    .sort((a, b) => a - b)
    .join(',')}`
}

export interface NotificationTranslationsResult {
  results: NotificationTranslationMap
  batchFailed: boolean
  loading: boolean
}

/**
 * Bulk-translates every currently-listed notification (own inbox items plus
 * any visible broadcast announcements) into `targetLocale`, mirroring
 * useCommentTranslations()'s whole-batch-in-one-call shape. Not board-bound
 * — `enabled` should reflect only `translation_available` from bootstrap().
 */
export function useNotificationTranslations(
  notificationIds: number[],
  targetLocale: string,
  enabled: boolean,
): NotificationTranslationsResult {
  const [results, setResults] = useState<NotificationTranslationMap>(new Map())
  const [batchFailed, setBatchFailed] = useState(false)
  const [loading, setLoading] = useState(false)

  const idsKey = notificationIds
    .slice()
    .sort((a, b) => a - b)
    .join(',')

  // biome-ignore lint/correctness/useExhaustiveDependencies: idsKey is the real dependency — notificationIds's array identity changes every render.
  useEffect(() => {
    if (!enabled || notificationIds.length === 0) {
      setResults(new Map())
      setBatchFailed(false)
      setLoading(false)
      return
    }

    const key = notificationsCacheKey(targetLocale, notificationIds)
    const cached = notificationsCache.get(key)
    if (cached) {
      setResults(cached)
      setBatchFailed(false)
      setLoading(false)
      return
    }

    let cancelled = false
    setBatchFailed(false)
    setLoading(true)

    translateNotifications(notificationIds, targetLocale)
      .then((res) => {
        if (cancelled) return
        const map: NotificationTranslationMap = new Map(res.notifications.map((n) => [n.id, n]))
        notificationsCache.set(key, map)
        setResults(map)
      })
      .catch(() => {
        if (cancelled) return
        setResults(new Map())
        setBatchFailed(true)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [targetLocale, enabled, idsKey])

  return { results, batchFailed, loading }
}

// ── Support messages (bulk, mirrors useCommentTranslations) ────────────────────

type SupportMessageTranslationMap = Map<number, TranslatedSupportMessage>

const supportMessagesCache = new Map<string, SupportMessageTranslationMap>()

function supportMessagesCacheKey(
  requestId: number,
  targetLocale: string,
  messageIds: number[],
): string {
  return `${requestId}:${targetLocale}:${messageIds
    .slice()
    .sort((a, b) => a - b)
    .join(',')}`
}

export interface SupportMessageTranslationsResult {
  results: SupportMessageTranslationMap
  batchFailed: boolean
  loading: boolean
}

/**
 * Bulk-translates every message of one support ticket into `targetLocale`.
 * `translateFn` is injected so the same hook serves both the customer side
 * (translateMySupportThread) and the operator side (
 * translateOperatorSupportThread) — same endpoint response shape, different
 * scoping on the server.
 */
export function useSupportMessageTranslations(
  requestId: number,
  messageIds: number[],
  targetLocale: string,
  enabled: boolean,
  translateFn: (id: number, targetLocale: string) => Promise<TranslateSupportMessagesResponse>,
): SupportMessageTranslationsResult {
  const [results, setResults] = useState<SupportMessageTranslationMap>(new Map())
  const [batchFailed, setBatchFailed] = useState(false)
  const [loading, setLoading] = useState(false)

  const idsKey = messageIds
    .slice()
    .sort((a, b) => a - b)
    .join(',')

  // biome-ignore lint/correctness/useExhaustiveDependencies: idsKey is the real dependency — messageIds's array identity changes every render.
  useEffect(() => {
    if (!enabled || messageIds.length === 0) {
      setResults(new Map())
      setBatchFailed(false)
      setLoading(false)
      return
    }

    const key = supportMessagesCacheKey(requestId, targetLocale, messageIds)
    const cached = supportMessagesCache.get(key)
    if (cached) {
      setResults(cached)
      setBatchFailed(false)
      setLoading(false)
      return
    }

    let cancelled = false
    setBatchFailed(false)
    setLoading(true)

    translateFn(requestId, targetLocale)
      .then((res) => {
        if (cancelled) return
        const map: SupportMessageTranslationMap = new Map(res.messages.map((m) => [m.id, m]))
        supportMessagesCache.set(key, map)
        setResults(map)
      })
      .catch(() => {
        if (cancelled) return
        setResults(new Map())
        setBatchFailed(true)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [requestId, targetLocale, enabled, idsKey, translateFn])

  return { results, batchFailed, loading }
}
