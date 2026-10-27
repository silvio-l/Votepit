/**
 * The embeddable widget UI (ADR 0005), rendered inside the iframe served by
 * GET {account}/{board}/widget.
 *
 * Security model (see core/documentation/widget.md):
 *  - postMessage: only the direct parent on an allowed origin is heard
 *    (acceptHostMessage); replies go to that exact origin, never '*'.
 *  - No cookies: every request is credentials:'omit'. An identified visitor
 *    holds a short-lived widget token in memory only (no storage).
 *  - Unidentified visitors are read-only; voting/commenting/submitting
 *    opens the full board in a new tab (noopener,noreferrer) where the
 *    normal login applies.
 */

import { Button, InlineNotice, summarizeVotes, TextArea, TextField } from '@votepit/ui/ds'
import { ArrowLeft, ChevronDown, ChevronUp, ExternalLink, MessageSquare, X } from 'lucide-react'
import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react'
import { brandingStyle } from '../lib/branding'
import * as api from './api'
import { acceptHostMessage, type WidgetConfig } from './config'
import type { WidgetT } from './i18n'

interface WidgetAppProps {
  config: WidgetConfig
  t: WidgetT
}

type View = { kind: 'list' } | { kind: 'idea'; id: number } | { kind: 'new' }

function openInNewTab(url: string): void {
  window.open(url, '_blank', 'noopener,noreferrer')
}

