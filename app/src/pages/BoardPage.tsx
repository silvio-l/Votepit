import type { SortValue } from '@votepit/ui'
import { Celebration, SortTabs } from '@votepit/ui'
import type { IdeaStatus as ComponentStatus } from '@votepit/ui/ds'
import {
  Avatar,
  Button,
  ButtonLink,
  cx,
  DsMenu,
  type DsMenuItem,
  EmptyState,
  ErrorState,
  IdeaRow,
  LoadingState,
  PageShell,
  SearchField,
  ShareButton,
  type ShareOutcome,
  Sheet,
  StatusBubbles,
  type StatusTone,
  statusChipFor,
  Toast,
} from '@votepit/ui/ds'

import { Globe, Plus, SlidersHorizontal } from 'lucide-react'
import type { ComponentPropsWithoutRef } from 'react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { BoardBrandFooter } from '../components/BoardBrandFooter'
import { BoardCoverBand } from '../components/BoardCoverBand'
import { BoardPhaseLine } from '../components/BoardPhaseLine'
import { BoardProfileTabs } from '../components/BoardProfileTabs'
import { BoardThemeMode } from '../components/BoardThemeMode'
import { BoardSection } from '../components/board/BoardSection'
import { type SectionSpec, useBoardSections } from '../components/board/useBoardSections'
import { DecisionReasonFlag } from '../components/DecisionBlock'
import {
  boardRoadmapVisible,
  LocalizedHeader,
  ScopeLabel,
  useIsPhone,
} from '../components/LocalizedHeader'
import { MarkdownLite } from '../components/MarkdownLite'
import { NotFoundState } from '../components/NotFoundState'
import { TagBadges } from '../components/TagBadges'
import { VoteReasonFollowUp } from '../components/VoteReasonFollowUp'
import { useBoardVotes } from '../hooks/useBoardVotes'
import { useDsLabels } from '../hooks/useDsLabels'
import { useFlipReorder } from '../hooks/useFlipReorder'
import { useIdeaCardLabels } from '../hooks/useIdeaCardLabels'
import { useBoardIntroTranslation, useIdeaTranslation } from '../hooks/useIdeaTranslation'
import { useInView } from '../hooks/useInView'
import {
  createPublicProfileCache,
  type PublicProfileCache,
  type PublicProfileState,
  usePublicProfile,
} from '../hooks/usePublicProfile'
import type { VoteState } from '../hooks/useVote'
import { useVoteReasonFollowUp } from '../hooks/useVoteReasonFollowUp'
import { useVoterPreview } from '../hooks/useVoterPreview'
import { accountPath, getAccountSlug } from '../lib/accountContext'
import type { ApiError, BoardStatusInfo, Idea, IdeaStatus, User } from '../lib/api'
import { accountRoleFor, bootstrap, getDefaultBoardSlug, logout } from '../lib/api'
import { GROUP_ORDER, readCollapsedGroups, writeCollapsedGroups } from '../lib/boardGroups'
import {
  boardInitialsName,
  boardLogoSrc,
  boardWordmarkPlated,
  boardWordmarkSrc,
} from '../lib/boardLogo'
import { blockedReasonKey } from '../lib/boardPhases'
import { statusAllows, statusLabel, toComponentStatus } from '../lib/boardStatuses'
import { type BrandingPro, brandingProAttributes, brandingStyle } from '../lib/branding'
import { crossedThreshold, hasCelebrated, markCelebrated } from '../lib/celebrations'
import { getFeatures, legalLinksFor } from '../lib/features'
import { wordingOverride } from '../lib/i18n/boardWording'
import { type Language, useI18n, useT } from '../lib/i18n/context'
import { useResolvedTheme } from '../lib/theme'

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Adapts react-router's Link to the `href` shape of the DS ButtonLink. */
function RouterLink({ href, ...props }: ComponentPropsWithoutRef<'a'> & { href: string }) {
  return <Link to={href} {...props} />
}

/** Simple relative-time formatter (no i18n lib dependency). */
function formatTimeAgo(iso: string, t: ReturnType<typeof useT>): string {
  const created = new Date(iso.replace(' ', 'T'))
  const diffMs = Date.now() - created.getTime()
  const mins = Math.floor(diffMs / 60_000)
  if (mins < 2) return t('timeAgoJustNow')
  if (mins < 60) return t('timeAgoMinutes', { count: mins })
  const hours = Math.floor(mins / 60)
  if (hours < 24) return t('timeAgoHours', { count: hours })
  const days = Math.floor(hours / 24)
  if (days < 30) return t(days === 1 ? 'timeAgoDaySingular' : 'timeAgoDayPlural', { count: days })
  const months = Math.floor(days / 30)
  if (months < 12) {
    return t(months === 1 ? 'timeAgoMonthSingular' : 'timeAgoMonthPlural', { count: months })
  }
  const years = Math.floor(months / 12)
  return t(years === 1 ? 'timeAgoYearSingular' : 'timeAgoYearPlural', { count: years })
}

/**
 * Author label for a row, with the same rules as AuthorBadge on the idea detail:
 * visible profile -> username (or "Voter"), anonymous -> "Voter", shared guest -> "Guest",
 * self-deleted placeholder -> "Deleted user". While loading: undefined (no name yet).
 */
function authorLabelFor(state: PublicProfileState, t: ReturnType<typeof useT>): string | undefined {
  if (state.status === 'loading') return undefined
  if (state.status === 'error') return t('voter')
  const profile = state.profile
  if (profile.visible === false && profile.guest === true) return t('guest')
  if (profile.visible === false && profile.deleted === true) return t('deletedUser')
  if (profile.visible === true && profile.username) return profile.username
  return t('voter')
}

// ── Sort mapping ──────────────────────────────────────────────────────────────

function sortValueToApi(sv: SortValue): string {
  const map: Record<SortValue, string> = {
    top: 'top',
    newest: 'newest',
    controversial: 'controversial',
  }
  return map[sv]
}

/** Converts the component Status type (hyphen) to the backend allow-list value (underscore). */
function statusToApi(s: ComponentStatus | null): string | undefined {
  if (s === null) return undefined
  if (s === 'in-progress') return 'in_progress'
  return s
}

/** Translated default labels of the six categories (owner overrides win, see lib/boardStatuses). */
function useStatusDefaults(): Record<IdeaStatus, string> {
  const tCommon = useT('common')
  return {
    open: tCommon('status.open'),
    planned: tCommon('status.planned'),
    in_progress: tCommon('status.inProgress'),
    done: tCommon('status.done'),
    declined: tCommon('status.declined'),
    archived: tCommon('status.archived'),
  }
}

/**
 * Status word + vote lock of one idea, resolved against the board's registry.
 * Votes = voting window (server-computed, issue 04) AND the status flag
 * (issue 03); `voteReason` names whichever closes them.
 */
