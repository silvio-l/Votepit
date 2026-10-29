/**
 * Loads the board list as sections (G-09): one request per section (status
 * category or custom status), paged per section with "Load more". A response
 * that arrives after the filters moved on is dropped, so an older result can
 * never overwrite a newer one.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ApiError, BoardResponse, Idea } from '../../lib/api'
import { getBoard } from '../../lib/api'
import { GROUP_PAGE_SIZE } from '../../lib/boardGroups'

/** One list section: a status category, or one custom status by key. */
export interface SectionSpec {
  id: string
  status?: string
  statusKey?: string
  /** Every idea except done, declined and archived (the active list). */
  excludeClosed?: boolean
}

interface SectionState {
  ideas: Idea[]
  /** Ideas matching the filters across all pages. */
  total: number
  phase: 'loading' | 'done' | 'error'
  loadingMore: boolean
}

export interface BoardSectionsFilters {
  boardSlug: string
  specs: SectionSpec[]
  sort: string
  query: string
  tagId: number | null
  /** Re-runs every section when it changes (retry button). */
  retryKey: number
  /** Vote poll: fresh first pages of every section are handed to onPoll. */
  onPoll: (ideas: Idea[]) => void
}

export interface BoardSections {
  /** Board-level data from the first answered section (header, tags, flags). */
  meta: BoardResponse | null
  /** Set when no section could be loaded at all (404 or failure). */
  fatalError: ApiError | null
  sections: Record<string, SectionState>
  /** Every loaded idea, flattened in section order (vote state input). */
  allIdeas: Idea[]
  loadMore: (id: string) => void
}

/** Vote poll cadence while the tab is visible (matches the board's vote poll). */
const POLL_INTERVAL_MS = 20_000

const EMPTY_SECTION: SectionState = { ideas: [], total: 0, phase: 'loading', loadingMore: false }

function fetchSection(
  boardSlug: string,
  spec: SectionSpec,
  filters: Pick<BoardSectionsFilters, 'sort' | 'query' | 'tagId'>,
  page: number,
): Promise<BoardResponse> {
  return getBoard(boardSlug, {
    sort: filters.sort,
    status: spec.status,
    statusKey: spec.statusKey,
    excludeClosed: spec.excludeClosed,
    q: filters.query || undefined,
    tagId: filters.tagId ?? undefined,
    page,
  })
}

/** Appends fresh ideas, skipping any already listed (a poll or a shifted page). */
function mergeIdeas(current: Idea[], incoming: Idea[]): Idea[] {
  const seen = new Set(current.map((idea) => idea.id))
  return [...current, ...incoming.filter((idea) => !seen.has(idea.id))]
}

export function useBoardSections(filters: BoardSectionsFilters): BoardSections {
  const { boardSlug, specs, sort, query, tagId, retryKey, onPoll } = filters
  const specKey = specs
    .map((s) => `${s.id}:${s.status ?? ''}:${s.statusKey ?? ''}:${s.excludeClosed ? 'active' : ''}`)
    .join('|')
  const stableSpecs = useMemo(() => decodeSpecs(specKey), [specKey])

  const [sections, setSections] = useState<Record<string, SectionState>>({})
  const [meta, setMeta] = useState<BoardResponse | null>(null)
  const [fatalError, setFatalError] = useState<ApiError | null>(null)
  const generation = useRef(0)
  const onPollRef = useRef(onPoll)
  onPollRef.current = onPoll

  // biome-ignore lint/correctness/useExhaustiveDependencies: retryKey is a deliberate re-run trigger.
  useEffect(() => {
    if (!boardSlug) return
    const gen = ++generation.current
    const init: Record<string, SectionState> = {}
    for (const spec of stableSpecs) init[spec.id] = EMPTY_SECTION
    setSections(init)
    setFatalError(null)
    let answered = false
    let failures = 0

    for (const spec of stableSpecs) {
      fetchSection(boardSlug, spec, { sort, query, tagId }, 1)
        .then((data) => {
          if (gen !== generation.current) return
          if (!answered) {
            answered = true
            setMeta(data)
          }
          setSections((prev) => ({
            ...prev,
            [spec.id]: {
              ideas: data.ideas,
              total: data.total ?? data.ideas.length,
              phase: 'done',
              loadingMore: false,
            },
          }))
        })
        .catch((err: unknown) => {
          if (gen !== generation.current) return
          failures += 1
          setSections((prev) => ({
            ...prev,
            [spec.id]: { ...EMPTY_SECTION, phase: 'error' },
          }))
          if (failures === stableSpecs.length) setFatalError(err as ApiError)
        })
    }
  }, [boardSlug, stableSpecs, sort, query, tagId, retryKey])

  const loadMore = useCallback(
    (id: string) => {
      const spec = stableSpecs.find((s) => s.id === id)
      const current = sections[id]
      if (!spec || !current || current.loadingMore || current.phase !== 'done') return
      if (current.ideas.length >= current.total) return
      const gen = generation.current
      const page = Math.floor(current.ideas.length / GROUP_PAGE_SIZE) + 1
      setSections((prev) => ({ ...prev, [id]: { ...prev[id], loadingMore: true } }))
      fetchSection(boardSlug, spec, { sort, query, tagId }, page)
        .then((data) => {
          if (gen !== generation.current) return
          setSections((prev) => ({
            ...prev,
            [id]: {
              ideas: mergeIdeas(prev[id].ideas, data.ideas),
              total: data.total ?? prev[id].total,
              phase: 'done',
              loadingMore: false,
            },
          }))
        })
        .catch(() => {
          if (gen !== generation.current) return
          setSections((prev) => ({ ...prev, [id]: { ...prev[id], loadingMore: false } }))
        })
    },
    [boardSlug, stableSpecs, sections, sort, query, tagId],
  )

  // Vote poll: first pages of all sections, merged by the caller (votes only).
  useEffect(() => {
    if (meta === null) return
    const timer = setInterval(() => {
      if (document.hidden) return
      const fresh: Idea[] = []
      let pending = stableSpecs.length
      for (const spec of stableSpecs) {
        fetchSection(boardSlug, spec, { sort, query, tagId }, 1)
          .then((data) => fresh.push(...data.ideas))
          .catch(() => {
            // A transient poll failure is retried on the next tick.
          })
          .finally(() => {
            pending -= 1
            if (pending === 0 && fresh.length > 0) onPollRef.current(fresh)
          })
      }
    }, POLL_INTERVAL_MS)
    return () => clearInterval(timer)
  }, [meta, boardSlug, stableSpecs, sort, query, tagId])

  const allIdeas = useMemo(() => {
    const out: Idea[] = []
    const listed = new Set<number>()
    for (const spec of stableSpecs) {
      for (const idea of sections[spec.id]?.ideas ?? []) {
        if (listed.has(idea.id)) continue
        listed.add(idea.id)
        out.push(idea)
      }
    }
    return out
  }, [sections, stableSpecs])

  return { meta, fatalError, sections, allIdeas, loadMore }
}

/** Rebuilds the section list from its string key, so effects depend on a stable value. */
function decodeSpecs(key: string): SectionSpec[] {
  if (key === '') return []
  return key.split('|').map((part) => {
    const [id = '', status = '', statusKey = '', active = ''] = part.split(':')
    return {
      id,
      status: status === '' ? undefined : status,
      statusKey: statusKey === '' ? undefined : statusKey,
      excludeClosed: active === 'active' ? true : undefined,
    }
  })
}
