// machine-drafted, needs native review
import type de from './boardImport.de'

const it = {
  heading: 'Importa idee',
  intro:
    "Porta le idee da un file CSV o da un'esportazione di Canny o Featurebase. Il file viene prima controllato; non viene importato nulla finché non confermi.",
  privacyNote:
    'Gli autori non vengono importati: nomi e indirizzi e-mail nel file vengono scartati e le idee importate compaiono con il tuo account. I voti vengono importati come numero, non come singoli voti.',
  formatLabel: 'Origine',
  formatCsv: 'CSV (modello Votepit)',
  formatCanny: 'Esportazione Canny (CSV)',
  formatFeaturebase: 'Esportazione Featurebase (CSV)',
  csvTemplateHint:
    'Colonne: title, body, status, created_at, votes, tags. Solo title è obbligatoria.',
  chooseFile: 'Scegli file',
  noFile: 'Nessun file selezionato',
  fileTooLarge: 'Il file supera i 2 MB.',
  fileReadError: 'Impossibile leggere il file.',
  check: 'Controlla file',
  checking: 'Controllo in corso…',
  summary: '{total} righe lette: {importable} verranno importate, {skipped} saltate.',
  newTags: 'Nuovi tag: {tags}',
  ignoredColumns: 'Colonne non importate: {columns}',
  issuesHeading: 'Note sul file',
  issueLine: 'Riga {line}',
  previewHeading: 'Anteprima (prime righe)',
  previewTitle: 'Titolo',
  previewStatus: 'Stato',
  previewVotes: 'Voti',
  previewDate: 'Creata',
  confirm: 'Importa {count} idee',
  importing: 'Importazione…',
  nothingToImport: 'Il file non contiene idee importabili.',
  done: '{count} idee sono state importate.',
  replayed: 'Questo file è già stato importato; non è stato creato nulla due volte.',
  conflict:
    'Questa importazione è già stata eseguita con un altro file. Seleziona di nuovo il file.',
  genericError: "L'importazione non è riuscita.",
  'reason.too_large': 'Il file supera i 2 MB.',
  'reason.too_many_rows': 'Il file ha più di 1.000 righe. Dividilo, per favore.',
  'reason.empty': 'Il file è vuoto.',
  'reason.invalid_encoding': 'Il file non è codificato in UTF-8. Salvalo come «CSV UTF-8».',
  'reason.malformed_csv': 'Il file non è un CSV valido (ad esempio, virgolette non chiuse).',
  'reason.missing_title_column':
    "Manca una colonna del titolo. Verifica di aver scelto l'origine giusta.",
  'code.title_missing': 'Titolo mancante, riga saltata.',
  'code.title_too_long': 'Il titolo supera i 200 caratteri, riga saltata.',
  'code.body_too_long': 'La descrizione è troppo lunga, riga saltata.',
  'code.column_count_mismatch': 'Numero di colonne errato, riga saltata.',
  'code.duplicate_title': "Esiste già un'idea con questo titolo, riga saltata.",
  'code.status_unknown': 'Stato sconosciuto, importata come «Aperta».',
  'code.created_at_invalid': 'Data non leggibile, viene usata la data di oggi.',
  'code.votes_invalid': 'Numero di voti non leggibile, viene usato 0.',
  'code.tag_too_long': 'Un tag supera i 40 caratteri e viene omesso.',
  'code.too_many_tags': 'Più di 10 tag, gli altri vengono omessi.',
  'code.tag_limit': 'Più di 50 nuovi tag per importazione, questo tag viene omesso.',
} satisfies typeof de

export default it
