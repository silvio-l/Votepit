// machine-drafted, needs native review
import type de from './inviteAcceptPage.de'

const es = {
  invalidTitle: 'Este enlace de invitación ya no funciona',
  invalidBody:
    'El enlace no es válido, ha caducado o ya se usó. Pide a la persona que te invitó un enlace nuevo.',
  acceptFailedFallback: 'No se pudo aceptar la invitación. Inténtalo de nuevo en un momento.',
  accepting: 'Aceptando invitación…',
  doneTitle: 'Bienvenido al equipo',
  doneBodyOwner: 'Ahora eres el propietario de esta cuenta.',
  doneBodyAdmin: 'Ahora eres administrador de esta cuenta.',
  doneBodyModerator: 'Ahora eres moderador de esta cuenta.',
  doneBodyMember: 'Ahora tienes acceso a los tableros privados de esta cuenta.',
  goToMembers: 'Ir a la vista de miembros',
  goToBoard: 'Ir al tablero',
  errorTitle: 'No disponible en este momento',
  teamLimitTitle: 'El equipo está completo',
  teamLimitBody:
    'Esta cuenta ha alcanzado el límite de miembros de su plan. Ponte en contacto con la persona que te invitó – tu enlace sigue siendo válido.',
  loginCta: 'Ir a iniciar sesión',
  retryCta: 'Reintentar',
  mismatchTitle: 'Has iniciado sesión con otra cuenta',
  mismatchBody:
    'Esta invitación es para otra dirección de correo. Inicia sesión con la cuenta invitada para aceptarla.',
  mismatchCurrentAccount: 'Sesión iniciada como: {publicId}',
  mismatchAliasHint:
    'Si recibiste este correo tú mismo: un reenvío o un buzón alias cuenta como una dirección distinta de aquella a la que se envió la invitación.',
  switchAccountCta: 'Cerrar sesión y cambiar de cuenta',
  switchingAccount: 'Cerrando sesión…',
} satisfies typeof de

export default es