function useIdeaStatus(
  idea: Idea,
  statuses: BoardStatusInfo[] | undefined,
  language: string,
  votingPhaseReason: string | null,
): { label: string; votesClosed: boolean; voteReason: string | null } {
  const defaults = useStatusDefaults()
  const tCommon = useT('common')
  const statusClosed = !statusAllows(statuses, idea.status_key ?? idea.status, 'votes')
  const voteReason = votingPhaseReason ?? (statusClosed ? tCommon('status.votesClosed') : null)
  return {
    label: statusLabel(statuses, idea.status_key, idea.status, language, defaults),
    votesClosed: voteReason !== null,
    voteReason,
  }
}

// ── BoardIdeaRow ──────────────────────────────────────────────────────────────

interface BoardIdeaRowProps {
  idea: Idea
  variant?: 'desktop' | 'phone'
  boardSlug: string
  /** Shared per page; the author name comes from the same public profile rules as the idea detail. */
  profileCache: PublicProfileCache
  currentUserId: number | null
  vote: VoteState
  onVoteUp: () => void
  onVoteDown: () => void
  /** translation_available (bootstrap) && is_authenticated && board.translation_enabled. */
  translationEnabled: boolean
  language: string
  /** The board's status registry (labels + vote lock), issue 03. */
  statuses?: BoardStatusInfo[]
  /** Owner wording for the vote words (Pro); null/undefined = Votepit's text. */
  brandingPro?: BrandingPro | null
  /** Why the voting window refuses votes right now (issue 04), null = open. */
  votingPhaseReason: string | null
}

/**
 * One idea as a dense list row (G-09). The excerpt is machine-translated once the
 * row scrolls into view, so the translation budget is not spent on unseen rows.
 */
function BoardIdeaRow({
  idea,
  variant = 'desktop',
  boardSlug,
  profileCache,
  currentUserId,
  vote,
  onVoteUp,
  onVoteDown,
  translationEnabled,
  language,
  statuses,
  votingPhaseReason,
  brandingPro,
}: BoardIdeaRowProps) {
  const tCommon = useT('common')
  const ideaStatus = useIdeaStatus(idea, statuses, language, votingPhaseReason)
  const cardLabels = useIdeaCardLabels(ideaStatus.voteReason, brandingPro)
  const t = useT('boardPage')
  const tAuthor = useT('authorBadge')
  const isSelf = currentUserId !== null && idea.author_id === currentUserId
  const profileState = usePublicProfile(isSelf ? null : idea.author_id, profileCache)
  const authorName = isSelf ? tAuthor('you') : authorLabelFor(profileState, tAuthor)
  const authorLine =
    authorName === undefined ? undefined : `${authorName} · ${formatTimeAgo(idea.created_at, t)}`
  // Photo only for a public profile; otherwise the placeholder name and initials.
  const authorProfile = profileState.status === 'ready' ? profileState.profile : null
  const authorAvatar =
    authorName === undefined
      ? undefined
      : { name: authorName, src: authorProfile?.visible === true ? authorProfile.avatar_url : null }

  const [inViewRef, inView] = useInView()
  const translation = useIdeaTranslation(
    boardSlug,
    idea.id,
    idea.title,
    idea.body,
    language,
    translationEnabled && inView,
  )

  return (
    <div ref={inViewRef}>
      <IdeaRow
        testId={`board-idea-${idea.id}`}
        title={translation.displayTitle}
        href={accountPath(`/${boardSlug}/idea/${idea.id}`)}
        status={toComponentStatus(idea.status)}
        statusLabel={ideaStatus.label}
        excerpt={<MarkdownLite text={translation.displayBody} maxChars={120} />}
        tags={
          (idea.tags?.length ?? 0) > 0 || idea.has_decision_reason === true ? (
            <>
              {(idea.tags?.length ?? 0) > 0 && (
                <TagBadges
                  tags={idea.tags}
                  boardSlug={boardSlug}
                  testIdPrefix={`board-idea-${idea.id}`}
                />
              )}
              {idea.has_decision_reason === true && <DecisionReasonFlag ideaId={idea.id} />}
            </>
          ) : undefined
        }
        up={vote.upCount}
        down={vote.downCount}
        commentCount={idea.comment_count}
        commentLabel={cardLabels.commentLabel}
        votedUp={vote.myVote === 'up'}
        votedDown={vote.myVote === 'down'}
        readonlyVotes={ideaStatus.votesClosed}
        onVote={(direction) => (direction === 'up' ? onVoteUp() : onVoteDown())}
        pinnedLabel={idea.is_pinned ? tCommon('idea.pinnedLabel') : undefined}
        authorLine={authorLine}
        authorAvatar={authorAvatar}
        translateControl={
          translation.isTranslated ? (
            <button
              type="button"
              className="ds-idea-row__translate"
              aria-label={
                translation.showOriginal ? t('showTranslationLink') : t('showOriginalLink')
              }
              title={translation.showOriginal ? t('showTranslationLink') : t('showOriginalLink')}
              data-testid={`board-idea-${idea.id}-translate`}
              onClick={(e) => {
                e.preventDefault()
                translation.setShowOriginal(!translation.showOriginal)
              }}
            >
              <Globe size={16} aria-hidden="true" />
            </button>
          ) : undefined
        }
        voteLabels={cardLabels.voteLabels}
        summaryLabels={cardLabels.summaryLabels}
        locale={cardLabels.locale}
        variant={variant}
      />
    </div>
  )
}

// ── Component ─────────────────────────────────────────────────────────────────

/** Stable identity across renders — avoids re-seeding useBoardVotes on every render while loading. */
const EMPTY_IDEAS: Idea[] = []

/** `?tag_id=` as a positive board tag id, or null when absent or malformed. */
function parseTagIdParam(raw: string | null): number | null {
  if (raw === null || !/^[0-9]+$/.test(raw)) return null
  const id = Number(raw)
  return id > 0 ? id : null
}

/** Typing pause before the search field commits its text to `?q=` (one request per pause). */
const SEARCH_DEBOUNCE_MS = 300

/** "{count} idea(s)" for the board stats; the owner's noun replaces the word only (no sentence rewrite). */
function statIdeaLabel(
  wording: BrandingPro['wording'] | undefined,
  language: Language,
  count: number,
  t: ReturnType<typeof useT>,
): string {
  const noun = wordingOverride(wording, language, count === 1 ? 'idea_singular' : 'idea_plural')
  return noun !== undefined
    ? `${count} ${noun}`
    : t(count === 1 ? 'statIdeasSingular' : 'statIdeasPlural', { count })
}

