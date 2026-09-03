import { buttonClassName, ErrorState, VotepitLogo } from '@votepit/ui'
import { Link } from 'react-router-dom'
import { getAccountSlug } from '../lib/accountContext'
import { getEdition } from '../lib/edition'
import { useT } from '../lib/i18n/context'

export default function NotFoundPage() {
  const t = useT('notFoundPage')
  const tCommon = useT('common')
  // In cloud mode, bare '/' is itself this 404 page (scoped routes are all
  // /{account}-prefixed) — linking back to it would loop. Without a known
  // account slug in scope, send them to /login instead (a fresh magic-link
  // click lands on their own dashboard, see LoginVerifyAction.php).
  const backHref = getAccountSlug() !== null ? `/${getAccountSlug()}` : '/login'

  return (
    <div className="min-h-screen vp-desk flex flex-col items-center justify-center gap-8 px-4 py-10">
      <VotepitLogo edition={getEdition()} ariaLabel={tCommon('header.logoAriaLabel')} />
      <div className="w-full max-w-md animate-vp-rise">
        {/* The status code is the desk's stamp — large, quiet, decorative. ErrorState
            brings its own sheet, so it is not nested inside another one. */}
        <p
          aria-hidden="true"
          className="font-archivo font-bold text-[5rem] leading-none tracking-[-0.04em] text-vp-ink/[0.08] text-center select-none mb-3"
        >
          404
        </p>
        <ErrorState
          kind="missing"
          title={t('title')}
          description={t('message')}
          action={
            <Link to={backHref} className={buttonClassName('primary', 'md')}>
              {t('backHome')}
            </Link>
          }
        />
      </div>
    </div>
  )
}