export function WidgetApp({ config, t }: WidgetAppProps) {
  const base = config.basePath
  const boardUrl = `${window.location.origin}${base}`

  const [token, setToken] = useState<string | null>(null)
  const [userName, setUserName] = useState<string | null>(null)
  const [view, setView] = useState<View>({ kind: 'list' })
  const [ideas, setIdeas] = useState<api.WidgetIdea[] | null>(null)
  const [loadFailed, setLoadFailed] = useState(false)
  const [error, setError] = useState<string | null>(null)
  /** Origin of the host page, learned from its first accepted message. */
  const hostOrigin = useRef<string | null>(null)

  const postToHost = useCallback((message: { type: string }) => {
    if (hostOrigin.current !== null) window.parent.postMessage(message, hostOrigin.current)
  }, [])

  const dropToken = useCallback(() => {
    setToken(null)
    setUserName(null)
  }, [])

  // ── Host channel ───────────────────────────────────────────────────────
  useEffect(() => {
    const onMessage = (event: MessageEvent) => {
      const message = acceptHostMessage(event, window.parent, config.origins)
      if (message === null) return
      hostOrigin.current = event.origin
      if (message.type === 'votepit:logout') {
        dropToken()
        return
      }
      if (message.type === 'votepit:identify') {
        api
          .identify(base, message.jwt)
          .then((result) => {
            setToken(result.token)
            setUserName(result.user.name)
          })
          .catch(() => dropToken())
      }
    }
    window.addEventListener('message', onMessage)
    return () => window.removeEventListener('message', onMessage)
  }, [base, config.origins, dropToken])

  // ── Idea list (public, or personalised with a token) ──────────────────
  useEffect(() => {
    let cancelled = false
    api
      .listIdeas(base, token)
      .then((result) => {
        if (!cancelled) setIdeas(result.ideas)
      })
      .catch((err: unknown) => {
        if (cancelled) return
        if (token !== null && err instanceof api.WidgetApiError && err.status === 401) {
          dropToken()
          return
        }
        setLoadFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [base, token, dropToken])

  /** Runs an identified action; an expired/revoked token falls back to read-only. */
  const runIdentified = async (action: (token: string) => Promise<void>) => {
    if (token === null) return
    setError(null)
    try {
      await action(token)
    } catch (err) {
      if (err instanceof api.WidgetApiError && err.status === 401) dropToken()
      setError(t('actionFailed'))
    }
  }

  const handleVote = (idea: api.WidgetIdea, value: 'up' | 'down') => {
    if (token === null) {
      openInNewTab(`${boardUrl}/idea/${idea.id}`)
      return
    }
    void runIdentified(async (tk) => {
      const result = await api.vote(base, tk, idea.id, value)
      setIdeas((prev) => prev?.map((i) => (i.id === idea.id ? { ...i, ...result } : i)) ?? prev)
    })
  }

  const header = (
    <header className="flex items-center justify-between gap-2 border-b border-vp-rule px-4 py-3">
      <div className="min-w-0">
        <h1 className="truncate text-vp-md font-semibold">{config.board.name}</h1>
        {userName !== null && (
          <p className="truncate text-vp-xs text-vp-text-muted">
            {t('signedInAs', { name: userName })}
          </p>
        )}
      </div>
      <button
        type="button"
        className="rounded p-1 text-vp-text-muted hover:text-vp-title"
        aria-label={t('close')}
        onClick={() => postToHost({ type: 'votepit:close' })}
      >
        <X size={18} aria-hidden="true" />
      </button>
    </header>
  )

  let body: ReactNode
  if (view.kind === 'idea') {
    body = (
      <IdeaView
        base={base}
        id={view.id}
        token={token}
        t={t}
        onBack={() => setView({ kind: 'list' })}
        onOpen={() => openInNewTab(`${boardUrl}/idea/${view.id}`)}
        runIdentified={runIdentified}
      />
    )
  } else if (view.kind === 'new' && token !== null) {
    body = (
      <NewIdeaView
        base={base}
        t={t}
        onBack={() => setView({ kind: 'list' })}
        runIdentified={runIdentified}
      />
    )
  } else if (loadFailed) {
    body = <InlineNotice tone="error">{t('loadFailed')}</InlineNotice>
  } else if (ideas === null) {
    body = <p className="text-vp-sm text-vp-text-muted">{t('loading')}</p>
  } else {
    body = (
      <>
        <div className="flex flex-wrap gap-2">
          {token !== null ? (
            <Button
              variant="primary"
              testId="widget-new-idea"
              onClick={() => setView({ kind: 'new' })}
            >
              {t('newIdea')}
            </Button>
          ) : (
            <Button
              variant="primary"
              testId="widget-open-board"
              onClick={() => openInNewTab(boardUrl)}
            >
              {t('openBoard')} <ExternalLink size={14} aria-hidden="true" />
            </Button>
          )}
        </div>
        {token === null && <p className="text-vp-xs text-vp-text-muted">{t('readOnlyHint')}</p>}
        {ideas.length === 0 ? (
          <p className="text-vp-sm text-vp-text-muted">{t('empty')}</p>
        ) : (
          <ul className="flex flex-col divide-y divide-vp-rule">
            {ideas.map((idea) => {
              const summary = summarizeVotes(idea.up_count, idea.down_count)
              return (
                <li key={idea.id} className="flex items-start gap-3 py-3">
                  <div className="flex flex-col items-center">
                    <button
                      type="button"
                      aria-label={t('upvote')}
                      aria-pressed={idea.my_vote === 'up'}
                      className={`rounded p-0.5 ${idea.my_vote === 'up' ? 'text-vp-vote-up' : 'text-vp-text-muted hover:text-vp-title'}`}
                      onClick={() => handleVote(idea, 'up')}
                    >
                      <ChevronUp size={18} aria-hidden="true" />
                    </button>
                    <span className="text-vp-sm font-semibold tabular-nums">
                      {idea.up_count - idea.down_count}
                    </span>
                    <button
                      type="button"
                      aria-label={t('downvote')}
                      aria-pressed={idea.my_vote === 'down'}
                      className={`rounded p-0.5 ${idea.my_vote === 'down' ? 'text-vp-vote-down' : 'text-vp-text-muted hover:text-vp-title'}`}
                      onClick={() => handleVote(idea, 'down')}
                    >
                      <ChevronDown size={18} aria-hidden="true" />
                    </button>
                  </div>
                  <button
                    type="button"
                    className="min-w-0 flex-1 text-left"
                    onClick={() => setView({ kind: 'idea', id: idea.id })}
                  >
                    <span className="block text-vp-sm font-medium text-pretty">{idea.title}</span>
                    <span className="mt-0.5 flex items-center gap-1 text-vp-xs text-vp-text-muted">
                      <MessageSquare size={12} aria-hidden="true" />
                      {t(idea.comment_count === 1 ? 'commentSingular' : 'comments', {
                        count: idea.comment_count,
                      })}
                    </span>
                    {/* Approval is secondary to the net value above: a share of the up/down votes, never a verdict. */}
                    {summary.approvalRounded !== null && (
                      <span
                        className="mt-0.5 block text-vp-xs text-vp-text-muted"
                        data-testid={`widget-idea-${idea.id}-approval`}
                      >
                        {t('approval', { value: summary.approvalRounded })}
                      </span>
                    )}
                    {summary.fewVotes && (
                      <span
                        className="mt-0.5 block text-vp-xs text-vp-text-muted"
                        data-testid={`widget-idea-${idea.id}-few`}
                      >
                        {t('fewVotes')}
                      </span>
                    )}
                  </button>
                </li>
              )
            })}
          </ul>
        )}
      </>
    )
  }

  // Board colours the plan lets the widget follow (validated in parseWidgetConfig).
  const themeStyle =
    config.theme == null
      ? undefined
      : brandingStyle({
          primary_color: config.theme.primary_color ?? null,
          secondary_color: config.theme.secondary_color ?? null,
        })

  return (
    <div className="flex min-h-screen flex-col bg-vp-surface" style={themeStyle}>
      {header}
      <main className="flex flex-1 flex-col gap-3 overflow-y-auto px-4 py-3">
        {error !== null && <InlineNotice tone="error">{error}</InlineNotice>}
        {body}
      </main>
    </div>
  )
}

interface IdeaViewProps {
  base: string
  id: number
  token: string | null
  t: WidgetT
  onBack: () => void
  onOpen: () => void
  runIdentified: (action: (token: string) => Promise<void>) => Promise<void>
}

function IdeaView({ base, id, token, t, onBack, onOpen, runIdentified }: IdeaViewProps) {
  const [data, setData] = useState<{ idea: api.WidgetIdea; comments: api.WidgetComment[] } | null>(
    null,
  )
  const [failed, setFailed] = useState(false)
  const [comment, setComment] = useState('')

  useEffect(() => {
    let cancelled = false
    api
      .getIdea(base, id)
      .then((result) => {
        if (!cancelled) setData(result)
      })
      .catch(() => {
        if (!cancelled) setFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [base, id])

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    if (comment.trim() === '') return
    void runIdentified(async (tk) => {
      await api.comment(base, tk, id, comment)
      setComment('')
      setData(await api.getIdea(base, id))
    })
  }

  return (
    <div className="flex flex-col gap-3">
      <button
        type="button"
        className="flex items-center gap-1 self-start text-vp-sm"
        onClick={onBack}
      >
        <ArrowLeft size={14} aria-hidden="true" /> {t('back')}
      </button>
      {failed && <InlineNotice tone="error">{t('loadFailed')}</InlineNotice>}
      {data === null && !failed && <p className="text-vp-sm text-vp-text-muted">{t('loading')}</p>}
      {data !== null && (
        <>
          <h2 className="text-vp-md font-semibold text-pretty">{data.idea.title}</h2>
          {/* Plaintext only — React escapes; never rendered as HTML (shared-origin invariant). */}
          <p className="whitespace-pre-line text-vp-sm">{data.idea.body}</p>
          <ul className="flex flex-col gap-2 border-t border-vp-rule pt-3">
            {data.comments.map((c) => (
              <li key={c.id} className="whitespace-pre-line text-vp-sm">
                {c.body}
              </li>
            ))}
          </ul>
          {token !== null ? (
            <form onSubmit={submit} className="flex flex-col gap-2">
              <TextArea
                testId="widget-comment"
                label={t('commentLabel')}
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                rows={3}
              />
              <Button
                type="submit"
                variant="primary"
                testId="widget-comment-submit"
                className="self-start"
              >
                {t('commentSubmit')}
              </Button>
            </form>
          ) : (
            <Button
              variant="secondary"
              testId="widget-open-idea"
              className="self-start"
              onClick={onOpen}
            >
              {t('openIdea')} <ExternalLink size={14} aria-hidden="true" />
            </Button>
          )}
        </>
      )}
    </div>
  )
}

interface NewIdeaViewProps {
  base: string
  t: WidgetT
  onBack: () => void
  runIdentified: (action: (token: string) => Promise<void>) => Promise<void>
}

function NewIdeaView({ base, t, onBack, runIdentified }: NewIdeaViewProps) {
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [formAt, setFormAt] = useState<string | null>(null)
  // Set once submitted; 'pending' when the board pre-moderates new ideas.
  const [done, setDone] = useState<'published' | 'pending' | null>(null)

  // Time-trap stamp from the public submit form — the server rejects a
  // submission that arrives too quickly after it (bot heuristic).
  useEffect(() => {
    api
      .submitStamp(base)
      .then((result) => setFormAt(result.form_at))
      .catch(() => setFormAt(null))
  }, [base])

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    if (formAt === null) return
    void runIdentified(async (tk) => {
      const result = await api.submitIdea(base, tk, title, body, formAt)
      setDone(result.moderation_state === 'pending' ? 'pending' : 'published')
    })
  }

  return (
    <div className="flex flex-col gap-3">
      <button
        type="button"
        className="flex items-center gap-1 self-start text-vp-sm"
        onClick={onBack}
      >
        <ArrowLeft size={14} aria-hidden="true" /> {t('back')}
      </button>
      {done !== null ? (
        <InlineNotice tone="success">
          {t(done === 'pending' ? 'submittedPending' : 'submitted')}
        </InlineNotice>
      ) : (
        <form onSubmit={submit} className="flex flex-col gap-3">
          <TextField
            testId="widget-title"
            label={t('titleLabel')}
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            required
          />
          <TextArea
            testId="widget-body"
            label={t('bodyLabel')}
            value={body}
            onChange={(e) => setBody(e.target.value)}
            rows={4}
          />
          <Button
            type="submit"
            variant="primary"
            testId="widget-submit-idea"
            className="self-start"
            disabled={formAt === null}
          >
            {t('submit')}
          </Button>
        </form>
      )}
    </div>
  )
}
