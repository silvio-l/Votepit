/**
 * UploadTermsGate — the owner/admin consent to the upload clause (AGB 19) in front of the first
 * board image upload (logo, cover, favicon, share image). The server refuses an upload without
 * a consent for the current version (403 upload_terms_required); this gate asks first, so the
 * owner does not meet that refusal in normal use. Once accepted for the account, uploads run
 * directly until the terms version changes. The payload carries upload_terms only when a terms
 * version is configured (Votepit Cloud); without it (Community) no dialog is shown at all. The summary is shown in full: the clause is not live
 * yet, so there is no link to a published text.
 */

import { Button, Checkbox, InlineNotice, Sheet } from '@votepit/ui/ds'
import { type ReactNode, useId, useState } from 'react'
import { acceptUploadTerms } from '../lib/api/boardAssets'
import { useI18n, useT } from '../lib/i18n/context'
import type { BoardAssetsState } from './BoardAssetsSettings'

const POINT_KEYS = [
  'uploadTermsPoint1',
  'uploadTermsPoint2',
  'uploadTermsPoint3',
  'uploadTermsPoint4',
  'uploadTermsPoint5',
  'uploadTermsPoint6',
] as const

export interface UploadTermsGateResult {
  /** Runs the upload now when the account accepted the current terms, otherwise asks first. */
  guard: (action: () => Promise<void>) => void
  /** The consent dialog; render it once next to the upload controls. */
  dialog: ReactNode
}

/**
 * @param boardSlug the board whose assets are uploaded (the accept call is board-scoped)
 * @param assets the shared assets state; its upload_terms block decides whether to ask
 * @param testId prefix for the dialog's test ids (`<testId>`, `-checkbox`, `-accept`, …)
 */
export function useUploadTermsGate(
  boardSlug: string,
  assets: BoardAssetsState,
  testId: string,
): UploadTermsGateResult {
  const t = useT('boardAssets')
  const { language } = useI18n()
  const descriptionId = useId()
  const [pending, setPending] = useState<{ run: () => Promise<void> } | null>(null)
  const [checked, setChecked] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // Ask only when the payload delivers a terms version and the account has not accepted it yet.
  const termsPending = assets.data?.upload_terms !== undefined && !assets.data.upload_terms.accepted

  const guard = (action: () => Promise<void>) => {
    if (!termsPending) {
      void action()
      return
    }
    setChecked(false)
    setError(null)
    setPending({ run: action })
  }

  const close = () => {
    if (busy) return
    setPending(null)
    setChecked(false)
    setError(null)
  }

  const confirm = async () => {
    const action = pending
    if (action === null || !checked || busy) return
    setBusy(true)
    setError(null)
    try {
      await acceptUploadTerms(boardSlug, language)
    } catch {
      setError(t('uploadTermsFailed'))
      setBusy(false)
      return
    }
    setPending(null)
    setChecked(false)
    setBusy(false)
    await assets.reload()
    await action.run()
  }

  const dialog = (
    <Sheet
      open={pending !== null}
      onClose={close}
      title={t('uploadTermsTitle')}
      closeLabel={t('uploadTermsCancel')}
      testId={testId}
      describedBy={descriptionId}
    >
      <div className="flex flex-col gap-ds-md">
        <p id={descriptionId} className="text-vp-sm text-vp-ink">
          {t('uploadTermsIntro')}
        </p>
        <ul className="flex flex-col gap-ds-xs pl-5 list-disc text-vp-sm text-vp-ink">
          {POINT_KEYS.map((key) => (
            <li key={key}>{t(key)}</li>
          ))}
        </ul>
        <Checkbox
          testId={`${testId}-checkbox`}
          label={t('uploadTermsCheckbox')}
          checked={checked}
          onChange={setChecked}
          disabled={busy}
        />
        {error !== null && (
          <InlineNotice tone="error" testId={`${testId}-error`}>
            {error}
          </InlineNotice>
        )}
        <div className="flex flex-wrap justify-end gap-ds-sm">
          <Button
            testId={`${testId}-cancel`}
            type="button"
            variant="secondary"
            disabled={busy}
            onClick={close}
          >
            {t('uploadTermsCancel')}
          </Button>
          <Button
            testId={`${testId}-accept`}
            type="button"
            variant="primary"
            disabled={!checked || busy}
            loading={busy}
            onClick={() => void confirm()}
          >
            {t('uploadTermsAccept')}
          </Button>
        </div>
      </div>
    </Sheet>
  )

  return { guard, dialog }
}
