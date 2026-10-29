// machine-drafted, needs native review
import type de from './appealPage.de'

const es = {
  loading: 'Cargando…',
  loadError: 'No se pudo cargar la página.',
  scopeLabel: 'Apelación',
  notFoundError: 'No se encontró esta eliminación.',
  notAuthorError: 'Solo el autor original puede apelar esta eliminación.',
  alreadyPendingError: 'Ya hay una apelación pendiente de revisión para esta eliminación.',
  submitFailed: 'No se pudo enviar la apelación. Inténtalo de nuevo.',
  backToBoard: 'Volver al tablero',
  heading: 'Apelar esta eliminación',
  subtitle:
    'Si crees que tu contenido se eliminó por error, explica aquí el motivo. Un moderador revisará tu apelación.',
  messageLabel: '¿Por qué debería reconsiderarse esta eliminación?',
  messagePlaceholder: 'Explica por qué no estás de acuerdo con la eliminación…',
  messageHint: '{count} caracteres',
  submit: 'Enviar apelación',
  submitting: 'Enviando…',
  submittedTitle: 'Apelación enviada',
  submittedBody:
    'Gracias, tu apelación se ha enviado. Un moderador la revisará y se te notificará el resultado.',
} satisfies typeof de

export default es
