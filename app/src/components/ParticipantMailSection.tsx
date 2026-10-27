import { Button, InlineNotice, Section, Select, Switch } from '@votepit/ui/ds'
import { Mail } from 'lucide-react'
import { useEffect, useState } from 'react'
import {
  getParticipantMailPreferences,
  type ParticipantMailCadence,
  type ParticipantMailCategoryKey,
  type ParticipantMailPreferences,
  saveParticipantMailPreferences,
} from '../lib/api/participantMail'
import { useT } from '../lib/i18n/context'

type Draft = Record<ParticipantMailCategoryKey, ParticipantMailCadence>

const CADENCE_LABEL_KEY: Record<ParticipantMailCadence, string> = {
  instant: 'cadenceInstant',
  daily: 'cadenceDaily',
  weekly: 'cadenceWeekly',
  off: 'cadenceOff',
}

function toDraft(prefs: ParticipantMailPreferences): Draft {
  return {
    my_ideas: prefs.categories.my_ideas.cadence,
    participated: prefs.categories.participated.cadence,
    new_ideas: prefs.categories.new_ideas.cadence,
  }
}

/**
 * Participant-mail cadence per category (close-the-loop re-engagement). Visible
 * to every signed-in user. The new-ideas digest is an explicit opt-in: a switch
 * that is off by default and only ever means "weekly" or "off" on the server.
 * Changes are saved together with the section's save button; only the
 * categories that actually changed are sent.
 */
export function ParticipantMailSection() {
  const t = useT('participantMail')
  const [prefs, setPrefs] = useState<ParticipantMailPreferences | null>(null)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    getParticipantMailPreferences()
      .then((data) => {
        if (cancelled) return
        setPrefs(data)
        setDraft(toDraft(data))
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [])

  if (loadError) {
    return (
      <Section
        testId="profile-participant-mail-section"
        title={t('heading')}
        icon={<Mail size={16} />}
      >
        <InlineNotice tone="error">{t('loadError')}</InlineNotice>
      </Section>
    )
  }

  if (prefs === null || draft === null) return null

  const changed = (Object.keys(draft) as ParticipantMailCategoryKey[]).filter(
    (key) => draft[key] !== prefs.categories[key].cadence,
  )

  const update = (key: ParticipantMailCategoryKey, cadence: ParticipantMailCadence) => {
    setSaved(false)
    setSaveError(null)
    setDraft({ ...draft, [key]: cadence })
  }

  const handleSave = async () => {
    if (changed.length === 0) return
    const updates = Object.fromEntries(changed.map((key) => [key, draft[key]])) as Partial<Draft>
    setSaving(true)
    setSaveError(null)
    try {
      const next = await saveParticipantMailPreferences(updates)
      setPrefs(next)
      setDraft(toDraft(next))
      setSaved(true)
    } catch {
      setSaveError(t('saveError'))
    } finally {
      setSaving(false)
    }
  }

  const selectOptions = (key: ParticipantMailCategoryKey) =>
    prefs.categories[key].allowed.map((cadence) => (
      <option key={cadence} value={cadence}>
        {t(CADENCE_LABEL_KEY[cadence])}
      </option>
    ))

  return (
    <Section
      testId="profile-participant-mail-section"
      title={t('heading')}
      icon={<Mail size={16} />}
      description={t('hint')}
      footer={
        <Button
          testId="profile-participant-mail-save"
          type="button"
          variant="primary"
          onClick={() => void handleSave()}
          disabled={saving || changed.length === 0}
          loading={saving}
        >
          {saving ? t('saving') : t('save')}
        </Button>
      }
    >
      <div className="grid grid-cols-1 gap-x-ds-md gap-y-ds-md">
        <Select
          testId="profile-participant-mail-my-ideas"
          label={t('myIdeasLabel')}
          hint={t('myIdeasHint')}
          value={draft.my_ideas}
          onChange={(value) => update('my_ideas', value as ParticipantMailCadence)}
        >
          {selectOptions('my_ideas')}
        </Select>

        <Select
          testId="profile-participant-mail-participated"
          label={t('participatedLabel')}
          hint={t('participatedHint')}
          value={draft.participated}
          onChange={(value) => update('participated', value as ParticipantMailCadence)}
        >
          {selectOptions('participated')}
        </Select>

        <Switch
          testId="profile-participant-mail-new-ideas"
          label={t('newIdeasLabel')}
          hint={t('newIdeasHint')}
          checked={draft.new_ideas === 'weekly'}
          onChange={(next) => update('new_ideas', next ? 'weekly' : 'off')}
          disabled={saving}
        />
      </div>

      {saved && (
        <InlineNotice tone="success" className="mt-ds-md animate-vp-fade-in">
          {t('saved')}
        </InlineNotice>
      )}
      {saveError !== null && (
        <InlineNotice tone="error" className="mt-ds-md">
          {saveError}
        </InlineNotice>
      )}
    </Section>
  )
}
