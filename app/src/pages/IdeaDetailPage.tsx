import {
  BackLink,
  Button,
  ButtonLink,
  ConfirmDialog,
  EmptyState,
  ErrorState,
  InlineNotice,
  LoadingState,
  PageShell,
  Section,
  Select,
  Skeleton,
  StatusChip,
  statusChipFor,
  TextArea,
  TextField,
  Toast,
  VoteControl,
  VoteSummary,
} from '@votepit/ui/ds'

import { Flag, MessageSquare, Pencil, ShieldCheck } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { AuthorBadge } from '../components/AuthorBadge'
import { BoardBrandFooter } from '../components/BoardBrandFooter'
import { BoardPhaseLine } from '../components/BoardPhaseLine'
import { BoardThemeMode } from '../components/BoardThemeMode'
import { CommentReactionBar } from '../components/CommentReactionBar'
import { ContentModerationModal } from '../components/ContentModerationModal'
import { DecisionBlock } from '../components/DecisionBlock'
import { IdeaFollowToggle } from '../components/IdeaFollowToggle'
import { IdeaIssueLinksPanel } from '../components/IdeaIssueLinksPanel'
import { LocalizedHeader, ScopeLabel, useIsPhone } from '../components/LocalizedHeader'
import { MarkdownLite } from '../components/MarkdownLite'
import { MarkdownToolbar } from '../components/MarkdownToolbar'
import { MergeIdeaDialog } from '../components/MergeIdeaDialog'
import { NotFoundState } from '../components/NotFoundState'
import { ReportContentModal } from '../components/ReportContentModal'
import { RouterButtonLink } from '../components/RouterButtonLink'
import { StatusDecisionForm } from '../components/StatusDecisionForm'
import { TagBadges } from '../components/TagBadges'
import { TagManager } from '../components/TagManager'
import { TurnstileChallengeModal } from '../components/TurnstileChallengeModal'
import { VoteReasonFollowUp } from '../components/VoteReasonFollowUp'
import { VoteReasonOperatorSection } from '../components/VoteReasonOperatorSection'
import { useIdeaCardLabels } from '../hooks/useIdeaCardLabels'
import { useCommentTranslations, useIdeaTranslation } from '../hooks/useIdeaTranslation'
import { createPublicProfileCache } from '../hooks/usePublicProfile'
import { useTurnstileChallenge } from '../hooks/useTurnstileChallenge'
import { useVote } from '../hooks/useVote'
import { useVoteReasonFollowUp } from '../hooks/useVoteReasonFollowUp'
import { useVoterPreview } from '../hooks/useVoterPreview'
import { accountPath, getAccountSlug } from '../lib/accountContext'
import type {
  AccountRole,
  ApiError,
  BlockScope,
  Comment,
  IdeaDetailResponse,
  IdeaStatus,
  ModerationReasonCode,
  StatusDecision,
  User,
} from '../lib/api'
import {
  accountRoleFor,
  blockUser,
  bootstrap,
  createComment,
  deleteComment,
  getAccountProfile,
  getIdea,
  logout,
  moderateIdea,
  setIdeaPinned,
  setIdeaStatus,
  unmergeIdea,
  updateAnonVoteNotifyEmail,
  updateComment,
  withdrawIdea,
} from '../lib/api'
import type { StatusDecisionInput } from '../lib/api/ideas'
import {
  boardInitialsName,
  boardLogoSrc,
  boardWordmarkPlated,
  boardWordmarkSrc,
} from '../lib/boardLogo'
import { blockedReasonKey } from '../lib/boardPhases'
import {
  defaultAllowedNext,
  findStatus,
  statusAllows,
  statusLabel,
  toComponentStatus,
  useStatusDefaultLabels,
} from '../lib/boardStatuses'
import { brandingProAttributes, brandingStyle } from '../lib/branding'
import { getFeatures, legalLinksFor } from '../lib/features'
import { formatDateTime, intlLocale } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'
import { useResolvedTheme } from '../lib/theme'
import { useIsOnline } from '../lib/useIsOnline'

/** UI-only mirror of CommentUpdateAction::EDIT_WINDOW_SECONDS — the server enforces the real deadline. */
const COMMENT_EDIT_WINDOW_SECONDS = 60

/** UI-only mirror of IdeaEditAction::EDIT_WINDOW_SECONDS — the server enforces the real deadline. */
const IDEA_EDIT_WINDOW_SECONDS = 7200

type LoadState =
  | { phase: 'loading' }
  | { phase: 'error'; notFound: boolean; message: string }
  | { phase: 'done'; data: IdeaDetailResponse }

// ── Tally marks (for / against counts next to the ballot) ─────────────────────

function TallyMark({ kind }: { kind: 'up' | 'down' }) {
  return (
    <svg
      viewBox="0 0 16 16"
      width="12"
      height="12"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.25"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {kind === 'up' ? <path d="M3 8.5l3.2 3.2L13 5" /> : <path d="M4 4l8 8M12 4l-8 8" />}
    </svg>
  )
}

function EyeGlyph() {
  return (
    <svg
      viewBox="0 0 16 16"
      width="13"
      height="13"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M1 8s2.7-4.5 7-4.5S15 8 15 8s-2.7 4.5-7 4.5S1 8 1 8Z" />
      <circle cx="8" cy="8" r="2" />
    </svg>
  )
}

// ── IdeaDetailContent ─────────────────────────────────────────────────────────
// Extracted so useVote can be called at the top level (no conditional hook call).

interface IdeaDetailContentProps {
  data: IdeaDetailResponse
  boardSlug: string
  currentUserId: number | null
  /**
   * The current user's own avatar, or null. Other authors' avatars are never
   * passed in here — AuthorBadge fetches each one's public profile itself and
   * shows it only if that user opted in (profile-visibility feature).
   */
  myAvatarUrl: string | null
  /** The current user's role in this account — for the "You" badge. */
  currentUserRole: AccountRole | null
  isAdmin: boolean
  user: User | null
  onLogout: () => void
  /** See BoardPage's "view as voter" toggle — shared across the board. */
  voterPreview: boolean
  onVoterPreviewChange: (checked: boolean) => void
  /**
   * bootstrap()'s translation_available flag (idea-comment-translation,
   * ticket 07/08) — auto-translate is only attempted for a logged-in voter
   * when this is true. False means no provider is configured; the idea and
   * its comments simply render as originals, no request ever fires.
   */
  translationAvailable: boolean
  /** Re-fetches the idea (after an unmerge changes what it shows). */
  onReload: () => void
}

