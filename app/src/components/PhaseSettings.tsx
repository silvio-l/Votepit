import { Badge, type BadgeTone, Button, InlineNotice, Section, TextField } from '@votepit/ui/ds'
import { CalendarClock } from 'lucide-react'
import { useState } from 'react'
import type {
  ApiError,
  BoardPhaseBounds,
  BoardPhasesSettings,
  IdeaStatus,
  PhaseState,
} from '../lib/api'
import { getAdminPhases, saveAdminPhases } from '../lib/api'
import { browserTimeZone, fromLocalInput, toLocalInput } from '../lib/boardPhases'
import { statusLabel } from '../lib/boardStatuses'
import { useI18n, useT } from '../lib/i18n/context'

type Field = keyof BoardPhaseBounds

const PHASES: { phase: 'submissions' | 'voting'; opens: Field; closes: Field }[] = [
  { phase: 'submissions', opens: 'submissions_opens_at', closes: 'submissions_closes_at' },
  { phase: 'voting', opens: 'voting_opens_at', closes: 'voting_closes_at' },
]

const EMPTY: Record<Field, string> = {
  submissions_opens_at: '',
  submissions_closes_at: '',
  voting_opens_at: '',
  voting_closes_at: '',
}

const STATE_TONE: Record<PhaseState, BadgeTone> = {
  open: 'success',
  upcoming: 'info',
  closed: 'warning',
}

function draftOf(s: BoardPhasesSettings): Record<Field, string> {
  return {
    submissions_opens_at: toLocalInput(s.submissions_opens_at),
    submissions_closes_at: toLocalInput(s.submissions_closes_at),
    voting_opens_at: toLocalInput(s.voting_opens_at),
    voting_closes_at: toLocalInput(s.voting_closes_at),
  }
}

/**
 * Board settings → deadlines (broaden-use-cases issue 04). Optional time
 * windows for submissions and voting, available on every plan. Inputs are
 * in the admin's own (explicitly named) time zone and sent with an offset;
 * the server stores UTC and computes the effective state with its clock —
 * shown per phase, combined with the statuses that close voting anyway.
 * accountAdmin only — the same gate as the rest of AdminPage.
 */
