/**
 * OperatorSupportPage — /operator/support
 *
 * Dedicated ticket inbox for the operator: search + status/category filters
 * + sort over one fluid table, reachable from the sidebar's Platform group
 * (AdminShell) and from the operator dashboard's "open support requests"
 * tile — no longer an inline accordion tacked onto OperatorPage, which
 * became unwieldy once an installation gets more than a handful of tickets
 * a day. Row click navigates to OperatorSupportTicketPage for the full
 * thread. Modeled directly on cloud/app/AdminTenantsPage.tsx's
 * search+filter+table blueprint.
 *
 * Auth gate: same shape as OperatorPage — anon redirects to /login, a
 * non-operator sees "no access", nothing else is fetched either way.
 */

import {
  Badge,
  type BadgeTone,
  Button,
  EmptyState,
  ErrorState,
  InlineNotice,
  LoadingState,
  PageHeader,
  SearchField,
  Section,
  Select,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeaderCell,
  TableRow,
} from '@votepit/ui/ds'
import { Ticket } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import type {
  ApiError,
  SupportCategory,
  SupportRequestSort,
  SupportRequestStatus,
  SupportRequestSummary,
  User,
} from '../lib/api'
import { bootstrap, listOperatorSupportRequests, logout } from '../lib/api'
import { formatDateTime } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready' }

type ListState =
  | { phase: 'loading' }
  | { phase: 'error'; message: string }
  | { phase: 'ready'; items: SupportRequestSummary[] }

const CATEGORIES: SupportCategory[] = [
  'technical',
  'billing',
  'account',
  'feature_request',
  'privacy',
  'other',
]
const STATUSES: SupportRequestStatus[] = ['open', 'answered', 'closed']
const SORTS: SupportRequestSort[] = [
  'updated_at_desc',
  'updated_at_asc',
  'created_at_desc',
  'created_at_asc',
]

const statusTone: Record<SupportRequestStatus, BadgeTone> = {
  open: 'warning',
  answered: 'success',
  closed: 'neutral',
}

