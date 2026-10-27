import { Icon, type IconName } from './icons'

export type TrustKind = 'hosting' | 'contract' | 'processing'

const KIND_ICON: Record<TrustKind, IconName> = {
  hosting: 'server',
  contract: 'file',
  processing: 'list',
}

export interface TrustItemProps {
  kind: TrustKind
  /** Concrete statement with its scope, e.g. "Hosting in Deutschland (VotePit Cloud)". */
  statement: string
  /** Link text that names the target, e.g. "Datenverarbeitung im Detail". */
  linkLabel: string
  href: string
  testId: string
}

/** Plain statement with a real link to the evidence. No seal or badge look. */
export function TrustItem({ kind, statement, linkLabel, href, testId }: TrustItemProps) {
  return (
    <div className="ds-trust-item" data-kind={kind} data-testid={testId}>
      <Icon name={KIND_ICON[kind]} className="ds-trust-item__icon" />
      <div>
        <p className="ds-trust-item__statement">{statement}</p>
        <a className="ds-trust-item__link ds-focusable" href={href} data-testid={`${testId}-link`}>
          {linkLabel}
        </a>
      </div>
    </div>
  )
}
