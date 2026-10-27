// machine-drafted, needs native review
import type de from './widget.de'

const es = {
  title: 'Feedback',
  close: 'Cerrar',
  loading: 'Cargando…',
  loadFailed: 'No se pudo cargar el tablero.',
  empty: 'Aún no hay ideas.',
  upvote: 'Votar a favor',
  downvote: 'Votar en contra',
  comments: '{count} comentarios',
  commentSingular: '{count} comentario',
  fewVotes: 'Aún pocos votos – el resultado puede cambiar mucho',
  approval: '{value}\u00a0% de aprobación',
  back: 'Volver',
  readOnlyHint: 'Para votar y comentar, el tablero se abre en una pestaña nueva.',
  openBoard: 'Abrir tablero',
  openIdea: 'Abrir en el tablero',
  commentLabel: 'Comentario',
  commentSubmit: 'Comentar',
  newIdea: 'Nueva idea',
  titleLabel: 'Título',
  bodyLabel: 'Descripción',
  submit: 'Enviar idea',
  submitted: '¡Gracias! Tu idea se ha enviado.',
  submittedPending: '¡Gracias! Tu idea aparecerá en cuanto el equipo la apruebe.',
  actionFailed: 'No ha funcionado. Inténtalo de nuevo.',
  signedInAs: 'Conectado como {name}',
} satisfies typeof de

export default es
