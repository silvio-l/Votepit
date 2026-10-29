// machine-drafted, needs native review
import type de from './boardImport.de'

const nl = {
  heading: 'Ideeën importeren',
  intro:
    'Neem ideeën over uit een CSV-bestand of een export van Canny of Featurebase. Het bestand wordt eerst gecontroleerd; er wordt pas geïmporteerd nadat je bevestigt.',
  privacyNote:
    'Auteurs worden niet overgenomen: namen en e-mailadressen in het bestand worden genegeerd en geïmporteerde ideeën verschijnen onder je account. Stemmen worden als aantal overgenomen, niet als losse stemmen.',
  formatLabel: 'Bron',
  formatCsv: 'CSV (Votepit-sjabloon)',
  formatCanny: 'Canny-export (CSV)',
  formatFeaturebase: 'Featurebase-export (CSV)',
  csvTemplateHint:
    'Kolommen: title, body, status, created_at, votes, tags. Alleen title is verplicht.',
  chooseFile: 'Bestand kiezen',
  noFile: 'Geen bestand gekozen',
  fileTooLarge: 'Het bestand is groter dan 2 MB.',
  fileReadError: 'Het bestand kon niet worden gelezen.',
  check: 'Bestand controleren',
  checking: 'Controleren…',
  summary: '{total} rijen gelezen: {importable} worden geïmporteerd, {skipped} overgeslagen.',
  newTags: 'Nieuwe tags: {tags}',
  ignoredColumns: 'Niet overgenomen kolommen: {columns}',
  issuesHeading: 'Opmerkingen bij het bestand',
  issueLine: 'Rij {line}',
  previewHeading: 'Voorbeeld (eerste rijen)',
  previewTitle: 'Titel',
  previewStatus: 'Status',
  previewVotes: 'Stemmen',
  previewDate: 'Aangemaakt',
  confirm: '{count} ideeën importeren',
  importing: 'Importeren…',
  nothingToImport: 'Het bestand bevat geen ideeën die geïmporteerd kunnen worden.',
  done: '{count} ideeën zijn geïmporteerd.',
  replayed: 'Dit bestand is al geïmporteerd; er is niets dubbel aangemaakt.',
  conflict: 'Deze import is al uitgevoerd met een ander bestand. Kies het bestand opnieuw.',
  genericError: 'Het importeren is mislukt.',
  'reason.too_large': 'Het bestand is groter dan 2 MB.',
  'reason.too_many_rows': 'Het bestand heeft meer dan 1.000 rijen. Splits het op.',
  'reason.empty': 'Het bestand is leeg.',
  'reason.invalid_encoding': 'Het bestand is niet in UTF-8 gecodeerd. Sla het op als "CSV UTF-8".',
  'reason.malformed_csv':
    'Het bestand is geen geldige CSV (bijvoorbeeld een niet gesloten aanhalingsteken).',
  'reason.missing_title_column':
    'Er ontbreekt een titelkolom. Controleer of de juiste bron is gekozen.',
  'code.title_missing': 'Titel ontbreekt, rij overgeslagen.',
  'code.title_too_long': 'Titel is langer dan 200 tekens, rij overgeslagen.',
  'code.body_too_long': 'Beschrijving is te lang, rij overgeslagen.',
  'code.column_count_mismatch': 'Verkeerd aantal kolommen, rij overgeslagen.',
  'code.duplicate_title': 'Er bestaat al een idee met deze titel, rij overgeslagen.',
  'code.status_unknown': 'Onbekende status, geïmporteerd als "Open".',
  'code.created_at_invalid': 'Datum niet leesbaar, de datum van vandaag wordt gebruikt.',
  'code.votes_invalid': 'Aantal stemmen niet leesbaar, 0 wordt gebruikt.',
  'code.tag_too_long': 'Een tag is langer dan 40 tekens en wordt weggelaten.',
  'code.too_many_tags': 'Meer dan 10 tags, de rest wordt weggelaten.',
  'code.tag_limit': 'Meer dan 50 nieuwe tags per import, deze tag wordt weggelaten.',
} satisfies typeof de

export default nl
