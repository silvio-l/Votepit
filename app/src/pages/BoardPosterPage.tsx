/**
 * BoardPosterPage — /admin/boards/:boardSlug/poster (broaden-use-cases issue 09)
 *
 * A print-friendly A4 portrait poster for a board's public link: optional
 * logo, board name, a neutral call to action, the QR code and the URL as
 * text. Printing goes through the browser's own dialog (window.print), no
 * PDF dependency; index.css puts `.vp-poster` on a named A4 portrait page
 * and hides everything around it, and the toolbar is screen-only.
 *
 * Client-side route only: the SPA shell is served for any HTML navigation,
 * and the only data read is the existing admin branding endpoint (accountAdmin
 * AuthZ server-side — anon → login, non-admin → error). Private boards get no
 * QR code (their link works for signed-in members only).
 */

import { Button, ButtonLink, ErrorState, InlineNotice, LoadingState } from '@votepit/ui/ds'
import { ArrowLeft } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { QrCodeSvg } from '../components/BoardQrPanel'
import { RouterButtonLink } from '../components/RouterButtonLink'
import { accountPath, fullBoardUrl } from '../lib/accountContext'
import type { ApiError, BrandingData } from '../lib/api'
import { getAdminBranding } from '../lib/api'
import { boardLogoSrc } from '../lib/boardLogo'
import { qrAllowedFor } from '../lib/boardQr'
import { posterAccent } from '../lib/branding'
import { useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'loading' }
  | { phase: 'error'; denied: boolean }
  | { phase: 'ready'; branding: BrandingData }

export default function BoardPosterPage() {
  const { boardSlug = '' } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()
  const t = useT('boardQr')
  const tCommon = useT('common')
  const [state, setState] = useState<PageState>({ phase: 'loading' })

  useEffect(() => {
    let cancelled = false
    getAdminBranding(boardSlug)
      .then((branding) => {
        if (!cancelled) setState({ phase: 'ready', branding })
      })
      .catch((err: ApiError) => {
        if (cancelled) return
        if (err?.name === 'ApiError' && err.status === 401) {
          navigate(
            `/login?r=${encodeURIComponent(accountPath(`/admin/boards/${boardSlug}/poster`))}`,
            { replace: true },
          )
          return
        }
        setState({ phase: 'error', denied: err?.name === 'ApiError' && err.status === 403 })
      })
    return () => {
      cancelled = true
    }
  }, [boardSlug, navigate])

  const backLink = (
    <ButtonLink
      href={accountPath(`/admin/boards/${boardSlug}`)}
      linkAs={RouterButtonLink}
      variant="text"
      testId="board-poster-back"
    >
      <ArrowLeft size={14} aria-hidden="true" />
      {t('posterBack')}
    </ButtonLink>
  )

  if (state.phase === 'loading') {
    return (
      <div className="vp-container py-10">
        <LoadingState label={tCommon('state.loading')} rows={5} />
      </div>
    )
  }

  if (state.phase === 'error') {
    return (
      <div className="vp-container py-10">
        <ErrorState
          title={tCommon(state.denied ? 'state.accessDeniedTitle' : 'state.errorTitle')}
          description={tCommon('state.error')}
          kind={state.denied ? 'denied' : 'failure'}
          action={backLink}
        />
      </div>
    )
  }

  const { branding } = state
  const url = fullBoardUrl(branding.board_slug)
  // Board accent on white paper (owner 26a): title text and a rule; QR stays black on white.
  const accent = posterAccent(branding.primary_color)

  if (!qrAllowedFor(branding.visibility)) {
    return (
      <div className="vp-container flex flex-col items-start gap-4 py-10">
        {backLink}
        <InlineNotice tone="info">{t('posterPrivate')}</InlineNotice>
      </div>
    )
  }

  return (
    <div className="vp-poster-root print:p-0">
      <div
        data-poster-toolbar
        className="vp-container flex flex-wrap items-center justify-between gap-3 py-4 print:hidden"
      >
        {backLink}
        <div className="flex flex-wrap items-center gap-3">
          <p className="text-vp-xs text-vp-text-secondary">{t('posterPrintHint')}</p>
          <Button
            testId="board-poster-print"
            type="button"
            variant="primary"
            onClick={() => window.print()}
          >
            {t('posterPrint')}
          </Button>
        </div>
      </div>

      <main className="flex justify-center px-4 pb-10 print:p-0">
        {/* Printed poster: paper in both schemes (fixed exception, owner 26a). */}
        <section
          aria-label={t('posterRegion')}
          data-testid="board-poster-sheet"
          className="vp-poster flex aspect-[210/297] w-full max-w-[210mm] flex-col items-center justify-center gap-[8mm] border border-vp-border-subtle bg-white px-[14mm] py-[16mm] text-center text-black print:aspect-auto print:h-[270mm] print:max-w-none print:border-0 print:px-0 print:py-0"
        >
          {boardLogoSrc(branding.board_slug, branding) !== undefined && (
            <img
              src={boardLogoSrc(branding.board_slug, branding)}
              alt=""
              className="max-h-[22mm] max-w-[60mm] object-contain"
            />
          )}
          <h1
            data-testid="board-poster-title"
            style={accent ? { color: accent.text } : undefined}
            className="font-archivo text-[clamp(1.75rem,6vw,2.75rem)] font-bold leading-tight text-balance break-words print:text-[34pt]"
          >
            {branding.board_name}
          </h1>
          {accent && (
            <div
              data-testid="board-poster-accent-rule"
              aria-hidden="true"
              style={{ backgroundColor: accent.rule }}
              className="h-[1.2mm] w-[40mm] print:[print-color-adjust:exact]"
            />
          )}
          <p className="text-[clamp(1.1rem,3.5vw,1.5rem)] text-pretty print:text-[18pt]">
            {t('posterCallToAction')}
          </p>
          <QrCodeSvg
            url={url}
            label={t('alt', { url })}
            className="aspect-square w-[min(70%,110mm)] print:w-[110mm]"
          />
          <p className="text-vp-sm print:text-[12pt]">{t('posterScanHint')}</p>
          <p className="font-mono-num text-[clamp(0.85rem,2.5vw,1.1rem)] break-all print:text-[13pt]">
            {url}
          </p>
        </section>
      </main>
    </div>
  )
}
