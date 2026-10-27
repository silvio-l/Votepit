import { Section, Switch } from '@votepit/ui/ds'
import { Bell } from 'lucide-react'
import { useEffect, useState } from 'react'
import {
  getOperatorNudges,
  type OperatorNudgeKey,
  type OperatorNudgePrefs,
  saveOperatorNudges,
} from '../lib/api/operatorNudges'
import { useT } from '../lib/i18n/context'

type RowLabelKey = 'onboardingLabel' | 'quietBoardLabel' | 'planLimitLabel'
type RowHintKey = 'onboardingHint' | 'quietBoardHint' | 'planLimitHint'

const ROWS: { key: OperatorNudgeKey; testId: string; label: RowLabelKey; hint: RowHintKey }[] = [
  {
    key: 'onboarding',
    testId: 'profile-nudge-onboarding',
    label: 'onboardingLabel',
    hint: 'onboardingHint',
  },
  {
    key: 'quiet_board',
    testId: 'profile-nudge-quiet-board',
    label: 'quietBoardLabel',
    hint: 'quietBoardHint',
  },
  {
    key: 'plan_limit',
    testId: 'profile-nudge-plan-limit',
    label: 'planLimitLabel',
    hint: 'planLimitHint',
  },
]

/**
 * Owner-facing switches for the operator nudges (onboarding, quiet board and,
 * on Cloud only, the plan-limit heads-up). Each switch is saved on its own;
 * a failed save rolls the switch back.
 */
export function OperatorNudgeSection({ showPlanLimit }: { showPlanLimit: boolean }) {
  const t = useT('operatorNudges')
  const [prefs, setPrefs] = useState<OperatorNudgePrefs | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    getOperatorNudges()
      .then(setPrefs)
      .catch(() => setPrefs(null))
  }, [])

  if (prefs === null) return null

  const rows = ROWS.filter((row) => row.key !== 'plan_limit' || showPlanLimit)

  const toggle = async (key: OperatorNudgeKey, next: boolean) => {
    const previous = prefs
    setPrefs({ ...prefs, [key]: next })
    setSaving(true)
    try {
      const saved = await saveOperatorNudges({ [key]: next })
      setPrefs({
        onboarding: saved.onboarding,
        quiet_board: saved.quiet_board,
        plan_limit: saved.plan_limit,
      })
    } catch {
      setPrefs(previous)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Section
      testId="profile-operator-nudges"
      title={t('heading')}
      icon={<Bell size={16} />}
      description={t('hint')}
    >
      <div className="grid grid-cols-1 gap-y-ds-md">
        {rows.map((row) => (
          <Switch
            key={row.key}
            testId={row.testId}
            label={t(row.label)}
            hint={t(row.hint)}
            checked={prefs[row.key]}
            onChange={(next) => void toggle(row.key, next)}
            disabled={saving}
          />
        ))}
      </div>
    </Section>
  )
}
