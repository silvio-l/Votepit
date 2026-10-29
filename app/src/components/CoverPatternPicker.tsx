/**
 * CoverPatternPicker — choose the board's cover pattern (P-28c). The default cover
 * stays the default (null). Paid plans can select; other plans see the same tiles
 * as a preview only, with the upgrade notice.
 */

import { BoardCover, COVER_PATTERNS, type CoverPattern, cx } from '@votepit/ui/ds'
import type { RequiredPlan } from '../lib/entitlements'
import { useT } from '../lib/i18n/context'
import { PlanUpgradeLink } from './PlanUpgradeLink'

export interface CoverPatternPickerProps {
  testId: string
  /** Stored pattern key, or null for the default cover. */
  value: string | null
  onChange: (value: string | null) => void
  allowed: boolean
  requiredPlan?: RequiredPlan | null
  disabled?: boolean
  /** Board accent for the preview tiles (validated upstream). */
  accent?: string | null
}

const OPTIONS: Array<{ key: CoverPattern | null; labelKey: string }> = [
  { key: null, labelKey: 'patternDefault' },
  ...COVER_PATTERNS.map((key) => ({ key, labelKey: `pattern_${key}` })),
]

export function CoverPatternPicker({
  testId,
  value,
  onChange,
  allowed,
  requiredPlan,
  disabled = false,
  accent,
}: CoverPatternPickerProps) {
  const t = useT('boardAssets')
  const selected =
    value !== null && (COVER_PATTERNS as readonly string[]).includes(value) ? value : null
  const locked = !allowed || disabled

  return (
    <div id={testId} className="flex flex-col gap-ds-sm" data-testid={testId}>
      <span className="text-vp-sm font-medium text-vp-title">{t('patternHeading')}</span>
      <span className="text-vp-xs text-vp-text-muted">{t('patternHint')}</span>

      <BoardCover
        accent={accent}
        seed="cover-pattern-preview"
        pattern={selected as CoverPattern | null}
        className="aspect-[3/1] w-full rounded-vp-md border border-vp-border"
        testId={`${testId}-preview`}
      />

      <div
        role="radiogroup"
        aria-label={t('patternHeading')}
        className="grid grid-cols-[repeat(auto-fill,minmax(7rem,1fr))] gap-ds-sm"
      >
        {OPTIONS.map((option) => {
          const isOn = option.key === selected
          return (
            <button
              key={option.key ?? 'default'}
              type="button"
              role="radio"
              aria-checked={isOn}
              aria-label={t(option.labelKey as 'patternDefault')}
              data-testid={`${testId}-option-${option.key ?? 'default'}`}
              disabled={locked}
              onClick={() => onChange(option.key)}
              className={cx(
                'flex min-w-0 flex-col gap-ds-xs rounded-vp-md border p-ds-xs text-left',
                isOn ? 'border-vp-accent' : 'border-vp-border',
                locked ? 'opacity-70' : 'cursor-pointer',
              )}
            >
              <BoardCover
                accent={accent}
                seed="cover-pattern-tile"
                pattern={option.key}
                className="aspect-[3/1] w-full rounded-vp-sm"
              />
              <span className="min-w-0 text-vp-xs text-vp-text break-words hyphens-auto">
                {t(option.labelKey as 'patternDefault')}
              </span>
            </button>
          )
        })}
      </div>

      {!allowed && (
        <p className="text-vp-xs text-vp-text-muted" data-testid={`${testId}-locked`}>
          {t('lockedImages')}{' '}
          <PlanUpgradeLink
            label={t('planUpgradeLinkLabel')}
            feature="cover_pattern"
            requiredPlan={requiredPlan ?? null}
          />
        </p>
      )}
    </div>
  )
}
