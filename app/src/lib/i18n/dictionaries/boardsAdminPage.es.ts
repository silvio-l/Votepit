// machine-drafted, needs native review
import type de from './boardsAdminPage.de'

const es = {
  loading: 'Cargando…',
  accessDeniedTitle: 'Acceso denegado',
  accessDeniedBody: 'Solo los administradores de la cuenta pueden acceder a esta página.',
  loadError: 'No se pudo cargar la página.',
  title: 'Tableros',
  subtitle: 'Todos los tableros de tu cuenta.',
  noBoards: 'Aún no se ha creado ningún tablero.',
  createFirstBoard: 'Crea el primero ahora',
  boardsAriaLabel: 'Tableros',
  boardsHeading: 'Tableros ({count})',
  frozen: 'Congelado',
  complianceFrozenAccount: 'Oculto: completa los datos del operador',
  complianceFrozenHintAccount:
    'Este tablero está oculto porque la identidad del operador legal de la cuenta (nombre, dirección y correo de contacto) está incompleta. Haz clic para completarla en la página Cuenta; así se desbloquean todos los tableros que ya tienen confirmado su propio reconocimiento del operador.',
  complianceFrozenBoard: 'Oculto: confirma el aviso del operador',
  complianceFrozenHintBoard:
    'El nombre del operador legal de la cuenta está configurado, pero este tablero aún necesita que se confirme su propio aviso de responsabilidad del operador. Haz clic para confirmarlo aquí.',
  manage: 'Configuración',
  view: 'Ver',
  copyLink: 'Copiar enlace del tablero',
  copyLinkCopied: '¡Enlace copiado!',
  createHeading: 'Crear un tablero nuevo',
  nameLabel: 'Nombre',
  namePlaceholder: 'p. ej. Nuestras ideas',
  slugLabel: 'Slug',
  slugPlaceholder: 'p. ej. nuestras-ideas',
  slugHint: 'Se sugiere a partir del nombre, pero puedes editarlo libremente.',
  createSubmitting: 'Creando…',
  createSubmit: 'Crear tablero',
  newBoard: 'Nuevo tablero',
  statBoards: 'Tableros',
  statIdeas: 'Ideas',
  statVotes: 'Votos',
  statCaptionAll: 'en todos los tableros',
  ideasCount: '{count} ideas',
  ideasCountSingular: '{count} idea',
  votesCount: '{count} votos',
  votesCountSingular: '{count} voto',
  createHint:
    'Un tablero es una página pública con su propio enlace donde la gente envía ideas y vota.',
  emptyDescription:
    'Crea un tablero y comparte su enlace: las ideas y los votos se irán acumulando solos.',
  operatorAckLabel:
    'Confirmo que soy el operador legal de este tablero, responsable de sus normas de comunidad, su moderación y cualquier obligación de aviso legal o privacidad del tablero.',
} satisfies typeof de

export default es