function IdeaDetailContent({
  data,
  boardSlug,
  currentUserId,
  myAvatarUrl,
  currentUserRole,
  isAdmin,
  user,
  onLogout,
  voterPreview,
  onVoterPreviewChange,
  translationAvailable,
  onReload,
}: IdeaDetailContentProps) {
  const resolvedTheme = useResolvedTheme()
  const { board, idea, is_authenticated } = data
  const navigate = useNavigate()
  const t = useT('ideaDetailPage')
  const isPhone = useIsPhone()
  const tCommon = useT('common')
  const { language } = useI18n()
  const STATUS_LABELS = useStatusDefaultLabels()
  const labelOf = (key: string) =>
    statusLabel(
      board.statuses,
      key,
      findStatus(board.statuses, key)?.category ?? (key as IdeaStatus),
      language,
      STATUS_LABELS,
    )

  // Ticket 07/08: auto-translate on load for a logged-in voter — no manual
  // click. Anonymous voters never get translated content (matches the rest
  // of the interaction surface, which is login-gated the same way).
  // board.translation_enabled: per-board opt-in follow-up, defaults to
  // false — see BoardPage's identical three-way gate.
  const translationEnabled = translationAvailable && is_authenticated && board.translation_enabled
  const ideaTranslation = useIdeaTranslation(
    board.slug,
    idea.id,
    idea.title,
    idea.body,
    language,
    translationEnabled,
  )

  // One public-profile lookup per distinct author on this page (the idea's
  // author plus every commenter), shared by all AuthorBadges below.
  const profileCache = useRef(createPublicProfileCache())

  // Comment list + compose box.
  const [comments, setComments] = useState<Comment[]>(data.comments)

  // Ticket 07/08: the same auto-translate action that translates the idea
  // also cascades to the ENTIRE comment thread in one bulk call, so
  // comments render already translated alongside the idea rather than as a
  // separate later step. A new/edited comment changes the id set and
  // re-triggers this (see useCommentTranslations' doc comment for why —
  // ticket 06 only ships a bulk endpoint, not a per-comment one).
  const commentTranslations = useCommentTranslations(
    board.slug,
    idea.id,
    comments.map((c) => c.id),
    language,
    translationEnabled,
  )
  const [commentsShowingOriginal, setCommentsShowingOriginal] = useState<Set<number>>(new Set())

  const [commentBody, setCommentBody] = useState('')
  const commentBodyRef = useRef<HTMLTextAreaElement>(null)
  // The worklist's "Answer" action links to #comment-body (P-06 part B): focus the composer on arrival.
  useEffect(() => {
    if (window.location.hash === '#comment-body') commentBodyRef.current?.focus()
  }, [])
  const [commentPending, setCommentPending] = useState(false)
  const [commentError, setCommentError] = useState<string | null>(null)
  const [deletingCommentId, setDeletingCommentId] = useState<number | null>(null)

  const [editingCommentId, setEditingCommentId] = useState<number | null>(null)
  const [editBody, setEditBody] = useState('')
  const editBodyRef = useRef<HTMLTextAreaElement>(null)
  const [editPending, setEditPending] = useState(false)
  const [editError, setEditError] = useState<string | null>(null)

  // Ticks every few seconds so the "edit" link disappears live once the
  // window closes, without a full-second-accurate timer (server enforces
  // the real deadline regardless — this is a UI hint only).
  const [nowTick, setNowTick] = useState(() => Date.now())
  useEffect(() => {
    const interval = setInterval(() => setNowTick(Date.now()), 5000)
    return () => clearInterval(interval)
  }, [])

  const [withdrawing, setWithdrawing] = useState(false)
  const [withdrawError, setWithdrawError] = useState<string | null>(null)
  const [showWithdrawConfirm, setShowWithdrawConfirm] = useState(false)

  // Moderator idea removal state (board-operator-compliance issue 07,
  // distinct from the author's own withdraw above).
  const [moderatingIdea, setModeratingIdea] = useState(false)
  const [showIdeaModerationModal, setShowIdeaModerationModal] = useState(false)

  // Admin status control state
  // Status KEY (owner-defined statuses: `s<base36>`, issue 03) + the keys
  // it may move to — both server-computed (allowed_next), never mirrored.
  const [currentStatus, setCurrentStatus] = useState<string>(idea.status_key ?? idea.status)
  const [allowedNext, setAllowedNext] = useState<string[]>(
    idea.allowed_next ?? defaultAllowedNext(idea.status),
  )
  const [statusPending, setStatusPending] = useState(false)
  const [statusError, setStatusError] = useState<string | null>(null)
  // Status picked in the moderation strip, waiting for the reason form (P-01).
  const [pendingStatus, setPendingStatus] = useState<string | null>(null)
  // Latest decision + history (P-01), updated locally after a successful change.
  const [decisionState, setDecisionState] = useState<{
    latest: StatusDecision | null
    history: StatusDecision[]
  }>({ latest: idea.decision ?? null, history: idea.decision_history ?? [] })

  // Admin pin control state
  const [isPinned, setIsPinned] = useState(idea.is_pinned)
  const [pinPending, setPinPending] = useState(false)
  const [pinError, setPinError] = useState<string | null>(null)

  // Tags — mutated in place via TagManager (moderators only); voters only
  // ever see the read-only idea.tags snapshot from the initial load. Falls
  // back to [] defensively (older cached responses / test fixtures may omit
  // the field).
  const [ideaTags, setIdeaTags] = useState(idea.tags ?? [])

  // A rejected vote rolls the mark back; the toast says why it snapped back.
  const [voteFailed, setVoteFailed] = useState(false)

  // ADR 0004 (UX follow-up): the optional notification-email prompt for an
  // anonymous voter is revealed only AFTER a vote is cast, and saved via
  // its own PATCH (updateAnonVoteNotifyEmail) — decoupled from the vote
  // request itself, never used for auth/dedup.
  const [anonVoteEmailInput, setAnonVoteEmailInput] = useState('')
  const [anonVoteEmailSaving, setAnonVoteEmailSaving] = useState(false)
  const [anonVoteEmailSaved, setAnonVoteEmailSaved] = useState(false)
  const [anonVoteEmailEditing, setAnonVoteEmailEditing] = useState(false)
  const [anonVoteEmailError, setAnonVoteEmailError] = useState<string | null>(null)

  // DSA Art. 16 notice-and-action: open to anyone, signed in or not.
  const [showReportModal, setShowReportModal] = useState(false)

  // Admin user-block control state (no persisted state to hydrate from,
  // starts unblocked and flips optimistically on action). Scope selector
  // picks account-wide vs. this-board-only.
  const [authorBlocked, setAuthorBlocked] = useState(false)
  const [blockScope, setBlockScope] = useState<BlockScope>('account')
  const [blockPending, setBlockPending] = useState(false)
  const [blockError, setBlockError] = useState<string | null>(null)
  // Only the block DIRECTION is confirmed (matches idea-withdraw's
  // destructive-action pattern) — unblocking is the corrective/undo action
  // and fires immediately, same as before.
  const [showBlockConfirm, setShowBlockConfirm] = useState(false)

  // Same optimistic pattern as the idea-author block above, but keyed by
  // comment author id (a Set, since several comments can share one author)
  // — always account-wide, since a per-comment scope selector next to
  // "Entfernen" would clutter the row for a rarely-used action.
  const [blockedCommentAuthors, setBlockedCommentAuthors] = useState<Set<number>>(new Set())
  const [commentBlockPendingAuthorId, setCommentBlockPendingAuthorId] = useState<number | null>(
    null,
  )
  const [commentBlockError, setCommentBlockError] = useState<string | null>(null)
  // Same confirm-only-on-block pattern as showBlockConfirm above.
  const [confirmBlockCommentAuthorId, setConfirmBlockCommentAuthorId] = useState<number | null>(
    null,
  )
  const [confirmDeleteCommentId, setConfirmDeleteCommentId] = useState<number | null>(null)

  const isOwner = currentUserId !== null && currentUserId === idea.author_id

  const handleStatusChange = async (to: string, decision?: StatusDecisionInput) => {
    if (statusPending) return
    const prev = currentStatus
    setCurrentStatus(to) // optimistic
    setStatusError(null)
    setStatusPending(true)
    try {
      const res = await setIdeaStatus(board.slug, idea.id, to, decision)
      setAllowedNext(res.allowed_next ?? defaultAllowedNext(to))
      // The server always records a decision row for a real change; mirror it locally.
      const entry: StatusDecision = {
        id: -Date.now(),
        status_from: prev,
        status_to: to,
        reason: decision?.reason?.trim() ? decision.reason.trim() : null,
        next_step: decision?.nextStep?.trim() ? decision.nextStep.trim() : null,
        responsible_role:
          decision?.responsibleRole === 'team' || decision?.responsibleRole === 'moderation'
            ? decision.responsibleRole
            : null,
        created_at: new Date().toISOString(),
      }
      setDecisionState((d) => ({ latest: entry, history: [entry, ...d.history] }))
      setPendingStatus(null)
    } catch (err) {
      setCurrentStatus(prev) // rollback on error
      const apiErr = err as ApiError
      setStatusError(apiErr?.payload?.message ?? t('statusUpdateError'))
    } finally {
      setStatusPending(false)
    }
  }

  // Issue 10: merge this idea into another one / undo a merge.
  const [showMergeDialog, setShowMergeDialog] = useState(false)
  const [unmergePendingId, setUnmergePendingId] = useState<number | null>(null)
  const [unmergeError, setUnmergeError] = useState<string | null>(null)
  const isMerged = idea.merged_into_id !== null && idea.merged_into_id !== undefined

  const handleUnmerge = async (sourceId: number) => {
    if (unmergePendingId !== null) return
    setUnmergePendingId(sourceId)
    setUnmergeError(null)
    try {
      await unmergeIdea(board.slug, sourceId)
      onReload()
    } catch {
      setUnmergeError(t('undoMergeFailed'))
      setUnmergePendingId(null)
    }
  }

  const handlePinToggle = async () => {
    if (pinPending) return
    const prev = isPinned
    const next = !prev
    setIsPinned(next) // optimistic
    setPinError(null)
    setPinPending(true)
    try {
      await setIdeaPinned(board.slug, idea.id, next)
    } catch (err) {
      setIsPinned(prev) // rollback on error
      const apiErr = err as ApiError
      setPinError(apiErr?.payload?.message ?? t('pinUpdateError'))
    } finally {
      setPinPending(false)
    }
  }

  const handleBlockToggle = async () => {
    if (blockPending) return
    const prev = authorBlocked
    const next = !prev
    setAuthorBlocked(next) // optimistic
    setBlockError(null)
    setBlockPending(true)
    try {
      await blockUser(board.slug, idea.author_id, next, blockScope)
    } catch (err) {
      setAuthorBlocked(prev) // rollback on error
      const apiErr = err as ApiError
      setBlockError(apiErr?.payload?.message ?? t('blockUpdateError'))
    } finally {
      setBlockPending(false)
    }
  }

  const handleCommentAuthorBlockToggle = async (authorId: number) => {
    if (commentBlockPendingAuthorId !== null) return
    const wasBlocked = blockedCommentAuthors.has(authorId)
    const next = !wasBlocked
    setBlockedCommentAuthors((prev) => {
      const updated = new Set(prev)
      if (next) updated.add(authorId)
      else updated.delete(authorId)
      return updated
    }) // optimistic
    setCommentBlockError(null)
    setCommentBlockPendingAuthorId(authorId)
    try {
      await blockUser(board.slug, authorId, next, 'account')
    } catch (err) {
      setBlockedCommentAuthors((prev) => {
        const updated = new Set(prev)
        if (wasBlocked) updated.add(authorId)
        else updated.delete(authorId)
        return updated
      }) // rollback on error
      const apiErr = err as ApiError
      setCommentBlockError(apiErr?.payload?.message ?? t('blockUpdateError'))
    } finally {
      setCommentBlockPendingAuthorId(null)
    }
  }

  const handleWithdraw = async () => {
    if (withdrawing) return
    setShowWithdrawConfirm(false)
    setWithdrawing(true)
    setWithdrawError(null)
    try {
      await withdrawIdea(board.slug, idea.id)
      navigate(accountPath(`/${board.slug}`))
    } catch (err) {
      const apiErr = err as ApiError
      setWithdrawError(apiErr?.payload?.message ?? t('withdrawError'))
      setWithdrawing(false)
    }
  }

  const handleCommentSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (commentPending) return
    const trimmed = commentBody.trim()
    if (trimmed === '') return

    setCommentPending(true)
    setCommentError(null)
    try {
      const res = await withCommentChallenge((turnstileToken) =>
        createComment(board.slug, idea.id, trimmed, turnstileToken),
      )
      setComments((prev) => [
        ...prev,
        {
          id: res.id,
          idea_id: idea.id,
          author_id: currentUserId ?? 0,
          body: trimmed,
          created_at: new Date().toISOString(),
          edited_at: null,
          reactions: { counts: {}, my_reaction: null },
        },
      ])
      setCommentBody('')
    } catch (err) {
      const apiErr = err as ApiError
      // board-operator-compliance issue 10: dedicated error key, unambiguous
      // "you cannot submit at all" message (see SubmitPage's identical
      // handling for idea creation).
      setCommentError(
        apiErr?.payload?.key === 'member_suspended'
          ? t('memberSuspendedError')
          : (apiErr?.payload?.message ?? t('commentPostError')),
      )
    } finally {
      setCommentPending(false)
    }
  }

  const handleCommentDelete = async (
    commentId: number,
    reasonCode: ModerationReasonCode,
    reasonNote: string,
  ) => {
    if (deletingCommentId !== null) return
    setDeletingCommentId(commentId)
    const prevComments = comments
    setComments((prev) => prev.filter((c) => c.id !== commentId)) // optimistic
    try {
      await deleteComment(
        board.slug,
        idea.id,
        commentId,
        reasonCode,
        reasonNote === '' ? undefined : reasonNote,
      )
      setConfirmDeleteCommentId(null)
    } catch (err) {
      setComments(prevComments) // rollback on error — ContentModerationModal shows the error inline
      const apiErr = err as ApiError
      throw new Error(apiErr?.payload?.message ?? t('commentDeleteError'))
    } finally {
      setDeletingCommentId(null)
    }
  }

  const handleIdeaModerate = async (reasonCode: ModerationReasonCode, reasonNote: string) => {
    if (moderatingIdea) return
    setModeratingIdea(true)
    try {
      await moderateIdea(
        board.slug,
        idea.id,
        reasonCode,
        reasonNote === '' ? undefined : reasonNote,
      )
      setShowIdeaModerationModal(false)
      navigate(accountPath(`/${board.slug}`))
    } catch (err) {
      const apiErr = err as ApiError
      throw new Error(apiErr?.payload?.message ?? t('ideaModerateError'))
    } finally {
      setModeratingIdea(false)
    }
  }

  function canEditComment(comment: Comment): boolean {
    if (comment.author_id !== currentUserId) return false
    const elapsedSeconds = (nowTick - new Date(comment.created_at).getTime()) / 1000
    return elapsedSeconds < COMMENT_EDIT_WINDOW_SECONDS
  }

  function canEditIdea(): boolean {
    const elapsedSeconds = (nowTick - new Date(idea.created_at).getTime()) / 1000
    return elapsedSeconds < IDEA_EDIT_WINDOW_SECONDS
  }

  function toggleCommentOriginal(commentId: number) {
    setCommentsShowingOriginal((prev) => {
      const updated = new Set(prev)
      if (updated.has(commentId)) updated.delete(commentId)
      else updated.add(commentId)
      return updated
    })
  }

  /** Ticket 08: per-comment translated/original/failed rendering. */
  function commentTranslationView(comment: Comment): {
    displayBody: string
    isTranslated: boolean
    failed: boolean
  } {
    const result = commentTranslations.results.get(comment.id)
    if (result === undefined) {
      // Not translated (disabled, still loading, or a comment posted after
      // the last cascade whose id isn't in the batch response yet) —
      // render as-is. commentTranslations.batchFailed covers the whole-batch
      // failure case explicitly below.
      return { displayBody: comment.body, isTranslated: false, failed: false }
    }
    if (!result.ok) {
      return { displayBody: comment.body, isTranslated: false, failed: true }
    }
    const isTranslated = result.detected_source_locale !== language
    const showingOriginal = commentsShowingOriginal.has(comment.id)
    return {
      displayBody: isTranslated && !showingOriginal ? result.translated_body : comment.body,
      isTranslated,
      failed: false,
    }
  }

  function startCommentEdit(comment: Comment) {
    setEditingCommentId(comment.id)
    setEditBody(comment.body)
    setEditError(null)
  }

  function cancelCommentEdit() {
    setEditingCommentId(null)
    setEditBody('')
    setEditError(null)
  }

  const handleCommentEditSubmit = async (e: React.FormEvent, commentId: number) => {
    e.preventDefault()
    if (editPending) return
    const trimmed = editBody.trim()
    if (trimmed === '') return

    setEditPending(true)
    setEditError(null)
    try {
      await updateComment(board.slug, idea.id, commentId, trimmed)
      setComments((prev) =>
        prev.map((c) =>
          c.id === commentId ? { ...c, body: trimmed, edited_at: new Date().toISOString() } : c,
        ),
      )
      setEditingCommentId(null)
      setEditBody('')
    } catch (err) {
      const apiErr = err as ApiError
      setEditError(apiErr?.payload?.message ?? t('commentEditError'))
    } finally {
      setEditPending(false)
    }
  }

  // P-03: optional reason after a vote against (per board opt-in, default off).
  const voteReasonFollowUp = useVoteReasonFollowUp(board.slug)
  const voteReasonsEnabled =
    board.vote_reasons_enabled === true && getFeatures().vote_reasons !== false
  const voteResult = useVote({
    boardSlug: board.slug,
    ideaId: idea.id,
    isAuthenticated: is_authenticated,
    initialScore: idea.score_cache,
    initialMyVote: idea.my_vote,
    initialUpCount: idea.up_count,
    initialDownCount: idea.down_count,
    anonymousVotingEnabled: board.anonymous_voting_enabled,
    onError: () => setVoteFailed(true),
    onVotedDown: () => {
      if (voteReasonsEnabled) voteReasonFollowUp.open(idea.id)
    },
  })

  const { myVote, upCount, downCount, onVoteUp, onVoteDown, turnstileModalProps } = voteResult
  const { withChallenge: withCommentChallenge, modalProps: commentTurnstileModalProps } =
    useTurnstileChallenge()

  // Retracting the vote deletes its DB row server-side (VoteRepository),
  // taking any saved notify-email with it — reset the local "saved" badge
  // so a re-vote doesn't claim an email is on file when it no longer is.
  useEffect(() => {
    if (myVote === null) {
      setAnonVoteEmailSaved(false)
      setAnonVoteEmailEditing(false)
    }
  }, [myVote])

  const handleAnonVoteEmailSave = async () => {
    if (anonVoteEmailSaving) return

    setAnonVoteEmailSaving(true)
    setAnonVoteEmailError(null)

    try {
      await updateAnonVoteNotifyEmail(board.slug, idea.id, anonVoteEmailInput)
      setAnonVoteEmailSaved(true)
      setAnonVoteEmailEditing(false)
    } catch (err) {
      const apiErr = err as ApiError
      setAnonVoteEmailError(apiErr?.payload?.message ?? t('anonVoteEmailSaveFailed'))
    } finally {
      setAnonVoteEmailSaving(false)
    }
  }
  const componentStatus = toComponentStatus(
    findStatus(board.statuses, currentStatus)?.category ?? currentStatus,
  )
  const componentChip = statusChipFor(componentStatus, labelOf(currentStatus))
  // A status the owner closed for votes/comments (issue 03) — the server
  // refuses with 423 anyway; the UI says so up front.
  // Votes = voting window (server clock, issue 04) AND the status flag; a
  // merged idea (issue 10) is read-only — votes and comments go to the idea
  // it was merged into, the server answers 404 on both anyway.
  const votingReasonKey = blockedReasonKey(board.phases, 'voting')
  const voteReason = isMerged
    ? t('mergedClosed')
    : votingReasonKey !== null
      ? tCommon(votingReasonKey)
      : !statusAllows(board.statuses, currentStatus, 'votes')
        ? tCommon('status.votesClosed')
        : null
  const votesClosed = voteReason !== null
  const cardLabels = useIdeaCardLabels(voteReason, board.branding_pro)
  const commentsClosed = isMerged || !statusAllows(board.statuses, currentStatus, 'comments')
  const boardHref = accountPath(`/${board.slug}`)
  const commentCountLabel = `${comments.length} ${
    comments.length === 1 ? t('commentSingular') : t('commentPlural')
  }`
  const viewCountLabel = `${idea.view_count} ${
    idea.view_count === 1 ? t('viewSingular') : t('viewPlural')
  }`
  const anyPending = statusPending || pinPending || blockPending
  // Voter preview hides every inline moderation affordance, same as the
  // header — a real voter never sees the status/pin/block controls or the
  // per-comment "remove" button.
  const showModeration = isAdmin && !voterPreview
  // Pre-moderation (issue 05): only the author and moderators ever receive a
  // pending idea; it shows a status-neutral notice and no vote/comment UI.
  const isPendingApproval = idea.moderation_state === 'pending'

  return (
    <div style={brandingStyle(board, resolvedTheme)} {...brandingProAttributes(board.branding_pro)}>
      <BoardThemeMode mode={board.theme_mode} boardSlug={board.slug} />
      <PageShell
        legalLinks={legalLinksFor(language)}
        header={
          <LocalizedHeader
            logoHref={boardHref}
            basePath={boardHref}
            boardSlug={board.slug}
            isAuthenticated={is_authenticated}
            user={user}
            onLoginClick={() => navigate(`/login?r=${encodeURIComponent(boardHref)}`)}
            onLogoutClick={onLogout}
            scope={<ScopeLabel section={board.name} />}
            voterPreview={voterPreview}
            onVoterPreviewChange={onVoterPreviewChange}
            brandLogoUrl={boardLogoSrc(boardSlug, board)}
            brandWordmarkUrl={boardWordmarkSrc(boardSlug, board)}
            brandWordmarkPlated={boardWordmarkPlated(board)}
            brandInitialsName={boardInitialsName(board)}
            brandAccent={board.primary_color ?? undefined}
            brandLogoAlt={board.name}
            boardHeaderLinks={board.branding_pro?.header_links}
            hideRoadmap={board.branding_pro?.roadmap_hidden}
          />
        }
      >
        <div className="ds-phone-touch">
          <div className="mb-4 text-vp-sm">
            <BackLink as={Link} to={boardHref} testId="idea-back-to-board">
              {board.name}
            </BackLink>
          </div>
          <BoardPhaseLine phases={board.phases} className="mb-4" />

          {isMerged && (
            <InlineNotice
              tone="info"
              className="mb-4"
              action={
                showModeration ? (
                  <Button
                    testId="idea-undo-merge"
                    variant="secondary"
                    onClick={() => void handleUnmerge(idea.id)}
                    disabled={unmergePendingId !== null}
                    loading={unmergePendingId === idea.id}
                  >
                    {t('undoMerge')}
                  </Button>
                ) : undefined
              }
            >
              <p>{t('mergedBanner')}</p>
              {idea.merged_into && (
                <Link
                  to={accountPath(`/${board.slug}/idea/${idea.merged_into.id}`)}
                  className="underline underline-offset-2 font-medium"
                >
                  {t('mergedBannerLink', { title: idea.merged_into.title })}
                </Link>
              )}
            </InlineNotice>
          )}
          {unmergeError !== null && (
            <InlineNotice tone="error" className="mb-4">
              {unmergeError}
            </InlineNotice>
          )}
          {isPendingApproval && (
            <div className="mb-4">
              <InlineNotice tone="info" title={t('pendingApprovalTitle')}>
                {t('pendingApprovalBody')}
              </InlineNotice>
            </div>
          )}

          <article
            className="vp-sheet vp-sheet--ruled vp-sheet--bleed animate-vp-rise"
            aria-label={ideaTranslation.displayTitle}
          >
            <div className="flex flex-col gap-4 sm:flex-row sm:gap-6 p-4 sm:p-6">
              {!isPendingApproval && (
                <div className="relative shrink-0 sm:pt-1.5">
                  <div
                    className="flex flex-row items-stretch gap-2 sm:flex-col sm:items-center max-sm:hidden"
                    data-testid="idea-vote-controls"
                  >
                    <VoteControl
                      direction="up"
                      size="field"
                      count={upCount}
                      selected={myVote === 'up'}
                      ideaTitle={ideaTranslation.displayTitle}
                      readonly={votesClosed}
                      onVote={onVoteUp}
                      labels={cardLabels.voteLabels}
                      locale={cardLabels.locale}
                      testId="idea-vote-up"
                    />
                    <VoteControl
                      direction="down"
                      size="field"
                      count={downCount}
                      selected={myVote === 'down'}
                      ideaTitle={ideaTranslation.displayTitle}
                      readonly={votesClosed}
                      onVote={onVoteDown}
                      labels={cardLabels.voteLabels}
                      locale={cardLabels.locale}
                      testId="idea-vote-down"
                    />
                  </div>
                  {voteReasonsEnabled && voteReasonFollowUp.state.ideaId === idea.id && (
                    <div className="mt-3 max-w-md">
                      <VoteReasonFollowUp
                        testId="idea-reason"
                        followUp={voteReasonFollowUp}
                        returnFocusTestId="idea-vote-down"
                      />
                    </div>
                  )}
                  {!is_authenticated && board.anonymous_voting_enabled && myVote !== null && (
                    <div className="mt-3 w-40 sm:w-44">
                      {anonVoteEmailSaved && !anonVoteEmailEditing ? (
                        <div className="text-vp-xs text-vp-text-secondary">
                          <p>{t('anonVoteEmailSaved')}</p>
                          <button
                            type="button"
                            className="mt-1 underline underline-offset-2 hover:text-vp-ink"
                            onClick={() => setAnonVoteEmailEditing(true)}
                          >
                            {t('anonVoteEmailChange')}
                          </button>
                        </div>
                      ) : (
                        <div className="flex flex-col gap-2">
                          <p className="text-vp-xs font-medium text-vp-ink">
                            {t('anonVoteEmailPrompt')}
                          </p>
                          <TextField
                            testId="idea-anon-vote-email"
                            label={t('anonVoteEmailLabel')}
                            hint={t('anonVoteEmailHint')}
                            placeholder={t('anonVoteEmailPlaceholder')}
                            type="email"
                            value={anonVoteEmailInput}
                            onChange={(e) => {
                              setAnonVoteEmailInput(e.target.value)
                              setAnonVoteEmailError(null)
                            }}
                            error={anonVoteEmailError ?? undefined}
                            disabled={anonVoteEmailSaving}
                            name="anon-vote-email"
                          />
                          <Button
                            testId="idea-anon-vote-email-save"
                            type="button"
                            variant="secondary"
                            onClick={() => void handleAnonVoteEmailSave()}
                            disabled={anonVoteEmailSaving || anonVoteEmailInput.trim() === ''}
                            loading={anonVoteEmailSaving}
                          >
                            {t('anonVoteEmailSave')}
                          </Button>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              )}

              <div className="flex-1 min-w-0">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                  <h1 className="font-archivo font-bold text-vp-2xl sm:text-vp-3xl tracking-[-0.025em] text-vp-ink leading-[1.1] text-balance min-w-0">
                    {ideaTranslation.displayTitle}
                  </h1>
                  <span
                    key={currentStatus}
                    className="animate-vp-fade-in motion-reduce:animate-none"
                  >
                    <StatusChip tone={componentChip.tone} icon={componentChip.icon}>
                      {componentChip.label}
                    </StatusChip>
                  </span>
                  <TagBadges tags={ideaTags} boardSlug={boardSlug} testIdPrefix="idea-detail" />
                </div>

                {/* Author line — same meta grammar as a comment's author line. */}
                <p className="mt-1.5 flex flex-wrap items-center gap-1.5 text-vp-xs text-vp-text-muted max-sm:[&_a]:min-h-11 max-sm:[&_a]:items-center">
                  <AuthorBadge
                    boardSlug={board.slug}
                    authorId={idea.author_id}
                    currentUserId={currentUserId}
                    myAvatarUrl={myAvatarUrl}
                    currentUserRole={currentUserRole}
                    currentUserIsOperator={user?.is_operator ?? false}
                    cache={profileCache.current}
                  />
                  <span aria-hidden="true">·</span>
                  <span>{formatDateTime(idea.created_at, language)}</span>
                </p>

                <p className="vp-prose mt-3 text-vp-md text-vp-text-secondary leading-6">
                  <MarkdownLite text={ideaTranslation.displayBody} />
                </p>

                {/* Ticket 07: machine-translation marking + single show-original
                toggle for title+description together — nothing to toggle
                when the idea already matched the viewer's language. */}
                {ideaTranslation.isTranslated && (
                  <p className="mt-1.5 flex items-center gap-1.5 text-vp-xs text-vp-text-muted">
                    <span>{t('machineTranslatedLabel')}</span>
                    <span aria-hidden="true">·</span>
                    <button
                      type="button"
                      className="underline hover:no-underline"
                      onClick={() => ideaTranslation.setShowOriginal(!ideaTranslation.showOriginal)}
                    >
                      {ideaTranslation.showOriginal
                        ? t('showTranslationLink')
                        : t('showOriginalLink')}
                    </button>
                  </p>
                )}

                <DecisionBlock
                  decision={decisionState.latest}
                  history={decisionState.history}
                  statusLabel={labelOf}
                />
                {ideaTranslation.translationFailed && (
                  <p className="mt-1.5 text-vp-xs text-vp-text-muted">
                    {t('translationUnavailableHint')}
                  </p>
                )}

                {/* Declared tally */}
                <div className="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 text-vp-sm">
                  <span className="inline-flex items-center gap-1.5 text-vp-vote-up-strong font-mono-num font-semibold">
                    <span role="img" aria-label={t('upvotesAriaLabel')}>
                      <TallyMark kind="up" />
                    </span>
                    <span>{upCount}</span>
                    <span className="font-inter font-normal text-vp-text-secondary">
                      {tCommon('vote.upLabel')}
                    </span>
                  </span>
                  <span className="inline-flex items-center gap-1.5 text-vp-vote-down-strong font-mono-num font-semibold">
                    <span role="img" aria-label={t('downvotesAriaLabel')}>
                      <TallyMark kind="down" />
                    </span>
                    <span>{downCount}</span>
                    <span className="font-inter font-normal text-vp-text-secondary">
                      {tCommon('vote.downLabel')}
                    </span>
                  </span>
                  {comments.length > 0 && (
                    <a
                      href="#comments"
                      className="max-sm:inline-flex max-sm:min-h-11 max-sm:items-center text-vp-text-muted hover:text-vp-ink underline decoration-2 underline-offset-[0.2em] decoration-vp-underline hover:decoration-current"
                    >
                      {commentCountLabel}
                    </a>
                  )}
                  <span className="inline-flex items-center gap-1.5 text-vp-text-muted">
                    <span role="img" aria-label={t('viewsAriaLabel')}>
                      <EyeGlyph />
                    </span>
                    <span className="font-mono-num">{viewCountLabel}</span>
                  </span>
                  {is_authenticated && <IdeaFollowToggle boardSlug={board.slug} ideaId={idea.id} />}
                  <button
                    type="button"
                    onClick={() => setShowReportModal(true)}
                    className="inline-flex items-center gap-1 max-sm:min-h-11 text-vp-text-muted hover:text-vp-vote-down-strong"
                  >
                    <Flag size={12} strokeWidth={2} aria-hidden="true" />
                    {tCommon('report.linkLabel')}
                  </button>
                </div>

                <div className="mt-3 max-w-xs">
                  <VoteSummary
                    up={upCount}
                    down={downCount}
                    fields={['net', 'approval']}
                    labels={cardLabels.summaryLabels}
                    locale={intlLocale(language)}
                    testId="idea-vote-summary"
                  />
                </div>

                {isOwner && (
                  <div className="mt-5 flex flex-wrap items-center gap-2 max-sm:[&_button]:min-h-11">
                    {canEditIdea() && (
                      <ButtonLink
                        href={accountPath(`/${board.slug}/idea/${idea.id}/edit`)}
                        linkAs={RouterButtonLink}
                        variant="secondary"
                        size="default"
                        testId="idea-detail-edit"
                      >
                        <Pencil size={14} strokeWidth={2} aria-hidden="true" />
                        {t('edit')}
                      </ButtonLink>
                    )}
                    <Button
                      testId="idea-withdraw"
                      variant="destructive"
                      onClick={() => setShowWithdrawConfirm(true)}
                      disabled={withdrawing}
                      loading={withdrawing}
                    >
                      {withdrawing ? t('withdrawing') : t('withdraw')}
                    </Button>
                    {withdrawError !== null && (
                      <InlineNotice tone="error" className="w-full mt-1">
                        {withdrawError}
                      </InlineNotice>
                    )}
                  </div>
                )}
              </div>
            </div>

            {/* Moderation strip — admins only, hidden while previewing as a voter */}
            {showModeration && !isMerged && (
              <div
                role="group"
                aria-label={t('moderationAriaLabel')}
                className="border-t border-vp-rule bg-vp-surface-frost px-4 sm:px-6 py-3 max-sm:[&_button]:min-h-11 max-sm:[&_select]:min-h-11"
              >
                <p className="vp-eyebrow mb-2 flex items-center gap-1.5">
                  <ShieldCheck size={13} strokeWidth={2} aria-hidden="true" />
                  {t('moderationLabel')}
                </p>
                <div className="flex flex-wrap items-center gap-2">
                  <Select
                    testId="idea-status-change"
                    label={t('changeStatusAriaLabel')}
                    hideLabel
                    value=""
                    onChange={(v) => {
                      // Picking a status opens the reason form; nothing is saved yet (P-01).
                      if (v !== '' && v !== currentStatus) setPendingStatus(v)
                    }}
                    disabled={statusPending}
                    className="w-44"
                  >
                    <option value="" disabled>
                      {t('changeStatusPlaceholder')}
                    </option>
                    {allowedNext.map((s) => (
                      <option key={s} value={s}>
                        {labelOf(s)}
                      </option>
                    ))}
                  </Select>

                  <Button
                    testId="idea-pin-toggle"
                    variant="secondary"
                    aria-pressed={isPinned}
                    onClick={() => void handlePinToggle()}
                    disabled={pinPending}
                  >
                    {isPinned ? t('pinned') : t('pin')}
                  </Button>

                  {/* A pending idea (pre-moderation) can be neither merge source nor target. */}
                  {!isPendingApproval && (
                    <Button
                      testId="idea-merge-into"
                      variant="secondary"
                      onClick={() => setShowMergeDialog(true)}
                    >
                      {t('mergeInto')}
                    </Button>
                  )}

                  <span aria-hidden="true" className="hidden sm:block h-5 w-px bg-vp-rule mx-1" />

                  <Select
                    testId="idea-block-scope"
                    label={t('blockScopeAriaLabel')}
                    hideLabel
                    value={blockScope}
                    onChange={(v) => setBlockScope(v as BlockScope)}
                    disabled={blockPending || authorBlocked}
                    className="w-36"
                  >
                    <option value="account">{t('scopeAccount')}</option>
                    <option value="board">{t('scopeBoardOption')}</option>
                  </Select>
                  <Button
                    testId="idea-author-block-toggle"
                    variant="destructive"
                    aria-pressed={authorBlocked}
                    onClick={() =>
                      authorBlocked ? void handleBlockToggle() : setShowBlockConfirm(true)
                    }
                    disabled={blockPending}
                  >
                    {t(authorBlocked ? 'authorBlockedLabel' : 'authorBlockLabel', {
                      scope: blockScope === 'board' ? t('scopeBoard') : t('scopeAccount'),
                    })}
                  </Button>

                  <span aria-hidden="true" className="hidden sm:block h-5 w-px bg-vp-rule mx-1" />

                  <Button
                    testId="idea-moderate"
                    variant="destructive"
                    onClick={() => setShowIdeaModerationModal(true)}
                    disabled={moderatingIdea}
                    loading={moderatingIdea}
                  >
                    {t('moderateIdea')}
                  </Button>

                  {anyPending && (
                    <span
                      className="text-vp-xs text-vp-text-muted"
                      aria-live="polite"
                      aria-busy="true"
                    >
                      {t('settingInProgress')}
                    </span>
                  )}
                </div>
                {pendingStatus !== null && (
                  <StatusDecisionForm
                    statusLabel={labelOf(pendingStatus)}
                    declined={findStatus(board.statuses, pendingStatus)?.category === 'declined'}
                    reasonRequired={board.status_reason_required === true}
                    pending={statusPending}
                    onSubmit={(decision) => void handleStatusChange(pendingStatus, decision)}
                    onCancel={() => setPendingStatus(null)}
                  />
                )}

                {(statusError ?? pinError ?? blockError) !== null && (
                  <InlineNotice tone="error" className="mt-2">
                    {statusError ?? pinError ?? blockError}
                  </InlineNotice>
                )}

                {(idea.merged_ideas ?? []).length > 0 && (
                  <div className="mt-3 pt-3 border-t border-vp-rule">
                    <p className="vp-eyebrow mb-1.5">{t('mergedIdeasLabel')}</p>
                    <ul className="flex flex-col gap-1.5">
                      {(idea.merged_ideas ?? []).map((m) => (
                        <li key={m.id} className="flex flex-wrap items-center gap-2 text-vp-sm">
                          <Link
                            to={accountPath(`/${board.slug}/idea/${m.id}`)}
                            className="underline underline-offset-2 text-vp-ink break-words min-w-0"
                          >
                            {m.title}
                          </Link>
                          <Button
                            testId={`idea-merged-undo-${m.id}`}
                            variant="text"
                            onClick={() => void handleUnmerge(m.id)}
                            disabled={unmergePendingId !== null}
                            loading={unmergePendingId === m.id}
                          >
                            {t('undoMerge')}
                          </Button>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}

                <div className="mt-3 pt-3 border-t border-vp-rule">
                  <TagManager
                    boardSlug={board.slug}
                    ideaId={idea.id}
                    ideaTags={ideaTags}
                    onChange={setIdeaTags}
                  />
                </div>

                <IdeaIssueLinksPanel boardSlug={board.slug} ideaId={idea.id} />
              </div>
            )}
          </article>

          {showModeration && voteReasonsEnabled && (
            <VoteReasonOperatorSection
              testId="idea-reasons-operator"
              boardSlug={board.slug}
              ideaId={idea.id}
            />
          )}

          {/* Pending (pre-moderation): no comments until a moderator approves. */}
          {!isPendingApproval && (
            <div className="mt-4">
              <Section
                testId="idea-comments-section"
                id="comments"
                icon={<MessageSquare size={16} strokeWidth={2} aria-hidden="true" />}
                title={comments.length === 0 ? t('commentsAriaLabel') : commentCountLabel}
                flush
                footer={
                  <div className="w-full">
                    {commentsClosed ? (
                      <p className="text-vp-sm text-vp-text-muted">
                        {isMerged ? t('mergedClosed') : tCommon('status.commentsClosed')}
                      </p>
                    ) : is_authenticated ? (
                      <form
                        onSubmit={(e) => void handleCommentSubmit(e)}
                        className="flex flex-col gap-3"
                      >
                        <TextArea
                          testId="idea-comment-body"
                          ref={commentBodyRef}
                          id="comment-body"
                          label={t('commentLabel')}
                          labelEnd={
                            <div className="max-sm:[&_button]:min-h-11 max-sm:[&_button]:min-w-11">
                              <MarkdownToolbar
                                textareaRef={commentBodyRef}
                                value={commentBody}
                                onChange={setCommentBody}
                              />
                            </div>
                          }
                          value={commentBody}
                          onChange={(e) => setCommentBody(e.target.value)}
                          disabled={commentPending}
                          placeholder={t('commentPlaceholder')}
                          hint={t('commentHint', { count: commentBody.length })}
                          rows={3}
                          maxLength={500}
                        />
                        <div className="flex flex-wrap items-center gap-3">
                          <Button
                            testId="idea-comment-submit"
                            type="submit"
                            variant="primary"
                            disabled={commentPending || commentBody.trim() === ''}
                            loading={commentPending}
                          >
                            {commentPending ? t('posting') : t('comment')}
                          </Button>
                          {commentError !== null && (
                            <InlineNotice tone="error" className="flex-1 min-w-48">
                              {commentError}
                            </InlineNotice>
                          )}
                        </div>
                      </form>
                    ) : (
                      <p className="text-vp-sm text-vp-text-secondary">
                        <a
                          href={`/login?r=${encodeURIComponent(accountPath(`/${board.slug}/idea/${idea.id}`))}`}
                          className="font-medium text-vp-ink underline hover:no-underline"
                        >
                          {t('loginToComment')}
                        </a>
                        {t('loginToCommentSuffix')}
                      </p>
                    )}
                  </div>
                }
              >
                {comments.length === 0 ? (
                  <EmptyState
                    kind="no-ideas"
                    testId="idea-comments-empty"
                    title={t('noCommentsTitle')}
                    description={is_authenticated ? t('noCommentsYet') : t('noCommentsAnonHint')}
                  />
                ) : (
                  <ul
                    className="divide-y divide-vp-border-subtle"
                    aria-label={t('commentsAriaLabel')}
                  >
                    {comments.map((comment) => {
                      const commentTranslation = commentTranslationView(comment)
                      return (
                        <li
                          key={comment.id}
                          className="flex flex-col gap-2 px-4 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-3 sm:px-5"
                        >
                          <div className="min-w-0 flex-1">
                            <p className="text-vp-xs text-vp-text-muted mb-1 flex flex-wrap items-center gap-1.5 max-sm:[&_a]:min-h-11 max-sm:[&_a]:items-center">
                              <AuthorBadge
                                boardSlug={board.slug}
                                authorId={comment.author_id}
                                currentUserId={currentUserId}
                                myAvatarUrl={myAvatarUrl}
                                currentUserRole={currentUserRole}
                                currentUserIsOperator={user?.is_operator ?? false}
                                cache={profileCache.current}
                              />
                              <span aria-hidden="true">·</span>
                              <span>{formatDateTime(comment.created_at, language)}</span>
                              {comment.edited_at !== null && (
                                <>
                                  <span aria-hidden="true">·</span>
                                  <span>{t('commentEditedLabel')}</span>
                                </>
                              )}
                            </p>
                            {editingCommentId === comment.id ? (
                              <form
                                onSubmit={(e) => void handleCommentEditSubmit(e, comment.id)}
                                className="flex flex-col gap-2"
                              >
                                <TextArea
                                  testId="idea-comment-edit-body"
                                  ref={editBodyRef}
                                  id={`comment-edit-body-${comment.id}`}
                                  label={t('editComment')}
                                  labelEnd={
                                    <div className="max-sm:[&_button]:min-h-11 max-sm:[&_button]:min-w-11">
                                      <MarkdownToolbar
                                        textareaRef={editBodyRef}
                                        value={editBody}
                                        onChange={setEditBody}
                                      />
                                    </div>
                                  }
                                  value={editBody}
                                  onChange={(e) => setEditBody(e.target.value)}
                                  disabled={editPending}
                                  hint={t('commentHint', { count: editBody.length })}
                                  rows={3}
                                  maxLength={500}
                                />
                                <div className="flex flex-wrap items-center gap-2">
                                  <Button
                                    testId="idea-comment-edit-save"
                                    type="submit"
                                    variant="primary"
                                    disabled={editPending || editBody.trim() === ''}
                                    loading={editPending}
                                  >
                                    {editPending
                                      ? t('savingEditedComment')
                                      : t('saveEditedComment')}
                                  </Button>
                                  <Button
                                    testId="idea-comment-edit-cancel"
                                    type="button"
                                    variant="text"
                                    onClick={cancelCommentEdit}
                                    disabled={editPending}
                                  >
                                    {t('cancelEditComment')}
                                  </Button>
                                </div>
                                {editError !== null && (
                                  <InlineNotice tone="error">{editError}</InlineNotice>
                                )}
                              </form>
                            ) : (
                              <>
                                <p className="vp-prose text-vp-base text-vp-ink leading-6">
                                  <MarkdownLite text={commentTranslation.displayBody} />
                                </p>
                                {commentTranslation.isTranslated && (
                                  <p className="mt-1 flex items-center gap-1 text-vp-xs text-vp-text-muted">
                                    <span>{t('machineTranslatedLabel')}</span>
                                    <span aria-hidden="true">·</span>
                                    <button
                                      type="button"
                                      className="underline hover:no-underline"
                                      onClick={() => toggleCommentOriginal(comment.id)}
                                    >
                                      {commentsShowingOriginal.has(comment.id)
                                        ? t('showTranslationLink')
                                        : t('showOriginalLink')}
                                    </button>
                                  </p>
                                )}
                                {commentTranslation.failed && (
                                  <p className="mt-1 text-vp-xs text-vp-text-muted">
                                    {t('translationUnavailableHint')}
                                  </p>
                                )}
                              </>
                            )}
                            <CommentReactionBar
                              boardSlug={board.slug}
                              ideaId={idea.id}
                              commentId={comment.id}
                              initialReactions={
                                comment.reactions ?? { counts: {}, my_reaction: null }
                              }
                              isAuthenticated={is_authenticated}
                              onRequireLogin={() =>
                                navigate(
                                  `/login?r=${encodeURIComponent(accountPath(`/${board.slug}/idea/${idea.id}`))}`,
                                )
                              }
                              ariaLabelFor={(emoji) =>
                                emoji === null
                                  ? t('reactionsGroupAriaLabel')
                                  : t('reactionAriaLabel', { emoji })
                              }
                            />
                          </div>
                          {editingCommentId !== comment.id && (
                            <div className="flex flex-wrap items-center gap-1 sm:shrink-0">
                              {canEditComment(comment) && (
                                <Button
                                  testId={`idea-comment-edit-${comment.id}`}
                                  variant="text"
                                  onClick={() => startCommentEdit(comment)}
                                >
                                  {t('edit')}
                                </Button>
                              )}
                              {showModeration && (
                                <Button
                                  testId={`idea-comment-remove-${comment.id}`}
                                  variant="text"
                                  onClick={() => setConfirmDeleteCommentId(comment.id)}
                                  disabled={deletingCommentId === comment.id}
                                  className="text-vp-vote-down-strong"
                                >
                                  {deletingCommentId === comment.id ? t('removing') : t('remove')}
                                </Button>
                              )}
                              {showModeration && (
                                <Button
                                  testId={`idea-comment-author-block-${comment.author_id}`}
                                  variant="text"
                                  aria-pressed={blockedCommentAuthors.has(comment.author_id)}
                                  onClick={() =>
                                    blockedCommentAuthors.has(comment.author_id)
                                      ? void handleCommentAuthorBlockToggle(comment.author_id)
                                      : setConfirmBlockCommentAuthorId(comment.author_id)
                                  }
                                  disabled={commentBlockPendingAuthorId === comment.author_id}
                                  className="text-vp-vote-down-strong"
                                >
                                  {blockedCommentAuthors.has(comment.author_id)
                                    ? t('commentAuthorBlockedLabel')
                                    : t('commentAuthorBlockLabel')}
                                </Button>
                              )}
                            </div>
                          )}
                        </li>
                      )
                    })}
                  </ul>
                )}

                {commentBlockError !== null && (
                  <div className="px-ds-md sm:px-ds-lg pb-ds-md">
                    <InlineNotice tone="error">{commentBlockError}</InlineNotice>
                  </div>
                )}
              </Section>
            </div>
          )}

          {/* Legal board-operator disclosure (issue 03) — a voter can land here
        directly via a shared idea link without visiting the board home page
        first, so the disclosure repeats here too. Not gated behind any
        plan-tier/edition logic, see BoardOperatorFooter's doc. */}
          <BoardBrandFooter
            slug={board.slug}
            legalOperatorName={board.legal_operator_name}
            visual={board}
          />

          {voteFailed && (
            <Toast
              type="error"
              message={tCommon('vote.failed')}
              onClose={() => setVoteFailed(false)}
              closeAriaLabel={tCommon('toast.closeAriaLabel')}
              testId="idea-vote-failed-toast"
            />
          )}

          <ConfirmDialog
            testId="idea-withdraw-dialog"
            open={showWithdrawConfirm}
            title={t('withdraw')}
            description={t('confirmWithdraw')}
            confirmLabel={t('withdraw')}
            cancelLabel={tCommon('action.cancel')}
            tone="danger"
            busy={withdrawing}
            onConfirm={() => void handleWithdraw()}
            onCancel={() => setShowWithdrawConfirm(false)}
          />

          <ConfirmDialog
            testId="idea-author-block-dialog"
            open={showBlockConfirm}
            title={t('authorBlockLabel', {
              scope: blockScope === 'board' ? t('scopeBoard') : t('scopeAccount'),
            })}
            description={t('confirmBlockAuthor', {
              scope: blockScope === 'board' ? t('scopeBoard') : t('scopeAccount'),
            })}
            confirmLabel={t('authorBlockLabel', {
              scope: blockScope === 'board' ? t('scopeBoard') : t('scopeAccount'),
            })}
            cancelLabel={tCommon('action.cancel')}
            tone="danger"
            busy={blockPending}
            onConfirm={() => {
              setShowBlockConfirm(false)
              void handleBlockToggle()
            }}
            onCancel={() => setShowBlockConfirm(false)}
          />

          <ConfirmDialog
            testId="idea-comment-author-block-dialog"
            open={confirmBlockCommentAuthorId !== null}
            title={t('commentAuthorBlockLabel')}
            description={t('confirmBlockCommentAuthor')}
            confirmLabel={t('commentAuthorBlockLabel')}
            cancelLabel={tCommon('action.cancel')}
            tone="danger"
            busy={commentBlockPendingAuthorId !== null}
            onConfirm={() => {
              if (confirmBlockCommentAuthorId === null) return
              const authorId = confirmBlockCommentAuthorId
              setConfirmBlockCommentAuthorId(null)
              void handleCommentAuthorBlockToggle(authorId)
            }}
            onCancel={() => setConfirmBlockCommentAuthorId(null)}
          />

          <ContentModerationModal
            open={confirmDeleteCommentId !== null}
            entityType="comment"
            onClose={() => setConfirmDeleteCommentId(null)}
            onConfirm={async (reasonCode, reasonNote) => {
              if (confirmDeleteCommentId === null) return
              await handleCommentDelete(confirmDeleteCommentId, reasonCode, reasonNote)
            }}
          />

          <ContentModerationModal
            open={showIdeaModerationModal}
            entityType="idea"
            onClose={() => setShowIdeaModerationModal(false)}
            onConfirm={async (reasonCode, reasonNote) => {
              await handleIdeaModerate(reasonCode, reasonNote)
            }}
          />

          {showModeration && (
            <MergeIdeaDialog
              open={showMergeDialog}
              boardSlug={board.slug}
              ideaId={idea.id}
              onClose={() => setShowMergeDialog(false)}
              onMerged={(targetId) => {
                setShowMergeDialog(false)
                navigate(accountPath(`/${board.slug}/idea/${targetId}`))
              }}
            />
          )}

          <ReportContentModal
            open={showReportModal}
            onClose={() => setShowReportModal(false)}
            url={typeof window !== 'undefined' ? window.location.href : boardHref}
            accountSlug={getAccountSlug() ?? undefined}
            boardSlug={board.slug}
            ideaId={idea.id}
          />

          <TurnstileChallengeModal {...turnstileModalProps} />
          <TurnstileChallengeModal {...commentTurnstileModalProps} />
          {isPhone && !isPendingApproval && (
            <div className="ds-idea-dock" data-testid="idea-dock">
              <VoteControl
                direction="up"
                size="field"
                count={upCount}
                selected={myVote === 'up'}
                ideaTitle={ideaTranslation.displayTitle}
                readonly={votesClosed}
                onVote={onVoteUp}
                labels={cardLabels.voteLabels}
                locale={cardLabels.locale}
                testId="idea-dock-vote-up"
              />
              <VoteControl
                direction="down"
                size="field"
                count={downCount}
                selected={myVote === 'down'}
                ideaTitle={ideaTranslation.displayTitle}
                readonly={votesClosed}
                onVote={onVoteDown}
                labels={cardLabels.voteLabels}
                locale={cardLabels.locale}
                testId="idea-dock-vote-down"
              />
              <button
                type="button"
                className="ds-idea-dock__comment"
                data-testid="idea-dock-comment"
                onClick={() => {
                  const target = commentBodyRef.current ?? document.getElementById('comments')
                  target?.scrollIntoView?.({ block: 'center' })
                  target?.focus?.()
                }}
              >
                {t('comment')}
              </button>
            </div>
          )}
        </div>
      </PageShell>
    </div>
  )
}

// ── IdeaDetailPage ────────────────────────────────────────────────────────────

export default function IdeaDetailPage() {
  const { boardSlug, ideaId } = useParams<{ boardSlug: string; ideaId: string }>()
  const t = useT('ideaDetailPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [loadState, setLoadState] = useState<LoadState>({ phase: 'loading' })
  const online = useIsOnline()
  const tCommonOffline = useT('common')
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [currentUserId, setCurrentUserId] = useState<number | null>(null)
  const [myAvatarUrl, setMyAvatarUrl] = useState<string | null>(null)
  const [currentUserRole, setCurrentUserRole] = useState<AccountRole | null>(null)
  const [isAdmin, setIsAdmin] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [retryKey, setRetryKey] = useState(0)
  const [viewAsVoter, setViewAsVoterPreview] = useVoterPreview()
  // Ticket 07/08: whether the translate endpoints are usable at all
  // (bootstrap()'s translation_available, Config::translationConfigured()
  // server-side). Undefined until bootstrap resolves is treated as false —
  // auto-translate must never fire before this is known.
  const [translationAvailable, setTranslationAvailable] = useState(false)

  useEffect(() => {
    bootstrap()
      .then((b) => {
        setIsAuthenticated(b.user !== null)
        setCurrentUserId(b.user?.id ?? null)
        const role = accountRoleFor(b.user, getAccountSlug())
        setCurrentUserRole(role)
        setIsAdmin(role !== null)
        setUser(b.user)
        setTranslationAvailable(b.translation_available ?? false)
        // Own avatar via the private profile endpoint; other authors' avatars
        // come from their PUBLIC profile (AuthorBadge), which the server only
        // fills in for users who opted in to a visible profile.
        if (b.user !== null) {
          getAccountProfile()
            .then((p) => setMyAvatarUrl(p.avatar_url))
            .catch(() => {})
        }
      })
      .catch(() => {})
  }, [])

  // biome-ignore lint/correctness/useExhaustiveDependencies: retryKey is a deliberate re-run trigger.
  useEffect(() => {
    if (!boardSlug || !ideaId) return

    setLoadState({ phase: 'loading' })

    getIdea(boardSlug, ideaId)
      .then((data) => {
        setIsAuthenticated(data.is_authenticated)
        setLoadState({ phase: 'done', data })
      })
      .catch((err: unknown) => {
        const apiErr = err as ApiError
        const notFound = apiErr.name === 'ApiError' && apiErr.status === 404
        setLoadState({ phase: 'error', notFound, message: t('loadError') })
      })
  }, [boardSlug, ideaId, t, retryKey])

  const handleLogout = () => {
    logout()
      .catch(() => {})
      .finally(() => {
        setIsAuthenticated(false)
        setUser(null)
        window.location.href = '/login'
      })
  }

  const boardHref = boardSlug ? accountPath(`/${boardSlug}`) : accountPath('/')

  // A missing or foreign idea shows the shared not-found page, with no identifiers on it.
  if (loadState.phase === 'error' && loadState.notFound) {
    return (
      <NotFoundState
        title={t('notFoundTitle')}
        message={t('notFoundDescription')}
        secondaryLink={{
          to: boardHref,
          label: t('backToBoard'),
          testId: 'idea-detail-not-found-back',
        }}
      />
    )
  }

  if (loadState.phase !== 'done') {
    const header = (
      <LocalizedHeader
        logoHref={boardHref}
        basePath={boardSlug ? accountPath(`/${boardSlug}`) : ''}
        boardSlug={boardSlug}
        isAuthenticated={isAuthenticated}
        user={user}
        onLogoutClick={handleLogout}
        scope={<ScopeLabel />}
        voterPreview={viewAsVoter}
        onVoterPreviewChange={setViewAsVoterPreview}
      />
    )

    if (loadState.phase === 'loading') {
      return (
        <PageShell header={header} legalLinks={legalLinksFor(language)}>
          {/* Mirrors the loaded page: back link, the ruled idea sheet, the comment sheet. */}
          <span aria-hidden="true" className="ds-skeleton__block h-4 w-32 mb-4" />
          <div className="vp-sheet vp-sheet--ruled px-4 sm:px-6">
            <LoadingState label={t('loading')} rows={1} variant="ballot" />
          </div>
          <div className="vp-sheet mt-4 px-4 sm:px-5" aria-hidden="true">
            <div className="py-2">
              <Skeleton lines={2} label={t('loading')} testId="idea-detail-loading-comments" />
            </div>
          </div>
        </PageShell>
      )
    }

    return (
      <PageShell header={header} legalLinks={legalLinksFor(language)}>
        {!online && (
          <InlineNotice tone="warning" className="mb-4" role="status">
            {tCommonOffline('state.offline')}
          </InlineNotice>
        )}
        <ErrorState
          title={t('errorTitle')}
          description={loadState.message}
          action={
            <Button
              testId="idea-state-retry"
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

  return (
    <IdeaDetailContent
      data={loadState.data}
      boardSlug={boardSlug ?? ''}
      currentUserId={currentUserId}
      myAvatarUrl={myAvatarUrl}
      currentUserRole={currentUserRole}
      isAdmin={isAdmin}
      user={user}
      onLogout={handleLogout}
      translationAvailable={translationAvailable}
      voterPreview={viewAsVoter}
      onVoterPreviewChange={setViewAsVoterPreview}
      onReload={() => setRetryKey((k) => k + 1)}
    />
  )
}
