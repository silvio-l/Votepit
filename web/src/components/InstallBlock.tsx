import { useCallback, useState } from 'react'

// Matomo: idSite=6 goal, guarded — fires once _paq exists
declare const window: Window & { _paq?: unknown[][] }

interface InstallProps {
  heading: string
  sub: string
  cliLabel: string
  cmd: string
  copy: string
  copied: string
  ftpLabel: string
  ftpText: string
}

interface Props {
  t: {
    install: InstallProps
  }
}

export default function InstallBlock({ t }: Props) {
  const ins = t.install
  const [isCopied, setIsCopied] = useState(false)

  const handleCopy = useCallback(async () => {
    if (!navigator.clipboard) return
    try {
      await navigator.clipboard.writeText(ins.cmd)
      setIsCopied(true)
      if (window._paq) window._paq.push(['trackEvent', 'Engagement', 'Install kopiert', 'install'])
      setTimeout(() => setIsCopied(false), 1600)
    } catch {
      /* clipboard unavailable — leave the command visible to copy manually */
    }
  }, [ins.cmd])

  return (
    <section className="vp-install vp-reveal" aria-labelledby="vp-install-h">
      <h2 className="vp-install-h" id="vp-install-h">
        {ins.heading}
      </h2>
      <p className="vp-install-sub">{ins.sub}</p>

      <div className="vp-cmd">
        <span className="vp-cmd-label">{ins.cliLabel}</span>
        <div className="vp-cmd-row">
          <code className="vp-cmd-code">
            <span className="vp-cmd-prompt">$</span>
            {ins.cmd}
          </code>
          <button
            type="button"
            className={`vp-cmd-copy${isCopied ? ' is-copied' : ''}`}
            onClick={handleCopy}
            aria-label={ins.copy}
          >
            {isCopied ? ins.copied : ins.copy}
          </button>
        </div>
      </div>

      <p className="vp-install-ftp">
        <strong>{ins.ftpLabel}</strong> {ins.ftpText}
      </p>
    </section>
  )
}
