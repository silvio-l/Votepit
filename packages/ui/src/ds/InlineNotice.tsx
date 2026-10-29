import type { ReactNode } from 'react'
import { cx } from '../lib/cx'
import { Icon, type IconName } from './icons'

export type NoticeTone = 'success' | 'info' | 'warning' | 'error'

const TONE_ICON: Record<NoticeTone, IconName> = {
  success: 'check-circle',
  info: 'info',
  warning: 'alert',
  error: 'error',
}

export interface InlineNoticeProps {
  tone: NoticeTone
  title?: string
  children: ReactNode
  /** Optional follow-up action, e.g. a retry button. */
  action?: ReactNode
  testId?: string
  className?: string
  /**
   * Live-region role override. Defaults to alert for errors and status otherwise;
   * pass 'alert' for a message that must interrupt, or 'none' for static content.
   */
  role?: 'alert' | 'status' | 'none'
}

/**
 * In-page message. Errors use role="alert" (assertive); the other tones use role="status"
 * (polite). A lone error is never shown only in a toast, so this stays in the layout.
 */
export function InlineNotice({
  tone,
  title,
  children,
  action,
  testId = 'inline-notice',
  className,
  role,
}: InlineNoticeProps) {
  const liveRole = role ?? (tone === 'error' ? 'alert' : 'status')
  return (
    <div
      className={cx('ds-notice', `ds-notice--${tone}`, className)}
      role={liveRole === 'none' ? undefined : liveRole}
      data-testid={testId}
    >
      <Icon name={TONE_ICON[tone]} />
      <div className="ds-notice__body">
        {title && <p className="ds-notice__title">{title}</p>}
        <div className="ds-notice__text">{children}</div>
        {action}
      </div>
    </div>
  )
}
