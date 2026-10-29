import { VotepitLogo } from '@votepit/ui'
import { Button, ButtonLink, NotFound } from '@votepit/ui/ds'
import { prefersReducedMotion } from '@votepit/ui/ds/motion'
import { useEffect } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { getEdition } from '../lib/edition'
import { useT } from '../lib/i18n/context'
import { RouterButtonLink } from './RouterButtonLink'
import { ThemeChoice } from './ThemeChoice'

export interface NotFoundStateProps {
  /** Page-specific H1 (defaults to the generic copy). Blame-free, never reveals existence. */
  title?: string
  /** Page-specific explanation (defaults to the generic copy). */
  message?: string
  /**
   * Optional extra link between the homepage and the back link, for a way on that is derived
   * from the URL the visitor already typed (e.g. the board path). Never from the data.
   */
  secondaryLink?: { to: string; label: string; testId: string }
}

/**
 * The single "nothing here" page for every full-page not-found case (unknown
 * route, unknown board, unknown roadmap, unknown account). It deliberately
 * takes no identifiers: a nonexistent resource and one that belongs to another
 * account must render exactly the same, so nothing on this page can reveal
 * whether something exists.
 *
 * The primary action goes to the homepage `/` rather than to an account home,
 * because the caller cannot prove the account in the URL is valid; `/` resolves
 * to the visitor's own place (cloud) or the default board (self-host).
 */
export function NotFoundState({
  title: titleOverride,
  message: messageOverride,
  secondaryLink,
}: NotFoundStateProps = {}) {
  const t = useT('notFoundPage')
  const tCommon = useT('common')
  const navigate = useNavigate()
  const location = useLocation()
  // A page may bring its own specific copy (idea, member, legal notice); the shared
  // action labels and the layout stay the same.
  const title = titleOverride ?? t('title')
  const message = messageOverride ?? t('message')

  // The document title follows the visible H1; the previous title is restored on unmount.
  useEffect(() => {
    const previous = document.title
    document.title = `${title} · Votepit`
    return () => {
      document.title = previous
    }
  }, [title])

  // React Router sets `key` to 'default' on the first entry of a session: there is no
  // in-app history to go back to, so the back link falls back to the homepage.
  const hasHistory = location.key !== 'default'
  const reduceMotion = prefersReducedMotion(typeof window === 'undefined' ? undefined : window)

  return (
    <NotFound
      header={
        <VotepitLogo
          edition={getEdition()}
          ariaLabel={tCommon('header.logoAriaLabel')}
          testId="not-found-logo"
        />
      }
      title={title}
      description={message}
      motion={!reduceMotion}
      actions={
        <>
          <ButtonLink href="/" linkAs={RouterButtonLink} variant="accent" testId="not-found-home">
            {t('backHome')}
          </ButtonLink>
          {secondaryLink && (
            <ButtonLink
              href={secondaryLink.to}
              linkAs={RouterButtonLink}
              variant="secondary"
              testId={secondaryLink.testId}
            >
              {secondaryLink.label}
            </ButtonLink>
          )}
          <Button
            variant="secondary"
            testId="not-found-back"
            onClick={() => (hasHistory ? navigate(-1) : navigate('/'))}
          >
            {t('back')}
          </Button>
          <div className="basis-full flex justify-center pt-2">
            <ThemeChoice />
          </div>
        </>
      }
    />
  )
}
