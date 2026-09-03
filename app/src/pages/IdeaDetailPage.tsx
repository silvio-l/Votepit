import type { Status } from '@votepit/ui'
import {
  Alert,
  Button,
  buttonClassName,
  ConfirmDialog,
  ConsensusBar,
  EmptyState,
  ErrorState,
  LoadingState,
  PageShell,
  Section,
  Select,
  Skeleton,
  SkeletonRows,
  StatusBadge,
  Textarea,
  Toast,
  VoteWidget,
} from '@votepit/ui'
import { ArrowLeft, MessageSquare, Pencil, ShieldCheck } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { AuthorBadge } from '../components/AuthorBadge'
import { LocalizedHeader, ScopeLabel } from '../components/LocalizedHeader'
import { createPublicProfileCache } from '../hooks/usePublicProfile'
import { useVote } from '../hooks/useVote'
import { accountPath, getAccountSlug } from '../lib/accountContext'
import type {
  AccountRole,
  ApiError,
  BlockScope,
  Comment,
  IdeaDetailResponse,
  IdeaStatus,
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
  setIdeaPinned,
  setIdeaStatus,
  withdrawIdea,
} from '../lib/api'
import { legalLinksFor } from '../lib/features'
import { formatDateTime } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

// ── Status transitions (mirror of StatusService::TRANSITIONS) ─────────────────

const TRANSITIONS: Record<IdeaStatus, IdeaStatus[]> = {
  open: ['planned', 'in_progress', 'done', 'declined'],
  planned: ['in_progress', 'done', 'declined', 'open'],
  in_progress: ['done', 'declined', 'planned'],
  done: ['in_progress', 'declined'],
  declined: ['open'],
}

function statusLabels(t: ReturnType<typeof useT>): Record<IdeaStatus, string> {
  return {
    open: t('statusLabelOpen'),
    planned: t('statusLabelPlanned'),
    in_progress: t('statusLabelInProgress'),
    done: t('statusLabelDone'),
    declined: t('statusLabelDeclined'),
  }
}

function toComponentStatus(raw: string): Status {
  if (raw === 'in_progress') return 'in-progress'
  const valid: Status[] = ['open', 'planned', 'in-progress', 'done', 'declined']
  return valid.includes(raw as Status) ? (raw as Status) : 'open'
}

function calcConsensus(upCount: number, downCount: number): number | null {
  const total = upCount + downCount
  if (total === 0) return null
  return Math.round((upCount / total) * 100)
}

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
}

