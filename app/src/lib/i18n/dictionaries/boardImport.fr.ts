// machine-drafted, needs native review
import type de from './boardImport.de'

const fr = {
  heading: 'Importer des idées',
  intro:
    "Reprenez des idées depuis un fichier CSV ou un export Canny ou Featurebase. Le fichier est d'abord vérifié ; rien n'est importé avant votre confirmation.",
  privacyNote:
    'Les auteurs ne sont pas importés : les noms et adresses e-mail du fichier sont ignorés, et les idées importées apparaissent sous votre compte. Les votes sont repris sous forme de nombre, pas de votes individuels.',
  formatLabel: 'Source',
  formatCsv: 'CSV (modèle Votepit)',
  formatCanny: 'Export Canny (CSV)',
  formatFeaturebase: 'Export Featurebase (CSV)',
  csvTemplateHint:
    'Colonnes : title, body, status, created_at, votes, tags. Seule title est obligatoire.',
  chooseFile: 'Choisir un fichier',
  noFile: 'Aucun fichier sélectionné',
  fileTooLarge: 'Le fichier dépasse 2 Mo.',
  fileReadError: "Le fichier n'a pas pu être lu.",
  check: 'Vérifier le fichier',
  checking: 'Vérification…',
  summary: '{total} lignes lues : {importable} seront importées, {skipped} ignorées.',
  newTags: 'Nouveaux tags : {tags}',
  ignoredColumns: 'Colonnes non reprises : {columns}',
  issuesHeading: 'Remarques sur le fichier',
  issueLine: 'Ligne {line}',
  previewHeading: 'Aperçu (premières lignes)',
  previewTitle: 'Titre',
  previewStatus: 'Statut',
  previewVotes: 'Votes',
  previewDate: 'Créée le',
  confirm: 'Importer {count} idées',
  importing: 'Importation…',
  nothingToImport: 'Le fichier ne contient aucune idée importable.',
  done: '{count} idées ont été importées.',
  replayed: "Ce fichier a déjà été importé ; rien n'a été créé en double.",
  conflict:
    'Cet import a déjà été effectué avec un autre fichier. Veuillez sélectionner le fichier à nouveau.',
  genericError: "L'import a échoué.",
  'reason.too_large': 'Le fichier dépasse 2 Mo.',
  'reason.too_many_rows': 'Le fichier compte plus de 1 000 lignes. Veuillez le diviser.',
  'reason.empty': 'Le fichier est vide.',
  'reason.invalid_encoding':
    "Le fichier n'est pas encodé en UTF-8. Enregistrez-le au format « CSV UTF-8 ».",
  'reason.malformed_csv':
    "Le fichier n'est pas un CSV valide (par exemple, un guillemet non fermé).",
  'reason.missing_title_column':
    'Il manque une colonne de titre. Vérifiez que la bonne source est sélectionnée.',
  'code.title_missing': 'Titre manquant, ligne ignorée.',
  'code.title_too_long': 'Titre de plus de 200 caractères, ligne ignorée.',
  'code.body_too_long': 'Description trop longue, ligne ignorée.',
  'code.column_count_mismatch': 'Nombre de colonnes incorrect, ligne ignorée.',
  'code.duplicate_title': 'Une idée avec ce titre existe déjà, ligne ignorée.',
  'code.status_unknown': 'Statut inconnu, importée comme « Ouverte ».',
  'code.created_at_invalid': 'Date illisible, la date du jour est utilisée.',
  'code.votes_invalid': 'Nombre de votes illisible, 0 est utilisé.',
  'code.tag_too_long': 'Un tag dépasse 40 caractères et est omis.',
  'code.too_many_tags': 'Plus de 10 tags, les autres sont omis.',
  'code.tag_limit': 'Plus de 50 nouveaux tags par import, ce tag est omis.',
} satisfies typeof de

export default fr