export default function OperatorSupportPage() {
  const navigate = useNavigate()
  const t = useT('operatorSupportPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)

  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<SupportRequestStatus | ''>('')
  const [category, setCategory] = useState<SupportCategory | ''>('')
  const [sort, setSort] = useState<SupportRequestSort>('updated_at_desc')

  const [listState, setListState] = useState<ListState>({ phase: 'loading' })

  // biome-ignore lint/correctness/useExhaustiveDependencies: init is stable (defined inline, no external deps worth tracking); only navigate matters.
  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent('/operator/support')}`, { replace: true })
          return
        }

        setUser(boot.user)
        if (!boot.user.is_operator && !boot.user.is_support) {
          setIsAuthenticated(true)
          setPageState({ phase: 'access_denied' })
          return
        }

        setIsAuthenticated(true)
        setPageState({ phase: 'ready' })
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent('/operator/support')}`, { replace: true })
          return
        }
        if (apiErr.name === 'ApiError' && apiErr.status === 403) {
          setIsAuthenticated(true)
          setPageState({ phase: 'access_denied' })
          return
        }
        const msg =
          (apiErr as ApiError)?.payload?.message ?? (err as Error)?.message ?? t('loadError')
        setPageState({ phase: 'error', message: msg })
      }
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [navigate])

  // Debounced reload — search waits 300ms, filter/sort changes are immediate
  // (they don't fire per-keystroke), same pattern as AdminTenantsPage.
  useEffect(() => {
    if (pageState.phase !== 'ready') return
    const handle = setTimeout(() => {
      listOperatorSupportRequests({
        status: status === '' ? undefined : status,
        category: category === '' ? undefined : category,
        q: search === '' ? undefined : search,
        sort,
      })
        .then((result) => {
          setListState({ phase: 'ready', items: result.requests })
        })
        .catch((err) => {
          const apiErr = err as ApiError
          const msg =
            (apiErr as ApiError)?.payload?.message ?? (err as Error)?.message ?? t('loadError')
          setListState({ phase: 'error', message: msg })
        })
    }, 300)
    return () => clearTimeout(handle)
  }, [pageState.phase, search, status, category, sort, t])

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const frame = (children: ReactNode) => (
    <AdminShell
      user={user}
      isAuthenticated={isAuthenticated}
      authPending={pageState.phase === 'loading'}
      onLogout={handleLogout}
      onLogin={() => navigate('/login')}
      logoHref="/"
      area="operator"
    >
      {children}
    </AdminShell>
  )

  if (pageState.phase === 'loading') {
    return frame(<LoadingState label={t('loading')} rows={6} />)
  }

  if (pageState.phase === 'access_denied') {
    return frame(
      <ErrorState
        kind="denied"
        title={t('accessDeniedTitle')}
        description={t('accessDeniedBody')}
        action={
          <Button testId="operator-support-logout" onClick={handleLogout}>
            {tCommon('header.logout')}
          </Button>
        }
      />,
    )
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  const filtersActive = search !== '' || status !== '' || category !== ''

  return frame(
    <>
      <PageHeader eyebrow={t('scopeLabel')} title={t('title')} description={t('subtitle')}>
        <div className="flex flex-col gap-3">
          <div
            role="search"
            aria-label={t('filtersHeading')}
            className="flex flex-col sm:flex-row sm:items-end gap-3"
          >
            <div className="flex-1 sm:max-w-md">
              <SearchField
                testId="operator-support-search"
                label={t('searchLabel')}
                hideLabel
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t('searchPlaceholder')}
              />
            </div>
            <Select
              testId="operator-support-status-filter"
              label={t('statusFilterLabel')}
              hideLabel
              value={status}
              onChange={(v) => setStatus(v as SupportRequestStatus | '')}
              className="sm:w-44"
            >
              <option value="">{t('statusFilterAll')}</option>
              {STATUSES.map((s) => (
                <option key={s} value={s}>
                  {t(`status.${s}`)}
                </option>
              ))}
            </Select>
            <Select
              testId="operator-support-category-filter"
              label={t('categoryFilterLabel')}
              hideLabel
              value={category}
              onChange={(v) => setCategory(v as SupportCategory | '')}
              className="sm:w-48"
            >
              <option value="">{t('categoryFilterAll')}</option>
              {CATEGORIES.map((c) => (
                <option key={c} value={c}>
                  {t(`category.${c}`)}
                </option>
              ))}
            </Select>
            <Select
              testId="operator-support-sort"
              label={t('sortLabel')}
              hideLabel
              value={sort}
              onChange={(v) => setSort(v as SupportRequestSort)}
              className="sm:w-48"
            >
              {SORTS.map((s) => (
                <option key={s} value={s}>
                  {t(`sort.${s}`)}
                </option>
              ))}
            </Select>
            {filtersActive && (
              <Button
                testId="operator-support-reset-filters"
                type="button"
                variant="text"
                onClick={() => {
                  setSearch('')
                  setStatus('')
                  setCategory('')
                }}
              >
                {t('resetFilters')}
              </Button>
            )}
          </div>
          {listState.phase === 'error' && (
            <InlineNotice tone="error">{listState.message}</InlineNotice>
          )}
        </div>
      </PageHeader>

      <Section
        testId="operator-support-table-section"
        title={
          listState.phase === 'ready'
            ? t('tableHeadingCount', { count: listState.items.length })
            : t('tableHeading')
        }
        icon={<Ticket size={16} />}
        emphasis="ruled"
        flush
      >
        {listState.phase === 'loading' ? (
          <LoadingState label={t('loading')} rows={6} className="px-ds-md sm:px-ds-lg" />
        ) : listState.phase === 'error' ? null : listState.items.length === 0 ? (
          <EmptyState
            kind={filtersActive ? 'no-results' : 'no-ideas'}
            variant="plain"
            testId="operator-support-empty"
            title={t('noTickets')}
            description={filtersActive ? t('noTicketsFiltered') : undefined}
          />
        ) : (
          <Table testId="operator-support-table" caption={t('tableAriaLabel')}>
            <TableHead>
              <TableRow>
                <TableHeaderCell>{t('subjectColumn')}</TableHeaderCell>
                <TableHeaderCell>{t('accountColumn')}</TableHeaderCell>
                <TableHeaderCell>{t('categoryColumn')}</TableHeaderCell>
                <TableHeaderCell>{t('statusColumn')}</TableHeaderCell>
                <TableHeaderCell>{t('updatedColumn')}</TableHeaderCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {listState.items.map((r) => (
                <TableRow key={r.id} onClick={() => navigate(`/operator/support/${r.id}`)}>
                  <TableCell>
                    <span className="font-medium text-vp-title">{r.subject}</span>
                  </TableCell>
                  <TableCell>
                    <span className="font-mono-num text-vp-sm text-vp-text-secondary">
                      {r.account_slug ?? '—'}
                    </span>
                  </TableCell>
                  <TableCell>
                    <Badge tone="neutral">{t(`category.${r.category}`)}</Badge>
                  </TableCell>
                  <TableCell>
                    <Badge tone={statusTone[r.status]} dot>
                      {t(`status.${r.status}`)}
                    </Badge>
                  </TableCell>
                  <TableCell>
                    <span className="font-mono-num text-vp-sm text-vp-text-secondary">
                      {formatDateTime(r.updated_at, language)}
                    </span>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Section>
    </>,
  )
}