function IdeaDetailContent({
  data,
  boardSlug: _boardSlug,
  currentUserId,
  myAvatarUrl,
  currentUserRole,
  isAdmin,
  user,
  onLogout,
}: IdeaDetailContentProps) {
  const { board, idea, is_authenticated } = data
  const navigate = useNavigate()
  const t = useT('ideaDetailPage')
  const tCommon = useT('common')
  const { language } = useI18n()
  const STATUS_LABELS = statusLabels(t)

  // One public-profile lookup per distinct author on this page (the idea's
  // author plus every commenter), shared by all AuthorBadges below.
  const profileCache = useRef(createPublicProfileCache())

  // Comment list + compose box.
  const [comments, setComments] = useState<Comment[]>(data.comments)
  const [commentBody, setCommentBody] = useState('')
  const [commentPending, setCommentPending] = useState(false)
  const [commentError, setCommentError] = useState<string | null>(null)
  const [deletingCommentId, setDeletingCommentId] = useState<number | null>(null)
  const [deleteCommentError, setDeleteCommentError] = useState<string | null>(null)

  const [withdrawing, setWithdrawing] = useState(false)
  const [withdrawError, setWithdrawError] = useState<string | null>(null)
  const [showWithdrawConfirm, setShowWithdrawConfirm] = useState(false)

  // Admin status control state
  const [currentStatus, setCurrentStatus] = useState<IdeaStatus>(idea.status)
  const [statusPending, setStatusPending] = useState(false)
  const [statusError, setStatusError] = useState<string | null>(null)

  // Admin pin control state
  const [isPinned, setIsPinned] = useState(idea.is_pinned)
  const [pinPending, setPinPending] = useState(false)
  const [pinError, setPinError] = useState<string | null>(null)

  // A rejected vote rolls the mark back; the toast says why it snapped back.
  const [voteFailed, setVoteFailed] = useState(false)

  // Admin user-block control state (no persisted state to hydrate from,
  // starts unblocked and flips optimistically on action). Scope selector
  // picks account-wide vs. this-board-only.
  const [authorBlocked, setAuthorBlocked] = useState(false)
  const [blockScope, setBlockScope] = useState<BlockScope>('account')
  const [blockPending, setBlockPending] = useState(false)
  const [blockError, setBlockError] = useState<string | null>(null)

  const isOwner = currentUserId !== null && currentUserId === idea.author_id

  const handleStatusChange = async (to: IdeaStatus) => {
    if (statusPending) return
    const prev = currentStatus
    setCurrentStatus(to) // optimistic
    setStatusError(null)
    setStatusPending(true)
    try {
      await setIdeaStatus(board.slug, idea.id, to)
    } catch (err) {
      setCurrentStatus(prev) // rollback on error
      const apiErr = err as ApiError
      setStatusError(apiErr?.payload?.message ?? t('statusUpdateError'))
    } finally {
      setStatusPending(false)
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
      const res = await createComment(board.slug, idea.id, trimmed)
      setComments((prev) => [
        ...prev,
        {
          id: res.id,
          idea_id: idea.id,
          author_id: currentUserId ?? 0,
          body: trimmed,
          created_at: new Date().toISOString(),
        },
      ])
      setCommentBody('')
    } catch (err) {
      const apiErr = err as ApiError
      setCommentError(apiErr?.payload?.message ?? t('commentPostError'))
    } finally {
      setCommentPending(false)
    }
  }

  const handleCommentDelete = async (commentId: number) => {
    if (deletingCommentId !== null) return
    setDeletingCommentId(commentId)
    setDeleteCommentError(null)
    const prevComments = comments
    setComments((prev) => prev.filter((c) => c.id !== commentId)) // optimistic
    try {
      await deleteComment(board.slug, idea.id, commentId)
    } catch (err) {
      setComments(prevComments) // rollback on error
      const apiErr = err as ApiError
      setDeleteCommentError(apiErr?.payload?.message ?? t('commentDeleteError'))
    } finally {
      setDeletingCommentId(null)
    }
  }

  const voteResult = useVote({
    boardSlug: board.slug,
    ideaId: idea.id,
    isAuthenticated: is_authenticated,
    initialScore: idea.score_cache,
    initialMyVote: idea.my_vote,
    initialUpCount: idea.up_count,
    initialDownCount: idea.down_count,
    onError: () => setVoteFailed(true),
  })

  const { score, myVote, upCount, downCount, onVoteUp, onVoteDown } = voteResult
  const consensusPercent = calcConsensus(upCount, downCount)
  const componentStatus = toComponentStatus(currentStatus)
  const boardHref = accountPath(`/${board.slug}`)
  const commentCountLabel = `${comments.length} ${
    comments.length === 1 ? t('commentSingular') : t('commentPlural')
  }`
  const anyPending = statusPending || pinPending || blockPending

  return (
    <PageShell
      width="narrow"
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
        />
      }
    >
      <div className="mb-4 text-vp-sm">
        <Link
          to={boardHref}
          className="inline-flex items-center gap-1.5 text-vp-text-secondary hover:text-vp-ink hover:underline transition-colors duration-150"
        >
          <ArrowLeft size={14} strokeWidth={2} aria-hidden="true" />
          {board.name}
        </Link>
      </div>

      <article className="vp-card vp-sheet--ruled animate-vp-rise" aria-label={idea.title}>
        <div className="flex gap-4 sm:gap-6 p-4 sm:p-6">
          <div className="relative shrink-0 pt-1.5">
            <VoteWidget
              tone="leading"
              score={score}
              userVote={myVote}
              onVoteUp={onVoteUp}
              onVoteDown={onVoteDown}
              upAriaLabel={tCommon('vote.upAriaLabel')}
              downAriaLabel={tCommon('vote.downAriaLabel')}
              upLabel={tCommon('vote.upLabel')}
              downLabel={tCommon('vote.downLabel')}
            />
          </div>

          <div className="flex-1 min-w-0">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
              <h1 className="font-archivo font-bold text-vp-2xl sm:text-vp-3xl tracking-[-0.02em] text-vp-ink leading-tight text-balance min-w-0">
                {idea.title}
              </h1>
              <span key={currentStatus} className="animate-vp-fade-in">
                <StatusBadge status={componentStatus} label={STATUS_LABELS[currentStatus]} />
              </span>
            </div>

            {/* Author line — same meta grammar as a comment's author line. */}
            <p className="mt-1.5 flex flex-wrap items-center gap-1.5 text-vp-xs text-vp-text-muted">
              <AuthorBadge
                authorId={idea.author_id}
                currentUserId={currentUserId}
                myAvatarUrl={myAvatarUrl}
                currentUserRole={currentUserRole}
                cache={profileCache.current}
              />
              <span aria-hidden="true">·</span>
              <span>{formatDateTime(idea.created_at, language)}</span>
            </p>

            <p className="vp-prose mt-3 text-vp-md text-vp-text-secondary leading-6">{idea.body}</p>

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
                  className="text-vp-text-muted hover:text-vp-ink hover:underline"
                >
                  {commentCountLabel}
                </a>
              )}
            </div>

            <div className="mt-3 max-w-xs">
              <ConsensusBar
                percent={consensusPercent}
                label={tCommon('vote.consensusLabel')}
                labelLow={tCommon('vote.consensusLowLabel')}
                labelEmpty={tCommon('vote.consensusEmptyLabel')}
              />
            </div>

            {isOwner && (
              <div className="mt-5 flex flex-wrap items-center gap-2">
                <Link
                  to={accountPath(`/${board.slug}/idea/${idea.id}/edit`)}
                  className={buttonClassName('secondary', 'sm')}
                >
                  <Pencil size={14} strokeWidth={2} aria-hidden="true" />
                  {t('edit')}
                </Link>
                <Button
                  variant="danger"
                  size="sm"
                  onClick={() => setShowWithdrawConfirm(true)}
                  disabled={withdrawing}
                  loading={withdrawing}
                >
                  {withdrawing ? t('withdrawing') : t('withdraw')}
                </Button>
                {withdrawError !== null && (
                  <Alert tone="error" className="w-full mt-1">
                    {withdrawError}
                  </Alert>
                )}
              </div>
            )}
          </div>
        </div>

        {/* Moderation strip — admins only */}
        {isAdmin && (
          <div
            role="group"
            aria-label={t('moderationAriaLabel')}
            className="border-t border-vp-border-subtle bg-vp-surface-frost rounded-b-vp-lg px-4 sm:px-6 py-3"
          >
            <p className="vp-eyebrow mb-2 flex items-center gap-1.5">
              <ShieldCheck size={13} strokeWidth={2} aria-hidden="true" />
              {t('moderationLabel')}
            </p>
            <div className="flex flex-wrap items-center gap-2">
              <Select
                label={t('changeStatusAriaLabel')}
                hideLabel
                size="sm"
                value=""
                onChange={(v) => {
                  void handleStatusChange(v as IdeaStatus)
                }}
                disabled={statusPending}
                className="w-44"
              >
                <option value="" disabled>
                  {t('changeStatusPlaceholder')}
                </option>
                {TRANSITIONS[currentStatus]?.map((s) => (
                  <option key={s} value={s}>
                    {STATUS_LABELS[s]}
                  </option>
                ))}
              </Select>

              <Button
                variant="secondary"
                size="sm"
                aria-pressed={isPinned}
                onClick={() => void handlePinToggle()}
                disabled={pinPending}
              >
                {isPinned ? t('pinned') : t('pin')}
              </Button>

              <span aria-hidden="true" className="hidden sm:block h-5 w-px bg-vp-rule mx-1" />

              <Select
                label={t('blockScopeAriaLabel')}
                hideLabel
                size="sm"
                value={blockScope}
                onChange={(v) => setBlockScope(v as BlockScope)}
                disabled={blockPending || authorBlocked}
                className="w-36"
              >
                <option value="account">{t('scopeAccount')}</option>
                <option value="board">{t('scopeBoardOption')}</option>
              </Select>
              <Button
                variant="danger"
                size="sm"
                aria-pressed={authorBlocked}
                onClick={() => void handleBlockToggle()}
                disabled={blockPending}
              >
                {t(authorBlocked ? 'authorBlockedLabel' : 'authorBlockLabel', {
                  scope: blockScope === 'board' ? t('scopeBoard') : t('scopeAccount'),
                })}
              </Button>

              {anyPending && (
                <span className="text-vp-xs text-vp-text-muted" aria-live="polite" aria-busy="true">
                  {t('settingInProgress')}
                </span>
              )}
            </div>
            {(statusError ?? pinError ?? blockError) !== null && (
              <Alert tone="error" className="mt-2">
                {statusError ?? pinError ?? blockError}
              </Alert>
            )}
          </div>
        )}
      </article>

      <div className="mt-4">
        <Section
          id="comments"
          icon={<MessageSquare size={16} strokeWidth={2} aria-hidden="true" />}
          title={comments.length === 0 ? t('commentsAriaLabel') : commentCountLabel}
          flush
          footer={
            <div className="w-full">
              {is_authenticated ? (
                <form onSubmit={(e) => void handleCommentSubmit(e)} className="flex flex-col gap-3">
                  <Textarea
                    id="comment-body"
                    label={t('commentLabel')}
                    value={commentBody}
                    onChange={setCommentBody}
                    disabled={commentPending}
                    placeholder={t('commentPlaceholder')}
                    rows={3}
                    maxLength={2000}
                  />
                  <div className="flex flex-wrap items-center gap-3">
                    <Button
                      type="submit"
                      variant="primary"
                      size="sm"
                      disabled={commentPending || commentBody.trim() === ''}
                      loading={commentPending}
                    >
                      {commentPending ? t('posting') : t('comment')}
                    </Button>
                    {commentError !== null && (
                      <Alert tone="error" className="flex-1 min-w-48">
                        {commentError}
                      </Alert>
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
              size="compact"
              headingLevel={3}
              title={t('noCommentsTitle')}
              description={is_authenticated ? t('noCommentsYet') : t('noCommentsAnonHint')}
            />
          ) : (
            <ul className="divide-y divide-vp-border-subtle" aria-label={t('commentsAriaLabel')}>
              {comments.map((comment) => (
                <li
                  key={comment.id}
                  className="flex items-start justify-between gap-3 px-4 sm:px-5 py-4"
                >
                  <div className="min-w-0 flex-1">
                    <p className="text-vp-xs text-vp-text-muted mb-1 flex flex-wrap items-center gap-1.5">
                      <AuthorBadge
                        authorId={comment.author_id}
                        currentUserId={currentUserId}
                        myAvatarUrl={myAvatarUrl}
                        currentUserRole={currentUserRole}
                        cache={profileCache.current}
                      />
                      <span aria-hidden="true">·</span>
                      <span>{formatDateTime(comment.created_at, language)}</span>
                    </p>
                    <p className="vp-prose text-vp-base text-vp-ink leading-6">{comment.body}</p>
                  </div>
                  {isAdmin && (
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => void handleCommentDelete(comment.id)}
                      disabled={deletingCommentId === comment.id}
                      className="shrink-0 text-vp-vote-down-strong"
                    >
                      {deletingCommentId === comment.id ? t('removing') : t('remove')}
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}

          {deleteCommentError !== null && (
            <div className="px-4 sm:px-5 pb-4">
              <Alert tone="error">{deleteCommentError}</Alert>
            </div>
          )}
        </Section>
      </div>

      {voteFailed && (
        <Toast
          type="error"
          message={tCommon('vote.failed')}
          onClose={() => setVoteFailed(false)}
          closeAriaLabel={tCommon('toast.closeAriaLabel')}
        />
      )}

      <ConfirmDialog
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
    </PageShell>
  )
}

// ── IdeaDetailPage ────────────────────────────────────────────────────────────

export default function IdeaDetailPage() {
  const { boardSlug, ideaId } = useParams<{ boardSlug: string; ideaId: string }>()
  const t = useT('ideaDetailPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [loadState, setLoadState] = useState<LoadState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [currentUserId, setCurrentUserId] = useState<number | null>(null)
  const [myAvatarUrl, setMyAvatarUrl] = useState<string | null>(null)
  const [currentUserRole, setCurrentUserRole] = useState<AccountRole | null>(null)
  const [isAdmin, setIsAdmin] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [retryKey, setRetryKey] = useState(0)

  useEffect(() => {
    bootstrap()
      .then((b) => {
        setIsAuthenticated(b.user !== null)
        setCurrentUserId(b.user?.id ?? null)
        const role = accountRoleFor(b.user, getAccountSlug())
        setCurrentUserRole(role)
        setIsAdmin(role !== null)
        setUser(b.user)
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
        setLoadState({
          phase: 'error',
          notFound,
          message: notFound ? t('notFoundMessage') : t('loadError'),
        })
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
      />
    )

    if (loadState.phase === 'loading') {
      return (
        <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
          {/* Mirrors the loaded page: back link, the ruled idea sheet, the comment sheet. */}
          <Skeleton className="h-4 w-32 mb-4" />
          <div className="vp-card vp-sheet--ruled px-4 sm:px-6">
            <LoadingState label={t('loading')} rows={1} variant="ballot" />
          </div>
          <div className="vp-sheet mt-4 px-4 sm:px-5" aria-hidden="true">
            <div className="py-2">
              <SkeletonRows rows={2} />
            </div>
          </div>
        </PageShell>
      )
    }

    return (
      <PageShell header={header} width="narrow" legalLinks={legalLinksFor(language)}>
        {loadState.notFound ? (
          <ErrorState
            // A missing idea is a dead end, not a failure that interrupts.
            role="status"
            title={t('notFoundTitle')}
            description={t('notFoundDescription')}
            action={
              <Link to={boardHref} className={buttonClassName('secondary')}>
                <ArrowLeft size={16} strokeWidth={2} aria-hidden="true" />
                {t('backToBoard')}
              </Link>
            }
          />
        ) : (
          <ErrorState
            title={t('errorTitle')}
            description={loadState.message}
            action={
              <Button variant="secondary" onClick={() => setRetryKey((k) => k + 1)}>
                {tCommon('state.retry')}
              </Button>
            }
          />
        )}
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
    />
  )
}
