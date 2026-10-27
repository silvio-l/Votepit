/**
 * BoardQrPanel — a collapsed "Show QR code" disclosure for a board's public
 * link (board settings, setup wizard's ready step). The toggle is handed to
 * `renderField` so it sits inside the link field next to "Copy"; the code
 * opens below. Nothing is generated or rendered until it is opened, so the
 * share flows stay unchanged by default.
 *
 * Generation is local (lib/boardQr.ts): inline SVG on screen, SVG and PNG
 * downloads as data: URLs, no external service. Callers only render this for
 * non-private boards (qrAllowedFor) and pass the public board URL.
 */

import { Button, ButtonLink } from '@votepit/ui/ds'
import { Download, Printer } from 'lucide-react'
import { type ReactNode, useEffect, useId, useMemo, useState } from 'react'
import { qrPngDataUrl, qrSvgDataUrl, qrSvgPath, qrViewBoxSize } from '../lib/boardQr'
import { useT } from '../lib/i18n/context'
import { RouterButtonLink } from './RouterButtonLink'

/** The code itself as inline SVG — presentation attributes only (strict CSP). */
export function QrCodeSvg({
  url,
  label,
  className,
}: {
  url: string
  label: string
  className?: string
}) {
  const { d, n } = useMemo(() => ({ d: qrSvgPath(url), n: qrViewBoxSize(url) }), [url])
  return (
    <svg
      role="img"
      aria-label={label}
      viewBox={`0 0 ${n} ${n}`}
      shapeRendering="crispEdges"
      className={className}
    >
      {/* Fixed black on white (exception): QR codes must scan in both schemes. */}
      <rect width={n} height={n} fill="#ffffff" />
      <path d={d} fill="#000000" />
    </svg>
  )
}

interface BoardQrPanelProps {
  /** The public board URL — never anything session- or token-bound. */
  url: string
  /** Base name for downloaded files (the board slug). */
  fileName: string
  /** Route to the printable poster; omitted where no poster page is reachable. */
  posterHref?: string
}

interface BoardQrPanelWithFieldProps extends BoardQrPanelProps {
  /** Renders the link field; place the given QR toggle as one of its actions. */
  renderField: (qrToggle: ReactNode) => ReactNode
}

export function BoardQrPanel({
  url,
  fileName,
  posterHref,
  renderField,
}: BoardQrPanelWithFieldProps) {
  const t = useT('boardQr')
  const [open, setOpen] = useState(false)
  const panelId = useId()

  return (
    <div className="flex flex-col gap-3">
      {renderField(
        <Button
          type="button"
          variant="secondary"
          testId="board-qr-toggle"
          aria-expanded={open}
          aria-controls={open ? panelId : undefined}
          onClick={() => setOpen((v) => !v)}
          className="shrink-0"
        >
          {open ? t('hide') : t('show')}
        </Button>,
      )}
      {open && <QrDetails id={panelId} url={url} fileName={fileName} posterHref={posterHref} />}
    </div>
  )
}

function QrDetails({ id, url, fileName, posterHref }: BoardQrPanelProps & { id: string }) {
  const t = useT('boardQr')
  const [pngHref, setPngHref] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    qrPngDataUrl(url)
      .then((href) => {
        if (!cancelled) setPngHref(href)
      })
      // No PNG where the environment cannot render one — SVG still works.
      .catch(() => {})
    return () => {
      cancelled = true
    }
  }, [url])

  const svgHref = useMemo(() => qrSvgDataUrl(url), [url])

  return (
    <div
      id={id}
      className="flex flex-col gap-4 rounded-vp-lg border border-vp-border-subtle bg-vp-surface p-4 sm:flex-row sm:items-start"
    >
      <QrCodeSvg
        url={url}
        label={t('alt', { url })}
        className="h-40 w-40 shrink-0 rounded-vp-sm border border-vp-border-subtle"
      />
      <div className="flex min-w-0 flex-col gap-3">
        <p className="text-vp-xs text-vp-text-secondary">{t('hint')}</p>
        <div className="flex flex-wrap gap-2">
          <ButtonLink
            href={svgHref}
            download={`${fileName}-qr.svg`}
            variant="text"
            testId="board-qr-download-svg"
          >
            <Download size={14} aria-hidden="true" />
            {t('downloadSvg')}
          </ButtonLink>
          {pngHref !== null && (
            <ButtonLink
              href={pngHref}
              download={`${fileName}-qr.png`}
              variant="text"
              testId="board-qr-download-png"
            >
              <Download size={14} aria-hidden="true" />
              {t('downloadPng')}
            </ButtonLink>
          )}
          {posterHref !== undefined && (
            <ButtonLink
              href={posterHref}
              linkAs={RouterButtonLink}
              variant="text"
              testId="board-qr-open-poster"
            >
              <Printer size={14} aria-hidden="true" />
              {t('openPoster')}
            </ButtonLink>
          )}
        </div>
      </div>
    </div>
  )
}
