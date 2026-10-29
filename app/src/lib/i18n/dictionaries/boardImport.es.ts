// machine-drafted, needs native review
import type de from './boardImport.de'

const es = {
  heading: 'Importar ideas',
  intro:
    'Trae ideas desde un archivo CSV o una exportación de Canny o Featurebase. Primero se revisa el archivo; no se importa nada hasta que lo confirmes.',
  privacyNote:
    'Los autores no se importan: los nombres y correos del archivo se descartan y las ideas importadas aparecen con tu cuenta. Los votos se importan como un número, no como votos individuales.',
  formatLabel: 'Origen',
  formatCsv: 'CSV (plantilla de Votepit)',
  formatCanny: 'Exportación de Canny (CSV)',
  formatFeaturebase: 'Exportación de Featurebase (CSV)',
  csvTemplateHint:
    'Columnas: title, body, status, created_at, votes, tags. Solo title es obligatoria.',
  chooseFile: 'Elegir archivo',
  noFile: 'Ningún archivo seleccionado',
  fileTooLarge: 'El archivo supera los 2 MB.',
  fileReadError: 'No se pudo leer el archivo.',
  check: 'Revisar archivo',
  checking: 'Revisando…',
  summary: '{total} filas leídas: se importarán {importable}, se omitirán {skipped}.',
  newTags: 'Etiquetas nuevas: {tags}',
  ignoredColumns: 'Columnas no importadas: {columns}',
  issuesHeading: 'Observaciones sobre el archivo',
  issueLine: 'Fila {line}',
  previewHeading: 'Vista previa (primeras filas)',
  previewTitle: 'Título',
  previewStatus: 'Estado',
  previewVotes: 'Votos',
  previewDate: 'Creada',
  confirm: 'Importar {count} ideas',
  importing: 'Importando…',
  nothingToImport: 'El archivo no contiene ideas que se puedan importar.',
  done: 'Se importaron {count} ideas.',
  replayed: 'Este archivo ya se importó; no se ha creado nada por duplicado.',
  conflict: 'Esta importación ya se hizo con otro archivo. Vuelve a seleccionar el archivo.',
  genericError: 'La importación ha fallado.',
  'reason.too_large': 'El archivo supera los 2 MB.',
  'reason.too_many_rows': 'El archivo tiene más de 1.000 filas. Divídelo, por favor.',
  'reason.empty': 'El archivo está vacío.',
  'reason.invalid_encoding': 'El archivo no está en UTF-8. Guárdalo como «CSV UTF-8».',
  'reason.malformed_csv': 'El archivo no es un CSV válido (por ejemplo, unas comillas sin cerrar).',
  'reason.missing_title_column':
    'Falta una columna de título. Comprueba que has elegido el origen correcto.',
  'code.title_missing': 'Falta el título, se omite la fila.',
  'code.title_too_long': 'El título supera los 200 caracteres, se omite la fila.',
  'code.body_too_long': 'La descripción es demasiado larga, se omite la fila.',
  'code.column_count_mismatch': 'Número de columnas incorrecto, se omite la fila.',
  'code.duplicate_title': 'Ya existe una idea con este título, se omite la fila.',
  'code.status_unknown': 'Estado desconocido, se importa como «Abierta».',
  'code.created_at_invalid': 'No se pudo leer la fecha, se usa la fecha de hoy.',
  'code.votes_invalid': 'No se pudo leer el número de votos, se usa 0.',
  'code.tag_too_long': 'Una etiqueta supera los 40 caracteres y se omite.',
  'code.too_many_tags': 'Más de 10 etiquetas, el resto se omite.',
  'code.tag_limit': 'Más de 50 etiquetas nuevas por importación, esta se omite.',
} satisfies typeof de

export default es
