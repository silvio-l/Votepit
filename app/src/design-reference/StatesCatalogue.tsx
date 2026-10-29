import {
  BrandMark,
  Button,
  DecisionPanel,
  EmptyState,
  IconButton,
  IdeaCard,
  type IdeaStatus,
  InlineNotice,
  type NoticeTone,
  Skeleton,
  StatusChip,
  statusChipFor,
  TextArea,
  TextField,
  TrustItem,
  VoteControl,
  VoteSummary,
} from '@votepit/ui/ds'
import type { ReactNode } from 'react'
import { CLOUD_SITE_ORIGIN } from '../lib/edition'
import {
  CAT_CARD,
  CAT_DECISION,
  CAT_EMPTY_TITLE,
  CAT_STATUS,
  CAT_SUMMARY,
  CAT_VOTE,
} from './catalogueLabels'

const ALL_STATUSES: readonly IdeaStatus[] = [
  'open',
  'in-review',
  'planned',
  'in-progress',
  'done',
  'declined',
  'not-implemented',
]

const NOTICE_TONES: readonly NoticeTone[] = ['success', 'info', 'warning', 'error']

const LONG_GERMAN_TITLE =
  'Automatische Zusammenfassung aller Ideen mit mehr als dreihundert Stimmen aus den letzten zwölf Monaten für die jährliche Produktplanung und Gremienberichte'
const UNBROKEN_WORD = 'Donaudampfschifffahrtsgesellschaftskapitaenspruefungsverordnungsaenderung'
const ENGLISH_TITLE =
  'Offline mode for travel, including sync conflicts when several devices edit the same idea after a long time without network'

/** A demo action that is always rejected. Shows the restore path: the pressed state stays as it was. */
const rejectVote = (): Promise<void> => Promise.reject(new Error('demo rejection'))
/** A demo action that never settles, so the pending state stays on screen. */
const hangVote = (): Promise<void> => new Promise<void>(() => {})

function Case({ title, testId, children }: { title: string; testId: string; children: ReactNode }) {
  return (
    <section className="ref-case" data-testid={testId} aria-label={title}>
      <p className="ref-case__label">{title}</p>
      {children}
    </section>
  )
}

/** Abstract decorative artwork for the showcase card media slot (not UI). */
function ShowcaseMedia() {
  return (
    <svg className="ref-showcase-media" viewBox="0 0 160 100" aria-hidden="true" focusable="false">
      <rect width="160" height="100" style={{ fill: 'var(--vpds-surface-calm)' }} />
      <path
        d="M52 70V30M40 42l12-12 12 12"
        fill="none"
        strokeWidth="6"
        strokeLinecap="round"
        style={{ stroke: 'var(--vpds-vote-up-solid)' }}
      />
      <path
        d="M108 30v40M96 58l12 12 12-12"
        fill="none"
        strokeWidth="6"
        strokeLinecap="round"
        style={{ stroke: 'var(--vpds-vote-down-solid)' }}
      />
    </svg>
  )
}

