// machine-drafted, needs native review
import type de from './accountPage.de'

const es = {
  loading: 'Cargando…',
  accessDeniedTitle: 'Sin acceso',
  accessDeniedBody: 'Solo el propietario de la cuenta puede acceder a esta página.',
  loadError: 'No se pudo cargar la página.',
  title: 'Cuenta',
  subtitle: 'Exportación y eliminación de datos de esta cuenta.',
  accountHeading: 'Esta cuenta',
  nameLabel: 'Nombre',
  slugLabel: 'Dirección (nombre corto)',
  slugHint:
    'Parte de la dirección de la cuenta, p. ej. «miclub» en …/miclub/… Solo minúsculas, cifras y guiones. Única en todo Votepit.',
  slugChecking: 'Comprobando…',
  slugAvailable: 'Esta dirección está disponible.',
  slugTaken: 'Esta dirección ya está en uso.',
  savingEllipsis: 'Guardando…',
  renameSubmit: 'Guardar nombre y dirección',
  renameSaved: 'Guardado.',
  saveFailedGeneric: 'No se pudo guardar. Inténtalo de nuevo.',
  legalOperatorHeading: 'Datos del operador del tablero',
  legalOperatorBody:
    'Se muestran en tu página pública de aviso legal (enlazada desde cada tablero público). El nombre, la dirección y el correo de contacto son obligatorios antes de que un tablero pueda hacerse público o no listado.',
  legalOperatorNameLabel: 'Nombre del operador',
  legalOperatorNameHint: 'El nombre de tu empresa, o tu propio nombre si operas por tu cuenta.',
  legalOperatorNamePlaceholder: 'p. ej. Acme S.L., o tu propio nombre',
  legalOperatorAddressLabel: 'Dirección',
  legalOperatorAddressPlaceholder: 'Calle Mayor 123\n28001 Madrid\nEspaña',
  legalOperatorEmailLabel: 'Correo de contacto',
  legalOperatorEmailHint:
    'Una dirección accesible para consultas legales; se muestra en tu página de aviso legal.',
  legalOperatorEmailPlaceholder: 'contact@your-domain.com',
  legalOperatorNoticeLabel: 'Aviso legal adicional (opcional)',
  legalOperatorNoticeHint:
    'Todo lo que exija tu jurisdicción además del nombre, la dirección y el correo; p. ej. NIF/IVA, inscripción en el registro mercantil o autoridad supervisora.',
  legalOperatorNoticePlaceholder: 'p. ej. NIF/IVA DE123456789, Registro Mercantil HRB 12345',
  legalOperatorSubmit: 'Guardar datos del operador',
  legalOperatorSaved: 'Guardado.',
  complianceFrozenBannerHeading: 'Tableros ocultos: falta información legal',
  complianceFrozenBannerBody:
    'Estos tableros están ocultos para los visitantes porque falta información del operador legal o no está confirmada. Indica abajo el nombre del operador legal y confirma en cada tablero el aviso de responsabilidad del operador para desbloquearlos:',
  deletionScheduledHeading: 'La cuenta se está eliminando',
  deletionScheduledPart1:
    'Esta cuenta, incluidos todos los tableros, ideas y comentarios, se eliminará definitivamente el',
  deletionScheduledPart2Days: '(',
  deletionScheduledPart3Days: 'días restantes).',
  deletionScheduledPart2Hours: '(',
  deletionScheduledPart3Hours: 'horas restantes).',
  deletionScheduledHint: 'Mientras dure el periodo de gracia, puedes cancelar la eliminación aquí.',
  deletionScheduledUndo: 'Cancelar eliminación',
  deletionScheduledUndoing: 'Cancelando…',
  deletionScheduledUndoFailed: 'No se pudo cancelar la eliminación.',
  telemetryHeading: 'Ayuda a mejorar Votepit',
  telemetryToggleLabel: 'Enviar datos de uso a Votepit',
  telemetryStateOn:
    'Activada: solo contadores de instalación y uso de funciones, sin contenido de tableros ni de usuarios.',
  telemetryStateOff: 'Desactivada: no se envía nada a Votepit.',
  telemetryHint:
    'Votepit solo sabe qué páginas se abren y qué acciones se realizan, p. ej. «se creó un tablero». Nada del contenido de tus tableros, ninguna idea y ninguna dirección de correo. No se usan cookies, y la dirección IP se acorta antes de guardarse. Activada por defecto; puedes desactivarla en cualquier momento.',
  telemetrySaved: 'Guardado.',
  telemetrySaveFailed: 'No se pudo guardar este ajuste.',
  exportHeading: 'Exportar mis datos',
  exportBody:
    'Descarga una copia completa de todos los datos de esta cuenta (tableros, ideas, votos, comentarios, miembros, invitaciones y más), como documento JSON o como archivo ZIP con un archivo CSV por tabla.',
  exportFailed: 'No se pudo descargar la exportación.',
  exportingJson: 'Exportando…',
  exportJson: 'Exportar como JSON',
  exportingCsv: 'Exportando…',
  exportCsv: 'Exportar como CSV (ZIP)',
  dangerZoneHeading: 'Zona de peligro',
  dangerZoneBody:
    'Elimina definitivamente esta cuenta: todos los tableros, ideas, votos, comentarios y membresías. El inicio de sesión global (cuenta de correo) se mantiene; solo desaparece esta cuenta. Una vez solicitada, tienes 48 horas para cancelar la eliminación aquí.',
  deleteAccountButton: 'Eliminar cuenta',
  deleteAccountConfirmLabel: 'Escribe "{slug}" para confirmar',
  deleteAccountConfirmPlaceholder: 'Dirección de tu cuenta',
  deleteAccountConfirmSubmit: 'Eliminar definitivamente',
  deleteAccountConfirmCancel: 'Cancelar',
  deleteAccountFailed: 'No se pudo solicitar la eliminación.',
  deletingAccount: 'Solicitando…',
} satisfies typeof de

export default es