export default function BoardPage() {
  const resolvedTheme = useResolvedTheme()
  const [profileCache] = useState(() => createPublicProfileCache())
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()
  const t = useT('boardPage')
  const tCommon = useT('common')
  const { language } = useI18n()
  const statusDefaults = useStatusDefaults()

  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  // idea-comment-translation, ticket 07 board-list follow-up — bootstrap()'s
  // fail-secure flag, same source as IdeaDetailPage's translationAvailable.
  const [translationAvailable, setTranslationAvailable] = useState(false)
  const [sort, setSort] = useState<SortValue>('top')
  const [status, setStatus] = useState<ComponentStatus | null>(null)
  // Exact owner-defined status (`?status_key=`), mutually exclusive with `status`.
  const [statusKey, setStatusKey] = useState<string | null>(null)
  // Groups the viewer folded away (Done / Declined / Archived start folded).
  const [collapsed, setCollapsed] = useState<Set<IdeaStatus>>(() => readCollapsedGroups())
  const isPhone = useIsPhone()
  const [filtersOpen, setFiltersOpen] = useState(false)
  // Free-text search (competitor-parity H6). `?q=` is the source of truth
  // (shareable/bookmarkable); `searchInput` is the field's live text, committed
  // to the URL after a typing pause. `committedQueryRef` holds the last value
  // this page wrote, so an external URL change (a link to the plain board) can
  // be told apart from our own write and reset the field instead of being
  // overwritten by it.
  const [searchParams, setSearchParams] = useSearchParams()
  const query = (searchParams.get('q') ?? '').trim()
  // Board tag filter (G-09, P-34): `?tag_id=` is the source of truth, so a tag
  // link on a row or the detail page opens this board filtered by that tag.
  const tagId = parseTagIdParam(searchParams.get('tag_id'))
  const setTagId = useCallback(
    (next: number | null) => {
      setSearchParams(
        (prev) => {
          const params = new URLSearchParams(prev)
          if (next === null) params.delete('tag_id')
          else params.set('tag_id', String(next))
          return params
        },
        { replace: true },
      )
    },
    [setSearchParams],
  )
  const [searchInput, setSearchInput] = useState(query)
  const committedQueryRef = useRef(query)
  const [retryKey, setRetryKey] = useState(0)
  // A rejected vote rolls the mark back; the toast says why it snapped back.
  const [voteFailed, setVoteFailed] = useState(false)
  const [shareNotice, setShareNotice] = useState<'copied' | 'failed' | null>(null)
  const [participationExpanded, setParticipationExpanded] = useState(false)
  const [participationClamped, setParticipationClamped] = useState(false)

  // Owner/admin "view as voter" toggle — shared across every
  // board-scoped page (see useVoterPreview.ts) so it survives client-side
  // navigation to the roadmap, an idea, submit or edit without every link
  // needing to carry `?view=voter` itself.
  const [viewAsVoter, setViewAsVoterPreview] = useVoterPreview()

  // Board-owner momentum milestones — a small, curated set (first idea ever,
  // first idea from someone other than the owner, 10 ideas total),
  // deliberately kept short (see BoardPage's celebration effect below for
  // the "why these three" rationale) and celebrated with the sparkle effect,
  // never the confetti rain that's reserved for the referral reward.
  const [milestone, setMilestone] = useState<null | {
    kind: 'first-idea' | 'first-external' | 'ten-ideas'
  }>(null)

  // Fetch bootstrap once on mount to seed CSRF token + auth state.
  useEffect(() => {
    bootstrap()
      .then((b) => {
        setIsAuthenticated(b.user !== null)
        setUser(b.user)
        setTranslationAvailable(b.translation_available ?? false)
      })
      .catch(() => {
        // Bootstrap failure is non-fatal — continue without auth context.
      })
  }, [])

  // Sections of the list (owner change 2026-10-22): one flat active list (open, planned,
  // in progress and custom active statuses), then Done, Declined and Archived as collapsible
  // sections. A status or custom-status filter shows just that status as one flat list.
  const specs: SectionSpec[] =
    statusKey !== null
      ? [{ id: `key-${statusKey}`, statusKey }]
      : status !== null
        ? [{ id: `status-${status}`, status: statusToApi(status) }]
        : [
            { id: 'active', excludeClosed: true },
            { id: 'done', status: 'done' },
            { id: 'declined', status: 'declined' },
            { id: 'archived', status: 'archived' },
          ]
  const grouped = statusKey === null && status === null

  const sortApi = sortValueToApi(sort)
  // The vote poll merges into useBoardVotes, which is created after the sections hook.
  const mergeRef = useRef<(ideas: Idea[]) => void>(() => {})
  const onPoll = useCallback((ideas: Idea[]) => mergeRef.current(ideas), [])
  const { meta, fatalError, sections, allIdeas, loadMore } = useBoardSections({
    boardSlug: boardSlug ?? '',
    specs,
    sort: sortApi,
    query,
    tagId,
    retryKey,
    onPoll,
  })

  const boardIdeas = allIdeas.length > 0 ? allIdeas : EMPTY_IDEAS
  const anonymousVotingEnabled = meta?.board.anonymous_voting_enabled ?? false
  // P-03: optional reason after a vote against (per board opt-in, default off).
  const voteReasonsEnabled =
    meta?.board.vote_reasons_enabled === true && getFeatures().vote_reasons !== false
  const voteReasonFollowUp = useVoteReasonFollowUp(boardSlug ?? '')
  const { open: openReasonFollowUp } = voteReasonFollowUp
  const onVotedDown = useCallback(
    (ideaId: number) => {
      if (voteReasonsEnabled) openReasonFollowUp(ideaId)
    },
    [voteReasonsEnabled, openReasonFollowUp],
  )
  const { registerRow, captureBeforeReorder } = useFlipReorder<number>()
  const dsLabels = useDsLabels()
  const {
    get: getVote,
    castVote,
    mergeFromPoll,
  } = useBoardVotes(
    boardSlug ?? '',
    boardIdeas,
    isAuthenticated,
    captureBeforeReorder,
    () => setVoteFailed(true),
    anonymousVotingEnabled,
    onVotedDown,
  )
  mergeRef.current = mergeFromPoll

  // The board response is the authority on the viewer's session (the account menu depends on it).
  useEffect(() => {
    if (meta !== null) setIsAuthenticated(meta.is_authenticated)
  }, [meta])

  // Root route `/` has no :boardSlug yet — resolve the account's default board and
  // redirect there. Without this, the effect below would return early forever and the
  // page would stay stuck on the loading state.
  const [rootError, setRootError] = useState<'notFound' | 'failure' | null>(null)
  useEffect(() => {
    if (boardSlug) return

    getDefaultBoardSlug()
      .then(({ slug }) => navigate(accountPath(`/${slug}`), { replace: true }))
      .catch((err: unknown) => {
        const apiErr = err as ApiError
        // 404 covers "no public board" and "unknown account" alike: both render the
        // shared not-found page, so the response never reveals which one it was.
        const notFound = apiErr.name === 'ApiError' && apiErr.status === 404
        setRootError(notFound ? 'notFound' : 'failure')
      })
  }, [boardSlug, navigate])

  // Commit the field's text to `?q=` after a typing pause (debounced).
  useEffect(() => {
    const next = searchInput.trim()
    if (next === committedQueryRef.current) return

    const timer = setTimeout(() => {
      committedQueryRef.current = next
      setSearchParams(
        (prev) => {
          const params = new URLSearchParams(prev)
          if (next === '') params.delete('q')
          else params.set('q', next)
          return params
        },
        { replace: true },
      )
    }, SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(timer)
  }, [searchInput, setSearchParams])

  // `?q=` changed from outside (navigation to the plain board, a shared link):
  // mirror it into the field instead of letting the debounce write it back.
  useEffect(() => {
    if (query === committedQueryRef.current) return
    committedQueryRef.current = query
    setSearchInput(query)
  }, [query])

  // Owner-only momentum milestones. Priority order matters: only the
  // FIRST milestone newly reached this tick is shown, never several
  // stacked at once. "first-external" is checked against whatever ideas
  // happen to be loaded on the current page/sort/filter — a best-effort
  // per-browser nicety, not a backend-verified fact, same tradeoff every
  // other localStorage-only celebration in this app already makes.
  useEffect(() => {
    if (meta === null || user === null) return
    const role = accountRoleFor(user, getAccountSlug())
    const isBoardOwner = user.is_admin || role === 'owner'
    if (!isBoardOwner) return

    const { board, stats } = meta

    if (crossedThreshold(`board:${board.slug}:total-ideas:first`, 1, stats.total_ideas)) {
      setMilestone({ kind: 'first-idea' })
      return
    }

    const externalKey = `board:${board.slug}:first-external-idea`
    if (!hasCelebrated(externalKey) && allIdeas.some((idea) => idea.author_id !== user.id)) {
      markCelebrated(externalKey)
      setMilestone({ kind: 'first-external' })
      return
    }

    if (crossedThreshold(`board:${board.slug}:total-ideas:ten`, 10, stats.total_ideas)) {
      setMilestone({ kind: 'ten-ideas' })
    }
  }, [meta, user, allIdeas])

  // Auto-dismiss, same rationale/duration as the referral-reward celebration.
  useEffect(() => {
    if (milestone === null) return
    const timer = setTimeout(() => setMilestone(null), 6000)
    return () => clearTimeout(timer)
  }, [milestone])

  const handleSortChange = (newSort: SortValue) => {
    setSort(newSort)
  }

  const handleStatusChange = (newStatus: ComponentStatus | null) => {
    setStatus(newStatus)
    setStatusKey(null)
    // sort is intentionally preserved — invariant: sort survives filter changes
  }

  const handleStatusKeyChange = (key: string) => {
    setStatus(null)
    setStatusKey(key)
  }

  const handleClearSearch = () => {
    // Clear immediately — no reason to wait out the debounce for a reset.
    committedQueryRef.current = ''
    setSearchInput('')
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev)
        params.delete('q')
        return params
      },
      { replace: true },
    )
  }

  const handleClearAllFilters = () => {
    // One URL write for q and tag_id: two setSearchParams calls in a row would
    // each start from the same stale search string and undo each other.
    committedQueryRef.current = ''
    setSearchInput('')
    setStatus(null)
    setStatusKey(null)
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev)
        params.delete('q')
        params.delete('tag_id')
        return params
      },
      { replace: true },
    )
  }

  const handleToggleSection = (id: IdeaStatus) => {
    setCollapsed((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      writeCollapsedGroups(next)
      return next
    })
  }

  const handleLogout = () => {
    logout()
      .catch(() => {
        // Even if logout request fails, navigate to login page.
      })
      .finally(() => {
        setIsAuthenticated(false)
        setUser(null)
        navigate('/login')
      })
  }

  const handleLogin = useCallback(() => {
    navigate(boardSlug ? `/login?r=${encodeURIComponent(accountPath(`/${boardSlug}`))}` : '/login')
  }, [boardSlug, navigate])

  // ── Render ──────────────────────────────────────────────────────────────────

  const boardName = meta?.board.name
  const brandLogoUrl = meta ? boardLogoSrc(boardSlug, meta.board) : undefined

  // Called unconditionally (before the loading/error early returns below) —
  // Rules of Hooks. Falls back to disabled/empty values until the board is loaded.
  const loadedBoard = meta?.board ?? null
  const introTranslationEnabled =
    translationAvailable && isAuthenticated && (loadedBoard?.translation_enabled ?? false)
  const introTranslation = useBoardIntroTranslation(
    loadedBoard?.slug ?? '',
    loadedBoard?.intro ?? '',
    language,
    introTranslationEnabled,
  )

  // Phones clamp the intro to two lines; "Show more" appears only when text is cut off.
  const introRef = useRef<HTMLParagraphElement | null>(null)
  const [introExpanded, setIntroExpanded] = useState(false)
  const [introClamped, setIntroClamped] = useState(false)
  const introText = introTranslation.displayIntro
  // biome-ignore lint/correctness/useExhaustiveDependencies: re-measure whenever the shown intro text changes
  useEffect(() => {
    const el = introRef.current
    if (el === null || introExpanded) return
    const measure = () => setIntroClamped(el.scrollHeight > el.clientHeight + 1)
    measure()
    if (typeof ResizeObserver === 'undefined') return
    const observer = new ResizeObserver(measure)
    observer.observe(el)
    return () => observer.disconnect()
  }, [introExpanded, introText, loadedBoard?.intro])

  // Phones keep the participation hint to one line; "more" appears only when it is cut off.
  // A ref callback (not an effect) measures whenever the hint mounts or is expanded, and the
  // observer re-measures on any size change, so no text dependencies are needed.
  const participationRef = useCallback(
    (el: HTMLParagraphElement | null) => {
      if (el === null || participationExpanded) return
      const measure = () => setParticipationClamped(el.scrollHeight > el.clientHeight + 1)
      measure()
      if (typeof ResizeObserver === 'undefined') return
      const observer = new ResizeObserver(measure)
      observer.observe(el)
      return () => observer.disconnect()
    },
    [participationExpanded],
  )

  const header = (
    <LocalizedHeader
      logoHref={accountPath(boardSlug ? `/${boardSlug}` : '/')}
      basePath={boardSlug ? accountPath(`/${boardSlug}`) : ''}
      boardSlug={boardSlug}
      boardHeaderLinks={meta?.board.branding_pro?.header_links}
      hideRoadmap={meta?.board.branding_pro?.roadmap_hidden}
      boardTabsInPage
      isAuthenticated={isAuthenticated}
      user={user}
      onLoginClick={handleLogin}
      onLogoutClick={handleLogout}
      scope={<ScopeLabel section={boardName} />}
      voterPreview={viewAsVoter}
      onVoterPreviewChange={setViewAsVoterPreview}
      brandLogoUrl={brandLogoUrl}
      brandWordmarkUrl={meta ? boardWordmarkSrc(boardSlug, meta.board) : undefined}
      brandWordmarkPlated={meta ? boardWordmarkPlated(meta.board) : false}
      brandLogoAlt={boardName}
      brandLogoPending={meta === null}
      brandInitialsName={meta ? boardInitialsName(meta.board) : undefined}
      brandAccent={meta?.board.primary_color ?? undefined}
    />
  )

  if (rootError === 'notFound') return <NotFoundState />

  if (rootError !== null) {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <ErrorState
          title={t('loadErrorTitle')}
          description={t('loadError')}
          action={
            <Button
              testId="board-state-retry"
              variant="secondary"
              onClick={() => setRetryKey((k) => k + 1)}
            >
              {tCommon('state.retry')}
            </Button>
          }
        />
      </PageShell>
    )
  }

  if (meta === null && fatalError === null) {
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        {/* Mirrors the loaded page — masthead, the control row, then the list. */}
        <div className="mb-8 flex flex-col gap-3" aria-hidden="true">
          <div className="h-3 w-16 rounded-vp-full bg-vp-rule" />
          <div className="h-11 w-72 max-w-full rounded-vp-md bg-vp-rule" />
        </div>
        <div className="vp-sheet overflow-hidden">
          <LoadingState label={t('loading')} rows={6} variant="ballot" />
        </div>
      </PageShell>
    )
  }

  if (meta === null) {
    // Unknown board (or a board of another account): the shared not-found page, same as any unknown route.
    if ((fatalError as ApiError).status === 404) return <NotFoundState />
    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        <ErrorState
          title={t('loadErrorTitle')}
          description={t('loadError')}
          action={
            <Button
              testId="board-state-retry"
              variant="secondary"
              onClick={() => setRetryKey((k) => k + 1)}
            >
              {tCommon('state.retry')}
            </Button>
          }
        />
      </PageShell>
    )
  }

  const board = meta.board
  const submitHref = accountPath(`/${board.slug}/submit`)

  // Deadlines (issue 04): the server computed the state with its own clock.
  // Owner/admin may still seed ideas into a closed submission window.
  const votingReasonKey = blockedReasonKey(board.phases, 'voting')
  const votingPhaseReason = votingReasonKey !== null ? tCommon(votingReasonKey) : null
  const submissionsReasonKey = blockedReasonKey(board.phases, 'submissions')
  const viewerRole = user !== null ? accountRoleFor(user, getAccountSlug()) : null
  const submissionsExempt = viewerRole === 'owner' || viewerRole === 'admin'
  const submissionsLocked = submissionsReasonKey !== null && !submissionsExempt

  const listedTotal = specs.reduce((sum, spec) => sum + (sections[spec.id]?.total ?? 0), 0)
  const allSectionsDone = specs.every((spec) => sections[spec.id]?.phase === 'done')
  const anyFilter = query !== '' || status !== null || statusKey !== null || tagId !== null
  const sortLabels = {
    top: tCommon('sort.top'),
    newest: tCommon('sort.newest'),
    controversial: tCommon('sort.controversial'),
  }
  const translationEnabled = translationAvailable && isAuthenticated && board.translation_enabled

  const tagItems: DsMenuItem[] = [
    {
      kind: 'action',
      label: t('allTags'),
      checked: tagId === null,
      onSelect: () => setTagId(null),
    },
    ...(meta.tags ?? []).map((tag) => ({
      kind: 'action' as const,
      label: `${tag.name} (${tag.count})`,
      checked: tagId === tag.id,
      onSelect: () => setTagId(tag.id),
    })),
  ]
  const selectedTagName = meta.tags?.find((tag) => tag.id === tagId)?.name

  const milestoneCopy =
    milestone !== null &&
    {
      'first-idea': {
        emoji: '🌱',
        title: t('milestoneFirstIdeaTitle'),
        body: t('milestoneFirstIdeaBody'),
      },
      'first-external': {
        emoji: '🤝',
        title: t('milestoneFirstExternalTitle'),
        body: t('milestoneFirstExternalBody'),
      },
      'ten-ideas': {
        emoji: '🚀',
        title: t('milestoneTenIdeasTitle'),
        body: t('milestoneTenIdeasBody'),
      },
    }[milestone.kind]

  const pinnedIdeas = allIdeas.filter((idea) => idea.is_pinned).slice(0, 3)
  const pinnedIds = new Set(pinnedIdeas.map((idea) => idea.id))

  const tagMenuEl =
    (meta.tags ?? []).length > 0 ? (
      <DsMenu
        testId="board-tag-filter"
        label={
          selectedTagName ? `${t('tagsFilterLabel')}: ${selectedTagName}` : t('tagsFilterLabel')
        }
        ariaLabel={t('tagsFilterLabel')}
        items={tagItems}
      />
    ) : null

  const railItems = [
    {
      id: 'all',
      label: tCommon('status.all'),
      count: meta.stats.total_ideas,
      active: status === null && statusKey === null,
      onSelect: () => handleStatusChange(null),
    },
    ...GROUP_ORDER.map((category) => ({
      id: category,
      label: statusLabel(board.statuses, category, category, language, statusDefaults),
      count: board.statuses?.find((s) => s.key === category)?.idea_count ?? null,
      active: status === toComponentStatus(category) && statusKey === null,
      onSelect: () => handleStatusChange(toComponentStatus(category)),
    })),
    ...(board.statuses ?? [])
      .filter((s) => !s.is_system && (!s.is_hidden || (s.idea_count ?? 0) > 0))
      .map((s) => ({
        id: s.key,
        label: statusLabel(board.statuses, s.key, s.category, language, statusDefaults),
        count: s.idea_count,
        active: statusKey === s.key,
        onSelect: () => handleStatusKeyChange(s.key),
      })),
  ]

  const boardUrl =
    typeof window === 'undefined' ? '' : `${window.location.origin}${accountPath(`/${boardSlug}`)}`
  const handleShareResult = (outcome: ShareOutcome) => {
    if (outcome === 'copied') setShareNotice('copied')
    if (outcome === 'failed') setShareNotice('failed')
  }
  // Status bubbles reuse the rail's items, so the same filter handlers and URL params apply.
  const bubbleTone = (id: string): StatusTone => {
    if (id === 'all') return 'neutral'
    const category = board.statuses?.find((s) => s.key === id)?.category ?? id
    const status = toComponentStatus(category)
    return statusChipFor(status, dsLabels.statusLabels[status]).tone
  }
  const bubbleItems = railItems.map((item) => ({
    key: item.id,
    label: item.label,
    count: item.count,
    tone: bubbleTone(item.id),
    pressed: item.active,
    onSelect: item.onSelect,
    testId: `board-bubble-${item.id}`,
  }))

  const renderRows = (ideas: Idea[]) => {
    // Under "Top" sort, re-rank by the current (optimistic or polled) score; pinned first.
    const ordered =
      sort === 'top'
        ? [...ideas].sort((a, b) => {
            if (a.is_pinned !== b.is_pinned) return a.is_pinned ? -1 : 1
            return getVote(b).score - getVote(a).score
          })
        : ideas
    return ordered.map((idea) => (
      <div key={idea.id} role="listitem" ref={registerRow(idea.id)}>
        <BoardIdeaRow
          idea={idea}
          boardSlug={board.slug}
          profileCache={profileCache}
          currentUserId={user?.id ?? null}
          vote={getVote(idea)}
          onVoteUp={() => castVote(idea, 'up')}
          onVoteDown={() => castVote(idea, 'down')}
          translationEnabled={translationEnabled}
          language={language}
          variant={isPhone ? 'phone' : 'desktop'}
          statuses={board.statuses}
          brandingPro={board.branding_pro}
          votingPhaseReason={votingPhaseReason}
        />
        {voteReasonsEnabled && voteReasonFollowUp.state.ideaId === idea.id && (
          <div className="px-4 pb-4">
            <VoteReasonFollowUp
              testId={`board-reason-${idea.id}`}
              followUp={voteReasonFollowUp}
              returnFocusTestId={`board-idea-${idea.id}-vote-down`}
            />
          </div>
        )}
      </div>
    ))
  }

  return (
    <div style={brandingStyle(board, resolvedTheme)} {...brandingProAttributes(board.branding_pro)}>
      <BoardThemeMode mode={board.theme_mode} boardSlug={board.slug} />
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        {milestoneCopy && (
          <Celebration
            effect="sparkle"
            tone="info"
            emoji={milestoneCopy.emoji}
            title={milestoneCopy.title}
            className="mb-6"
          >
            {milestoneCopy.body}
          </Celebration>
        )}
        {/* Masthead — set directly on the paper ground like the landing's hero:
          eyebrow, the board name at display weight, the intro as a lead, and
          the one ink CTA. No card around it; the list below is the object. */}
        <header className="mb-4 md:mb-10 animate-vp-rise" data-testid="board-profile">
          {/* Cover band (G-12): uploaded cover, or our own shapes drawn from the board accent and id. */}
          <BoardCoverBand
            slug={board.slug}
            seed={board.id}
            accent={board.primary_color}
            coverPattern={board.cover_pattern}
            assets={board.assets}
          />
          <div className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between sm:gap-8">
            <div className="min-w-0 flex-1">
              <div className="ds-board-profile">
                <div className="ds-board-profile__avatar relative -top-6 md:-top-10">
                  <Avatar
                    size="large"
                    name={board.name}
                    color={board.primary_color}
                    src={boardLogoSrc(boardSlug, board) ?? null}
                  />
                </div>
                <div className="min-w-0">
                  <div className="vp-eyebrow mb-3 max-sm:hidden">{tCommon('header.board')}</div>
                  {board.primary_color && (
                    <div
                      aria-hidden="true"
                      className="mb-3 h-1.5 w-12 rounded-vp-full bg-vp-accent max-sm:hidden"
                    />
                  )}
                  <h1 className="font-archivo font-extrabold text-vp-3xl md:text-vp-display leading-vp-display tracking-vp-display text-vp-ink text-balance">
                    {board.name}
                  </h1>
                  {/* Real numbers only: ideas in total and votes cast this week. */}
                  <p className="ds-board-stats" data-testid="board-stats">
                    <span data-testid="board-stat-ideas">
                      {statIdeaLabel(
                        board.branding_pro?.wording,
                        language,
                        meta.stats.total_ideas,
                        t,
                      )}
                    </span>
                    <span data-testid="board-stat-votes">
                      {t(
                        meta.stats.weekly_votes === 1
                          ? 'statVotesWeekSingular'
                          : 'statVotesWeekPlural',
                        { count: meta.stats.weekly_votes },
                      )}
                    </span>
                  </p>
                </div>
                <div className="ds-board-profile__share sm:hidden">
                  <ShareButton
                    url={boardUrl}
                    title={board.name}
                    label={t('shareBoard')}
                    iconOnly
                    onResult={handleShareResult}
                    testId="board-share-icon"
                  />
                </div>
              </div>
              {/* The intro is tenant-authored plaintext — rendered through .vp-prose
                (pre-wrap, break-anywhere), never as markup. */}
              {board.intro && (
                <>
                  <p
                    ref={introRef}
                    id="board-intro"
                    className={cx(
                      'vp-prose mt-4 max-w-vp-measure text-vp-lg md:text-vp-xl leading-7 md:leading-8 text-vp-text-secondary text-pretty',
                      !introExpanded && 'max-sm:line-clamp-2',
                    )}
                  >
                    {introTranslation.isTranslated && !introTranslation.showOriginal
                      ? introTranslation.displayIntro
                      : board.intro}
                  </p>
                  {(introClamped || introExpanded) && (
                    <button
                      type="button"
                      className="min-h-11 self-start text-vp-sm font-semibold text-vp-ink underline underline-offset-4 hover:no-underline sm:hidden"
                      aria-expanded={introExpanded}
                      aria-controls="board-intro"
                      onClick={() => setIntroExpanded((v) => !v)}
                      data-testid="board-intro-toggle"
                    >
                      {introExpanded ? t('introShowLess') : t('introShowMore')}
                    </button>
                  )}
                  {introTranslation.isTranslated && (
                    <p className="flex items-center gap-1 text-vp-xs text-vp-text-muted">
                      <span>{t('machineTranslatedLabel')}</span>
                      <span aria-hidden="true">·</span>
                      <button
                        type="button"
                        className="underline hover:no-underline"
                        onClick={() =>
                          introTranslation.setShowOriginal(!introTranslation.showOriginal)
                        }
                      >
                        {introTranslation.showOriginal
                          ? t('showTranslationLink')
                          : t('showOriginalLink')}
                      </button>
                    </p>
                  )}
                  {introTranslation.translationFailed && (
                    <p className="mt-1 text-vp-xs text-vp-text-muted">
                      {t('translationUnavailableHint')}
                    </p>
                  )}
                </>
              )}
              {/* Anonymous voting and guest submission (ADR 0006) are per-board
                opt-ins — say up front what works without an account, so owners
                don't have to spell it out in their intro. */}
              <p className="vp-eyebrow mt-4 max-sm:hidden">{t('whatHappensNext')}</p>
              <BoardPhaseLine id="board-phase-line" phases={board.phases} className="mt-4" />
              {submissionsLocked === false && submissionsReasonKey !== null && (
                <p className="mt-1 max-w-vp-measure text-vp-xs text-vp-text-muted text-pretty">
                  {tCommon('phase.adminExempt')}
                </p>
              )}
              {(anonymousVotingEnabled || board.anonymous_submission_available === true) &&
                !isAuthenticated && (
                  <>
                    <p
                      ref={participationRef}
                      id="board-participation-hint"
                      className={cx(
                        'mt-3 max-w-vp-measure text-vp-sm text-vp-text-muted text-pretty',
                        !participationExpanded && 'max-sm:line-clamp-1',
                      )}
                    >
                      {anonymousVotingEnabled && board.anonymous_submission_available === true
                        ? t('participationHintAnonymousBoth')
                        : board.anonymous_submission_available === true
                          ? t('participationHintGuestSubmission')
                          : t('participationHintAnonymousVoting')}
                    </p>
                    {(participationClamped || participationExpanded) && (
                      <button
                        type="button"
                        className="min-h-11 self-start text-vp-sm font-semibold text-vp-ink underline underline-offset-4 hover:no-underline sm:hidden"
                        aria-expanded={participationExpanded}
                        aria-controls="board-participation-hint"
                        onClick={() => setParticipationExpanded((v) => !v)}
                        data-testid="board-participation-toggle"
                      >
                        {participationExpanded ? t('introShowLess') : t('introShowMore')}
                      </button>
                    )}
                  </>
                )}
            </div>
            {submissionsLocked && submissionsReasonKey !== null ? (
              <Button
                testId="board-new-idea"
                variant="primary"
                size="large"
                disabled
                className="w-full sm:w-auto shrink-0"
                title={tCommon(submissionsReasonKey)}
                aria-describedby="board-phase-line"
              >
                <Plus size={16} strokeWidth={2} aria-hidden="true" />
                {t('newIdea')}
              </Button>
            ) : (
              // On phones the tab bar's "New idea" button opens the same form
              // (NewIdeaSheet), so the masthead CTA is left out there. A locked
              // board keeps the disabled button above as the explanation.
              !isPhone && (
                <ButtonLink
                  href={submitHref}
                  variant="accent"
                  size="large"
                  className="w-full sm:w-auto shrink-0"
                  linkAs={RouterLink}
                  testId="board-new-idea"
                >
                  <Plus size={16} strokeWidth={2} aria-hidden="true" />
                  {t('shareIdea')}
                </ButtonLink>
              )
            )}
            <div className="max-sm:hidden">
              <ShareButton
                url={boardUrl}
                title={board.name}
                label={t('shareBoard')}
                onResult={handleShareResult}
                testId="board-share"
              />
            </div>
          </div>
          {!isPhone && (
            <BoardProfileTabs
              basePath={boardSlug ? accountPath(`/${boardSlug}`) : ''}
              current="ideas"
              showRoadmap={boardRoadmapVisible(
                user,
                viewAsVoter,
                meta.board.branding_pro?.roadmap_hidden,
              )}
            />
          )}
        </header>

        <div className="xl:grid xl:grid-cols-[14rem_minmax(0,1fr)] xl:gap-8">
          <aside
            className="hidden xl:block"
            data-testid="board-rail"
            aria-label={tCommon('status.filterAriaLabel')}
          >
            <div className="flex flex-col gap-1">
              {railItems.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  aria-pressed={item.active}
                  onClick={item.onSelect}
                  data-testid={`board-rail-status-${item.id}`}
                  className={cx(
                    'flex w-full items-center justify-between gap-2 rounded-vp-md px-3 py-2 text-left text-vp-sm',
                    item.active ? 'bg-vp-ink text-vp-on-ink' : 'text-vp-ink hover:bg-vp-rule',
                    'pointer-coarse:min-h-11',
                  )}
                >
                  <span>{item.label}</span>
                  {item.count !== null && <span className="tabular-nums">{item.count}</span>}
                </button>
              ))}
            </div>
            {(meta.tags ?? []).length > 0 && (
              <div className="mt-6 flex flex-col gap-1">
                <h2 className="vp-eyebrow mb-1">{t('tagsFilterLabel')}</h2>
                {(meta.tags ?? []).map((tag) => (
                  <button
                    key={tag.id}
                    type="button"
                    aria-pressed={tagId === tag.id}
                    onClick={() => setTagId(tagId === tag.id ? null : tag.id)}
                    data-testid={`board-rail-tag-${tag.id}`}
                    className={cx(
                      'flex w-full items-center justify-between gap-2 rounded-vp-md px-3 py-2 text-left text-vp-sm',
                      tagId === tag.id
                        ? 'bg-vp-ink text-vp-on-ink'
                        : 'text-vp-ink hover:bg-vp-rule',
                      'pointer-coarse:min-h-11',
                    )}
                  >
                    <span>{tag.name}</span>
                    <span className="tabular-nums">{tag.count}</span>
                  </button>
                ))}
              </div>
            )}
            <section
              className="mt-8 border-t border-vp-rule pt-4"
              data-testid="board-week-stats"
              aria-labelledby="board-week-heading"
            >
              <h2 id="board-week-heading" className="vp-eyebrow mb-3">
                {tCommon('idea.thisWeekLabel')}
              </h2>
              <dl className="flex flex-col gap-3">
                <div>
                  <dt className="text-vp-xs text-vp-text-muted">
                    {tCommon('idea.votesGivenLabel')}
                  </dt>
                  <dd className="font-archivo text-vp-xl font-bold tabular-nums">
                    +{meta?.stats?.weekly_votes ?? 0}
                  </dd>
                </div>
                <div>
                  <dt className="text-vp-xs text-vp-text-muted">{tCommon('idea.newIdeasLabel')}</dt>
                  <dd className="font-archivo text-vp-xl font-bold tabular-nums">
                    {meta?.stats?.weekly_new_ideas ?? 0}
                  </dd>
                </div>
                <div>
                  <dt className="text-vp-xs text-vp-text-muted">{t('weekApprovalLabel')}</dt>
                  <dd className="font-archivo text-vp-xl font-bold tabular-nums">
                    {meta?.stats?.avg_consensus ?? 0}%
                  </dd>
                </div>
              </dl>
            </section>
          </aside>
          <div className="min-w-0">
            {/* Toolbar (G-09): search, tag menu, status and sort. */}
            {/* Phones: search and the filter button share one row; sort lives in the
          filter sheet and shows as a chip once it differs from the default. */}
            <div role="search" className="mb-3 flex items-center gap-2 sm:gap-3">
              <SearchField
                testId="board-search"
                label={t('searchLabel')}
                hideLabel
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                placeholder={t('searchPlaceholder')}
                maxLength={100}
                className="w-full sm:max-w-sm max-sm:min-w-0 max-sm:flex-1"
              />
              {isPhone && (
                <button
                  type="button"
                  className="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-vp-full border border-vp-rule px-4 text-vp-sm text-vp-ink"
                  onClick={() => setFiltersOpen(true)}
                  data-testid="board-filters-open"
                >
                  <SlidersHorizontal size={16} aria-hidden="true" />
                  {t('filtersButton')}
                </button>
              )}
              {!isPhone && <div className="xl:hidden">{tagMenuEl}</div>}
            </div>

            {isPhone && (
              <StatusBubbles
                items={bubbleItems}
                label={tCommon('status.filterAriaLabel')}
                className="mb-3"
                testId="board-status-bubbles"
              />
            )}

            {/* Board top — status chips left, sort right — sits directly on the
          sheet's head rule, the way the landing's ExampleBoard lays it out.
          Phones carry status and sort in the filter sheet instead. */}
            {!isPhone && (
              <div className="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                {/* Below 1280 px the status chips stay here; from 1280 px the left rail carries them. */}
                <div className="xl:hidden min-w-0">
                  <StatusBubbles
                    items={bubbleItems}
                    label={tCommon('status.filterAriaLabel')}
                    testId="board-status-bubbles"
                  />
                </div>
                {/* Sort keeps its natural width at 768-1279 px, so its labels never clip.
                  Below 1024 px and on touch screens its tabs are 44 px targets. */}
                <div className="shrink-0">
                  <SortTabs
                    value={sort}
                    onChange={handleSortChange}
                    ariaLabel={tCommon('sort.ariaLabel')}
                    labels={sortLabels}
                    tone="accent"
                    className="!rounded-full !overflow-hidden max-lg:[&_button]:min-h-11 pointer-coarse:[&_button]:min-h-11"
                  />
                </div>
              </div>
            )}

            {(anyFilter || (isPhone && sort !== 'top')) && (
              <div className="mb-3 flex flex-wrap items-center gap-2">
                {isPhone && sort !== 'top' && (
                  <button
                    type="button"
                    className="inline-flex min-h-11 items-center gap-1 rounded-vp-full bg-vp-ink px-4 text-vp-sm text-vp-on-ink"
                    onClick={() => setFiltersOpen(true)}
                    data-testid="board-sort-chip"
                  >
                    {`${tCommon('sort.ariaLabel')}: ${sortLabels[sort]}`}
                  </button>
                )}
                {anyFilter && (
                  <Button
                    variant="secondary"
                    onClick={handleClearAllFilters}
                    testId="board-clear-filters"
                  >
                    {t('clearAllFilters')}
                  </Button>
                )}
              </div>
            )}

            {pinnedIdeas.length > 0 && (
              <section
                className="mb-3"
                data-testid="board-pinned"
                aria-labelledby="board-pinned-heading"
              >
                <h2 id="board-pinned-heading" className="vp-eyebrow mb-2">
                  {t('pinnedHeading')}
                </h2>
                <div className="vp-sheet overflow-hidden ds-idea-rows" role="list">
                  {renderRows(pinnedIdeas)}
                </div>
              </section>
            )}

            {/* The list (G-09): one ruled sheet of status groups; a flat list when a
          single status or custom status is filtered. */}
            <div className="vp-sheet overflow-hidden" data-testid="board-list">
              {!allSectionsDone && listedTotal === 0 && (
                <LoadingState label={t('loading')} rows={6} variant="ballot" />
              )}

              {allSectionsDone && listedTotal === 0 ? (
                query !== '' ? (
                  <EmptyState
                    kind="no-results"
                    testId="board-empty-search"
                    title={t('noSearchResultsTitle')}
                    description={t('noSearchResultsDescription', { query })}
                    action={
                      <Button
                        variant="secondary"
                        onClick={handleClearSearch}
                        testId="board-search-reset"
                      >
                        {t('resetSearch')}
                      </Button>
                    }
                  />
                ) : anyFilter ? (
                  <EmptyState
                    kind="no-results"
                    testId="board-empty-status"
                    title={t('noIdeasForStatusTitle')}
                    description={t('noIdeasForStatusDescription')}
                    action={
                      <Button
                        variant="secondary"
                        onClick={handleClearAllFilters}
                        testId="board-empty-clear-filters"
                      >
                        {t('clearAllFilters')}
                      </Button>
                    }
                  />
                ) : (
                  <EmptyState
                    kind="no-ideas"
                    testId="board-empty-ideas"
                    title={t('noIdeasTitle')}
                    description={
                      wordingOverride(board.branding_pro?.wording, language, 'empty_state') ??
                      t('noIdeasDescription')
                    }
                    action={
                      submissionsLocked ? undefined : (
                        <ButtonLink
                          href={submitHref}
                          variant="primary"
                          linkAs={RouterLink}
                          testId="board-submit-first-idea"
                        >
                          {t('submitFirstIdea')}
                        </ButtonLink>
                      )
                    }
                  />
                )
              ) : (
                specs.map((spec) => {
                  const section = sections[spec.id]
                  // Done, Declined and Archived are the collapsible sections under the active list.
                  const category = grouped && spec.id !== 'active' ? (spec.id as IdeaStatus) : null
                  const title =
                    category !== null
                      ? statusLabel(board.statuses, category, category, language, statusDefaults)
                      : undefined
                  const isCollapsed = category !== null && collapsed.has(category)
                  if (section === undefined) return null
                  if (section.phase === 'error') {
                    return (
                      <div
                        key={spec.id}
                        className="flex flex-wrap items-center justify-between gap-3 py-3"
                      >
                        <p className="text-vp-sm text-vp-text-muted">{t('loadError')}</p>
                        <Button
                          variant="secondary"
                          onClick={() => setRetryKey((k) => k + 1)}
                          testId="board-section-retry"
                        >
                          {tCommon('state.retry')}
                        </Button>
                      </div>
                    )
                  }
                  if (grouped && section.total === 0 && section.phase === 'done') {
                    return null
                  }
                  return (
                    <BoardSection
                      key={spec.id}
                      id={spec.id}
                      title={title}
                      total={section.total}
                      loaded={section.ideas.length}
                      collapsed={isCollapsed}
                      onToggle={() => category !== null && handleToggleSection(category)}
                      loadingMore={section.loadingMore}
                      onLoadMore={() => loadMore(spec.id)}
                      loadMoreLabel={(remaining) =>
                        `${t('loadMore')} · ${t('loadMoreRemaining', { count: remaining })}`
                      }
                      loadingLabel={t('loading')}
                    >
                      {section.phase === 'loading' && section.ideas.length === 0 ? (
                        <div aria-hidden="true" className="flex flex-col gap-2 px-3 py-2 sm:px-4">
                          <div className="h-12 rounded-vp-md bg-vp-rule" />
                          <div className="h-12 rounded-vp-md bg-vp-rule" />
                        </div>
                      ) : (
                        <div role="list" aria-label={title ?? t('ideasAriaLabel')}>
                          {renderRows(section.ideas.filter((idea) => !pinnedIds.has(idea.id)))}
                        </div>
                      )}
                    </BoardSection>
                  )
                })
              )}
            </div>
          </div>
        </div>

        {/* Footer extras (note, back link, operator line, Powered by) — shared with the
          idea page via BoardBrandFooter so every board page carries the same footer. */}
        <BoardBrandFooter
          slug={board.slug}
          legalOperatorName={board.legal_operator_name}
          visual={board}
        />

        <Sheet
          open={filtersOpen}
          onClose={() => setFiltersOpen(false)}
          title={t('filtersButton')}
          closeLabel={tCommon('toast.closeAriaLabel')}
          testId="board-filters-dialog"
        >
          {/* Touch targets in the sheet: every chip and tag button is at least 44 px tall. */}
          <div className="flex flex-col gap-4 [&_button]:min-h-11">
            {tagMenuEl}
            {isPhone && (
              <div className="flex flex-col gap-1">
                <h2 className="vp-eyebrow mb-1">{tCommon('sort.ariaLabel')}</h2>
                <SortTabs
                  value={sort}
                  onChange={handleSortChange}
                  ariaLabel={tCommon('sort.ariaLabel')}
                  labels={sortLabels}
                  tone="accent"
                  className="!rounded-full !overflow-hidden"
                />
              </div>
            )}
          </div>
        </Sheet>

        {shareNotice !== null && (
          <Toast
            type={shareNotice === 'copied' ? 'success' : 'error'}
            message={t(shareNotice === 'copied' ? 'shareCopied' : 'shareFailed')}
            onClose={() => setShareNotice(null)}
            closeAriaLabel={tCommon('toast.closeAriaLabel')}
            testId="board-share-toast"
          />
        )}
        {voteFailed && (
          <Toast
            type="error"
            message={tCommon('vote.failed')}
            onClose={() => setVoteFailed(false)}
            closeAriaLabel={tCommon('toast.closeAriaLabel')}
            testId="board-vote-failed-toast"
          />
        )}
      </PageShell>
    </div>
  )
}