export function PhaseSettings({ boardSlug }: { boardSlug: string }) {
  const t = useT('adminPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [settings, setSettings] = useState<BoardPhasesSettings | null>(null)
  const [draft, setDraft] = useState<Record<Field, string>>(EMPTY)
  const [loadError, setLoadError] = useState(false)
  const [saving, setSaving] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<Partial<Record<Field, string>>>({})
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  const apply = (next: BoardPhasesSettings) => {
    setSettings(next)
    setDraft(draftOf(next))
  }

  // Loaded on demand like the statuses: most boards never get deadlines,
  // so the settings page does not pay a request for them on every visit.
  const [expanded, setExpanded] = useState(false)

  const handleExpand = () => {
    setExpanded(true)
    setLoadError(false)
    getAdminPhases(boardSlug)
      .then((res) => {
        if (res?.phases?.submissions === undefined || res.phases.voting === undefined) {
          setLoadError(true)
          return
        }
        apply(res)
      })
      .catch(() => setLoadError(true))
  }

  const save = async (next: Record<Field, string>) => {
    setSaving(true)
    setError(null)
    setSaved(false)
    setFieldErrors({})
    try {
      apply(
        await saveAdminPhases(boardSlug, {
          submissions_opens_at: fromLocalInput(next.submissions_opens_at),
          submissions_closes_at: fromLocalInput(next.submissions_closes_at),
          voting_opens_at: fromLocalInput(next.voting_opens_at),
          voting_closes_at: fromLocalInput(next.voting_closes_at),
        }),
      )
      setSaved(true)
    } catch (err) {
      const fields = (err as ApiError)?.payload?.fields ?? {}
      const mapped: Partial<Record<Field, string>> = {}
      for (const { opens, closes } of PHASES) {
        if (fields[opens] !== undefined) mapped[opens] = t('phasesInvalid')
        if (fields[closes] !== undefined) {
          mapped[closes] =
            next[opens] !== '' && next[closes] !== '' ? t('phasesOrderError') : t('phasesInvalid')
        }
      }
      setFieldErrors(mapped)
      if (Object.keys(mapped).length === 0) setError(t('phasesSaveError'))
    } finally {
      setSaving(false)
    }
  }

  const clearPhase = (opens: Field, closes: Field) => {
    const next = { ...draft, [opens]: '', [closes]: '' }
    setDraft(next)
    void save(next)
  }

  const stateLabel: Record<PhaseState, string> = {
    open: t('phasesStateOpen'),
    upcoming: t('phasesStateUpcoming'),
    closed: t('phasesStateClosed'),
  }
  const statusDefaults: Record<IdeaStatus, string> = {
    open: tCommon('status.open'),
    planned: tCommon('status.planned'),
    in_progress: tCommon('status.inProgress'),
    done: tCommon('status.done'),
    declined: tCommon('status.declined'),
    archived: tCommon('status.archived'),
  }

  return (
    <Section
      testId="phase-settings-section"
      title={t('phasesHeading')}
      icon={<CalendarClock size={16} />}
      description={t('phasesSubtitle')}
    >
      {!expanded ? (
        <Button
          testId="phase-settings-manage"
          type="button"
          variant="secondary"
          onClick={handleExpand}
        >
          {t('phasesManage')}
        </Button>
      ) : loadError ? (
        <InlineNotice tone="error" testId="phase-settings-load-error">
          {t('phasesLoadError')}
        </InlineNotice>
      ) : settings === null ? (
        <p className="text-vp-sm text-vp-text-muted">{t('loading')}</p>
      ) : (
        <div className="flex flex-col gap-ds-md">
          <p className="text-vp-xs text-vp-text-muted">
            {t('phasesTimeZone', { zone: browserTimeZone() })}
          </p>
          {PHASES.map(({ phase, opens, closes }) => {
            const state = settings.phases[phase].state
            const closedStatuses = phase === 'voting' ? settings.statuses_closed_for_votes : []
            return (
              <fieldset
                key={phase}
                className="flex flex-col gap-ds-sm"
                data-testid={`phase-${phase}`}
              >
                <legend className="flex flex-wrap items-center gap-ds-xs mb-ds-xs">
                  <span className="text-vp-sm font-semibold text-vp-title">
                    {phase === 'submissions' ? t('phasesSubmissions') : t('phasesVoting')}
                  </span>
                  <Badge tone={STATE_TONE[state]} dot>
                    {t('phasesEffective', { state: stateLabel[state] })}
                  </Badge>
                </legend>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-ds-sm">
                  <TextField
                    className="max-w-ds-field"
                    testId={`phase-${phase}-opens`}
                    type="datetime-local"
                    id={`phase-${opens}`}
                    label={t('phasesOpensAt')}
                    value={draft[opens]}
                    onChange={(e) => {
                      const v = e.target.value
                      setDraft((prev) => ({ ...prev, [opens]: v }))
                    }}
                    error={fieldErrors[opens]}
                    disabled={saving}
                  />
                  <TextField
                    className="max-w-ds-field"
                    testId={`phase-${phase}-closes`}
                    type="datetime-local"
                    id={`phase-${closes}`}
                    label={t('phasesClosesAt')}
                    value={draft[closes]}
                    onChange={(e) => {
                      const v = e.target.value
                      setDraft((prev) => ({ ...prev, [closes]: v }))
                    }}
                    error={fieldErrors[closes]}
                    disabled={saving}
                  />
                </div>
                {closedStatuses.length > 0 && (
                  <p className="text-vp-xs text-vp-text-muted">
                    {t('phasesVotingStatusNote', {
                      list: closedStatuses
                        .map((s) => statusLabel([s], s.key, s.category, language, statusDefaults))
                        .join(', '),
                    })}
                  </p>
                )}
                {(draft[opens] !== '' || draft[closes] !== '') && (
                  <div>
                    <Button
                      testId={`phase-${phase}-clear`}
                      type="button"
                      variant="secondary"
                      onClick={() => clearPhase(opens, closes)}
                      disabled={saving}
                    >
                      {t('phasesClear')}
                    </Button>
                  </div>
                )}
              </fieldset>
            )
          })}
          <p className="text-vp-xs text-vp-text-muted">{t('phasesExemptNote')}</p>
          <div>
            <Button
              testId="phase-settings-save"
              type="button"
              onClick={() => void save(draft)}
              loading={saving}
            >
              {t('phasesSave')}
            </Button>
          </div>
          {error !== null && (
            <InlineNotice tone="error" testId="phase-settings-error">
              {error}
            </InlineNotice>
          )}
          {saved && (
            <InlineNotice tone="success" testId="phase-settings-saved">
              {t('phasesSaved')}
            </InlineNotice>
          )}
        </div>
      )}
    </Section>
  )
}