/** Every component in every listed state, plus long content and the 320 px reflow frame. */
export function StatesCatalogue() {
  return (
    <section
      className="ref-section"
      aria-labelledby="states-catalogue-title"
      data-testid="states-catalogue"
    >
      <InlineNotice
        tone="warning"
        title="Beispieldaten — keine echten Nutzerstimmen"
        testId="states-sample-banner"
      >
        Alle Zahlen, Titel und Stimmen in diesem Katalog sind Beispiele. Sperr- und Fehlerfälle sind
        absichtlich ausgelöst.
      </InlineNotice>
      <h2 id="states-catalogue-title" className="ref-section__title">
        Zustände und Komponenten
      </h2>

      <div className="ref-cases">
        <Case title="Button: Varianten, Standard" testId="case-buttons-variants">
          <div className="ref-group">
            <Button testId="case-button-primary">Primär</Button>
            <Button variant="secondary" testId="case-button-secondary">
              Sekundär
            </Button>
            <Button variant="text" testId="case-button-text">
              Text
            </Button>
            <Button variant="destructive" testId="case-button-destructive">
              Löschen
            </Button>
          </div>
          <p className="ref-case__label">Groß, Ladezustand, deaktiviert</p>
          <div className="ref-group">
            <Button size="large" testId="case-button-large">
              Groß
            </Button>
            <Button variant="secondary" loading testId="case-button-loading">
              Speichern
            </Button>
            <Button disabled testId="case-button-disabled">
              Nicht verfügbar
            </Button>
          </div>
          <div className="ref-stage ds-surface-inverse">
            <Button variant="on-inverse" testId="case-button-on-inverse">
              Auf dunklem Grund
            </Button>
          </div>
        </Case>

        <Case title="IconButton" testId="case-icon-buttons">
          <div className="ref-group">
            <IconButton icon="search" accessibleLabel="Suche öffnen" testId="case-icon-search" />
            <IconButton
              icon="bell"
              accessibleLabel="Updates verfolgen"
              pressed
              testId="case-icon-bell-pressed"
            />
            <IconButton icon="comment" accessibleLabel="Kommentare" testId="case-icon-comment" />
          </div>
        </Case>

        <Case title="VoteControl: Grundzustände (inline)" testId="case-vote-basic">
          <div className="ref-row">
            <VoteControl
              {...CAT_VOTE}
              direction="up"
              count={0}
              ideaTitle="Beispielidee"
              testId="case-vote-neutral-up"
            />
            <VoteControl
              {...CAT_VOTE}
              direction="down"
              count={0}
              ideaTitle="Beispielidee"
              testId="case-vote-neutral-down"
            />
          </div>
          <div className="ref-row">
            <VoteControl
              {...CAT_VOTE}
              direction="up"
              count={24}
              selected
              ideaTitle="Offline-Modus für unterwegs"
              testId="case-vote-selected-up"
            />
            <VoteControl
              {...CAT_VOTE}
              direction="down"
              count={6}
              selected
              ideaTitle="Offline-Modus für unterwegs"
              testId="case-vote-selected-down"
            />
          </div>
        </Case>

        <Case title="VoteControl: Hover, Fokus (Vorschau), kompakt" testId="case-vote-states">
          <div className="ref-row">
            <VoteControl
              {...CAT_VOTE}
              direction="up"
              count={18}
              forceState="hover"
              ideaTitle="Tastenkürzel selbst festlegen"
              testId="case-vote-hover"
            />
            <VoteControl
              {...CAT_VOTE}
              direction="down"
              count={12}
              forceState="focus"
              ideaTitle="Tastenkürzel selbst festlegen"
              testId="case-vote-focus"
            />
          </div>
          <div className="ref-row">
            <VoteControl
              {...CAT_VOTE}
              direction="up"
              count={3}
              size="compact"
              ideaTitle="Export als Markdown"
              testId="case-vote-compact-up"
            />
            <VoteControl
              {...CAT_VOTE}
              direction="down"
              count={0}
              size="compact"
              ideaTitle="Export als Markdown"
              testId="case-vote-compact-down"
            />
          </div>
        </Case>

        <Case title="VoteControl: pending, readonly, Fehler" testId="case-vote-async">
          <div className="ref-row">
            <VoteControl
              {...CAT_VOTE}
              direction="up"
              count={25}
              pending
              ideaTitle="Beispielidee"
              testId="case-vote-pending"
            />
            <VoteControl
              {...CAT_VOTE}
              direction="up"
              count={24}
              readonly
              ideaTitle="Beispielidee"
              testId="case-vote-readonly"
            />
          </div>
          <p className="ref-case__label">
            Ein Klick schlägt fehl, der vorherige Zustand bleibt (Demo):
          </p>
          <div className="ref-row">
            <VoteControl
              {...CAT_VOTE}
              direction="up"
              count={24}
              onVote={rejectVote}
              ideaTitle="Beispielidee"
              testId="case-vote-restore-unselected"
            />
            <VoteControl
              {...CAT_VOTE}
              direction="down"
              count={6}
              selected
              onVote={rejectVote}
              ideaTitle="Beispielidee"
              testId="case-vote-restore-selected"
            />
          </div>
          <div className="ref-row">
            <VoteControl
              {...CAT_VOTE}
              direction="up"
              count={24}
              errorMessage="Stimme konnte nicht gespeichert werden. Der vorherige Stand bleibt erhalten."
              ideaTitle="Beispielidee"
              testId="case-vote-error-static"
            />
          </div>
          <p className="ref-case__label">Noch offene Anfrage (Demo, blockiert weitere Klicks):</p>
          <div className="ref-row">
            <VoteControl
              {...CAT_VOTE}
              direction="down"
              count={6}
              onVote={hangVote}
              ideaTitle="Beispielidee"
              testId="case-vote-hanging"
            />
          </div>
        </Case>

        <Case title="VoteControl: null, gleich viele, nur Gegenstimmen" testId="case-vote-counts">
          <div className="ref-row">
            <VoteControl
              {...CAT_VOTE}
              direction="up"
              count={0}
              ideaTitle="Ohne Stimmen"
              testId="case-vote-zero"
            />
            <VoteControl
              {...CAT_VOTE}
              direction="down"
              count={0}
              ideaTitle="Ohne Stimmen"
              testId="case-vote-zero-down"
            />
          </div>
          <div className="ref-row">
            <VoteControl
              {...CAT_VOTE}
              direction="up"
              count={10}
              ideaTitle="Gleich viele Stimmen"
              testId="case-vote-equal-up"
            />
            <VoteControl
              {...CAT_VOTE}
              direction="down"
              count={10}
              ideaTitle="Gleich viele Stimmen"
              testId="case-vote-equal-down"
            />
          </div>
          <div className="ref-row">
            <VoteControl
              {...CAT_VOTE}
              direction="down"
              count={5}
              selected
              ideaTitle="Nur Gegenstimmen"
              testId="case-vote-only-down"
            />
          </div>
        </Case>

        <Case title="StatusChip: Töne" testId="case-status-tones">
          <div className="ref-group">
            <StatusChip tone="neutral">Offen</StatusChip>
            <StatusChip tone="info">In Prüfung</StatusChip>
            <StatusChip tone="progress">In Arbeit</StatusChip>
            <StatusChip tone="success">Umgesetzt</StatusChip>
          </div>
        </Case>

        <Case
          title="StatusChip: alle Status (gleiche Labels in Liste, Detail, Roadmap)"
          testId="case-status-mapping"
        >
          <div className="ref-group">
            {ALL_STATUSES.map((status) => {
              const chip = statusChipFor(status, CAT_STATUS[status])
              return (
                <StatusChip
                  key={status}
                  tone={chip.tone}
                  icon={chip.icon}
                  testId={`case-status-${status}`}
                >
                  {chip.label}
                </StatusChip>
              )
            })}
          </div>
        </Case>

        <Case title="VoteSummary: standard" testId="case-summary-standard">
          <VoteSummary {...CAT_SUMMARY} up={24} down={6} testId="case-summary-24-6" />
          <VoteSummary {...CAT_SUMMARY} up={18} down={12} testId="case-summary-18-12" />
          <VoteSummary {...CAT_SUMMARY} up={21} down={3} testId="case-summary-21-3" />
        </Case>

        <Case
          title="VoteSummary: null, nur Gegenstimmen, gleich, kompakt"
          testId="case-summary-edge"
        >
          <VoteSummary {...CAT_SUMMARY} up={0} down={0} testId="case-summary-zero" />
          <VoteSummary {...CAT_SUMMARY} up={0} down={5} testId="case-summary-only-down" />
          <VoteSummary {...CAT_SUMMARY} up={10} down={10} testId="case-summary-equal" />
          <VoteSummary
            {...CAT_SUMMARY}
            up={24}
            down={6}
            variant="compact"
            testId="case-summary-compact"
          />
          <VoteSummary {...CAT_SUMMARY} up={1234} down={567} testId="case-summary-large" />
        </Case>

        <Case title="IdeaCard: Liste, Standard und Auswahl" testId="case-idea-list">
          <IdeaCard
            {...CAT_CARD}
            testId="case-idea-default"
            title="Offline-Modus für unterwegs"
            problem="Unterwegs ohne Netz lassen sich Ideen weder lesen noch bewerten."
            status="in-progress"
            up={24}
            down={6}
            commentCount={7}
            updatedLabel="vor 2 Tagen"
            href="#offline-mode"
          />
          <IdeaCard
            {...CAT_CARD}
            testId="case-idea-selected"
            title="Tastenkürzel selbst festlegen"
            status="in-review"
            up={18}
            down={12}
            commentCount={1}
            href="#shortcuts"
            state="selected"
            votedUp
          />
        </Case>

        <Case title="IdeaCard: ungelesen, Speichern, kompakt" testId="case-idea-states">
          <IdeaCard
            {...CAT_CARD}
            testId="case-idea-unread"
            title="Export als Markdown"
            status="planned"
            up={21}
            down={3}
            commentCount={0}
            href="#markdown-export"
            state="unread"
          />
          <IdeaCard
            {...CAT_CARD}
            testId="case-idea-pending"
            title="Export als Markdown"
            status="planned"
            up={21}
            down={3}
            commentCount={4}
            href="#markdown-export"
            state="pending"
            votedDown
          />
          <IdeaCard
            {...CAT_CARD}
            testId="case-idea-compact"
            title="Export als Markdown"
            status="planned"
            up={21}
            down={3}
            commentCount={4}
            href="#markdown-export"
            variant="compact"
          />
        </Case>

        <Case title="IdeaCard: Showcase mit Medien, ohne Medien" testId="case-idea-showcase">
          <IdeaCard
            {...CAT_CARD}
            testId="case-idea-showcase-media"
            title="Offline-Modus für unterwegs"
            status="in-progress"
            up={24}
            down={6}
            commentCount={7}
            href="#offline-mode"
            variant="showcase"
            media={<ShowcaseMedia />}
          />
          <IdeaCard
            {...CAT_CARD}
            testId="case-idea-showcase-plain"
            title="Tastenkürzel selbst festlegen"
            status="in-review"
            up={18}
            down={12}
            commentCount={14}
            href="#shortcuts"
            variant="showcase"
          />
        </Case>

        <Case
          title="IdeaCard: sehr langer Titel, unbrechbares Wort, Englisch"
          testId="case-idea-long"
        >
          <IdeaCard
            {...CAT_CARD}
            testId="case-idea-long-german"
            title={LONG_GERMAN_TITLE}
            problem="Lange Titel umbrechen und verlängern die Karte, nicht die Seite."
            status="open"
            up={310}
            down={44}
            commentCount={2}
            href="#long"
          />
          <IdeaCard
            {...CAT_CARD}
            testId="case-idea-long-word"
            title={UNBROKEN_WORD}
            status="open"
            up={2}
            down={0}
            commentCount={0}
            href="#unbroken"
          />
          <IdeaCard
            {...CAT_CARD}
            testId="case-idea-long-english"
            title={ENGLISH_TITLE}
            status="open"
            up={0}
            down={0}
            commentCount={0}
            href="#english"
          />
        </Case>

        <Case title="IdeaCard: Berechtigung (nur lesen)" testId="case-idea-readonly">
          <IdeaCard
            {...CAT_CARD}
            testId="case-idea-readonly-card"
            title="Gast sieht die Zahlen"
            status="open"
            up={5}
            down={1}
            commentCount={0}
            href="#guest"
            readonlyVotes
          />
        </Case>

        <Case title="DecisionPanel: geplant, abgelehnt, umgesetzt" testId="case-decision">
          <DecisionPanel
            {...CAT_DECISION}
            outcome="planned"
            author="Produktteam"
            date="2026-10-14"
            rationale="Mehrere Rückmeldungen zeigen den Bedarf. Eine Liefergarantie gibt es nicht."
            nextStep="Technische Prüfung der Synchronisation."
            testId="case-decision-planned"
          />
          <DecisionPanel
            {...CAT_DECISION}
            outcome="declined"
            date="2026-10-02"
            rationale="Wir bauen diese Funktion nicht, weil sie den Export für die meisten Teams unübersichtlich macht. Die Rückmeldungen sind dennoch wichtig."
            nextStep="Keine Umsetzung geplant; die Idee bleibt sichtbar."
            canEdit
            onEdit={() => undefined}
            testId="case-decision-declined"
          />
          <DecisionPanel
            {...CAT_DECISION}
            outcome="done"
            author="Produktteam"
            date="2026-09-20"
            rationale="Die Funktion ist seit der letzten Version verfügbar."
            nextStep="Keiner: Der Verlauf dokumentiert die Änderung."
            testId="case-decision-done"
          />
        </Case>

        <Case
          title="Eingabefelder: Hinweis, Pflicht, ungültig, deaktiviert, nur lesen"
          testId="case-fields"
        >
          <TextField
            label="Titel"
            hint="Kurz und konkret, etwa 60 Zeichen."
            required
            testId="case-field-required"
          />
          <TextField
            label="Titel"
            error="Bitte gib einen Titel ein."
            defaultValue=""
            testId="case-field-invalid"
          />
          <TextField label="Projekt" disabled defaultValue="VotePit" testId="case-field-disabled" />
          <TextField
            label="Kennung"
            readOnly
            defaultValue="votepit-feedback"
            testId="case-field-readonly"
          />
          <TextArea
            label="Problem oder gewünschter Nutzen"
            hint="Was passiert heute, was soll künftig möglich sein?"
            error="Beschreibe kurz das Problem."
            testId="case-textarea-invalid"
          />
        </Case>

        <Case title="InlineNotice: Töne" testId="case-notices">
          {NOTICE_TONES.map((tone) => (
            <InlineNotice
              key={tone}
              tone={tone}
              title={tone === 'error' ? 'Speichern fehlgeschlagen' : 'Hinweis'}
              testId={`case-notice-${tone}`}
              action={
                tone === 'error' ? (
                  <Button variant="secondary" testId="case-notice-retry">
                    Erneut versuchen
                  </Button>
                ) : undefined
              }
            >
              {tone === 'error'
                ? 'Die Verbindung wurde unterbrochen. Deine Eingabe ist noch im Formular.'
                : 'Beispieltext für diesen Zustand.'}
            </InlineNotice>
          ))}
        </Case>

        <Case title="EmptyState: keine Ideen, keine Treffer, kein Zugriff" testId="case-empty">
          <EmptyState
            kind="no-ideas"
            title={CAT_EMPTY_TITLE['no-ideas']}
            description="Auf diesem Board gibt es noch keine Ideen."
            action={<Button testId="case-empty-submit">Erste Idee einreichen</Button>}
            testId="case-empty-no-ideas"
          />
          <EmptyState
            kind="no-results"
            title={CAT_EMPTY_TITLE['no-results']}
            description="Für „Offline“ gibt es keine Treffer."
            action={
              <Button variant="secondary" testId="case-empty-reset">
                Suche zurücksetzen
              </Button>
            }
            testId="case-empty-no-results"
          />
          <EmptyState
            kind="no-access"
            title={CAT_EMPTY_TITLE['no-access']}
            description="Dieses Board ist nur für eingeladene Mitglieder sichtbar."
            action={
              <Button variant="secondary" testId="case-empty-login">
                Mit eingeladenem Konto anmelden
              </Button>
            }
            testId="case-empty-no-access"
          />
        </Case>

        <Case title="TrustItem: Hosting, AV-Vertrag, Verarbeitung" testId="case-trust">
          <TrustItem
            kind="hosting"
            statement="VotePit Cloud wird in Deutschland gehostet."
            linkLabel="Datenschutzinformationen"
            href={`${CLOUD_SITE_ORIGIN}/privacy/`}
            testId="case-trust-hosting"
          />
          <TrustItem
            kind="contract"
            statement="Deinen AV-Vertrag kannst du direkt im Konto abrufen."
            linkLabel="Nutzungsbedingungen"
            href={`${CLOUD_SITE_ORIGIN}/terms/`}
            testId="case-trust-contract"
          />
          <TrustItem
            kind="processing"
            statement="Welche Dienstleister und optionalen Funktionen Daten verarbeiten, steht in den Datenschutzinformationen."
            linkLabel="Datenverarbeitung im Detail"
            href={`${CLOUD_SITE_ORIGIN}/privacy/`}
            testId="case-trust-processing"
          />
        </Case>

        <Case title="Skeleton: Laden" testId="case-skeleton">
          <Skeleton testId="case-skeleton-text" label="Inhalte werden geladen" />
          <Skeleton
            variant="card"
            lines={2}
            label="Idee wird geladen"
            testId="case-skeleton-card"
          />
        </Case>

        <Case title="Marke (Logo aus dem Originalpfad)" testId="case-brand">
          <div className="ref-row">
            <BrandMark />
            <BrandMark edition="community" />
          </div>
          <div className="ref-stage ds-surface-inverse">
            <BrandMark onInk edition="cloud" />
          </div>
        </Case>

        <Case title="320 px Reflow: Liste, Abstimmung, Formular" testId="case-reflow-320">
          <div className="ref-frame-320" data-testid="case-reflow-frame">
            <IdeaCard
              {...CAT_CARD}
              testId="case-reflow-idea"
              title={LONG_GERMAN_TITLE}
              problem="Lange Problemtexte umbrechen auch auf 320 Pixel."
              status="in-review"
              up={24}
              down={6}
              commentCount={7}
              href="#reflow"
            />
            <VoteSummary {...CAT_SUMMARY} up={24} down={6} testId="case-reflow-summary" />
            <TextField label="Suche" testId="case-reflow-search" />
            <Button testId="case-reflow-button">Updates verfolgen</Button>
          </div>
        </Case>
      </div>
    </section>
  )
}
