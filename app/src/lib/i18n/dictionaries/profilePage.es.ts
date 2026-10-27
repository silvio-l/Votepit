// machine-drafted, needs native review
import type de from './profilePage.de'

const es = {
  loading: 'Cargando…',
  title: 'Perfil',
  subtitle:
    'Tu foto, enlaces sociales, cuentas y seguridad de inicio de sesión, todo en un solo lugar.',
  accountsHeading: 'Tus cuentas',
  noAccounts: 'Aún no eres miembro de ninguna cuenta.',
  noAccountsHint:
    'Aun así puedes votar en todas partes y gestionar tu perfil; no necesitas un tablero propio para ello.',
  noAccountsCta: 'Crear tu propio tablero',
  accountColumn: 'Cuenta',
  manageLink: 'Gestionar',
  logoutCta: 'Cerrar sesión',
  profileLoadError:
    'No se pudieron cargar tu foto, tus enlaces sociales ni tu ajuste de privacidad.',
  profileLoadRetryCta: 'Reintentar',
  loggedInHint:
    'Por privacidad, Votepit nunca guarda tu dirección de correo en texto plano (solo como hash unidireccional), por lo que no se puede mostrar aquí.',
  userIdHint: 'Tu ID de usuario: #{id}',

  // Username (optional public display name)
  usernameHeading: 'Nombre visible',
  usernameHint:
    'Opcional: se muestra en lugar de "Votante" en tus ideas y comentarios, pero solo mientras tu perfil sea visible públicamente. Único en toda la plataforma.',
  usernameLabel: 'Nombre visible',
  usernamePlaceholder: 'p. ej. mariagarcia',
  usernameSave: 'Guardar',
  usernameSaving: 'Guardando…',
  usernameSaved: 'Guardado.',
  usernameTakenError: 'Este nombre visible ya está en uso.',
  usernameInvalidError:
    'Usa entre 3 y 30 caracteres, empezando por una letra (letras, dígitos, guion bajo).',
  usernameGenericError: 'Algo salió mal. Inténtalo de nuevo.',

  // Avatar (profile-avatar-social)
  avatarHeading: 'Foto de perfil',
  avatarHint: 'JPG, PNG, GIF o WebP, máx. 5 MB. Se recorta automáticamente a 256×256.',
  avatarUpload: 'Subir foto',
  avatarUploading: 'Subiendo…',
  avatarRemove: 'Eliminar',
  avatarRemoving: 'Eliminando…',
  avatarFileTooLarge: 'El archivo supera los 5 MB.',
  avatarInvalidType: 'Elige una imagen (JPG, PNG, GIF o WebP). SVG no es compatible.',
  avatarUploadError: 'No se pudo procesar la imagen. Prueba con otra.',
  avatarGenericError: 'Algo salió mal. Inténtalo de nuevo.',

  // Social links (profile-avatar-social security redesign)
  socialLinksHeading: 'Enlaces sociales',
  socialLinksHint:
    'Hasta 4 enlaces de perfil fijos: introduce tu usuario o tu dominio, nunca una URL completa.',
  socialLinksWebsiteLabel: 'Sitio web',
  socialLinksXLabel: 'X (Twitter)',
  socialLinksYoutubeLabel: 'YouTube',
  socialLinksGithubLabel: 'GitHub',
  socialLinksHandlePlaceholder: 'usuario',
  socialLinksUsernamePlaceholder: 'nombre de usuario',
  socialLinksSave: 'Guardar',
  socialLinksSaving: 'Guardando…',
  socialLinksSaved: 'Guardado.',
  socialLinksInvalidWebsite: 'Introduce solo el dominio, p. ej. example.com.',
  socialLinksInvalidXHandle:
    'Introduce un usuario de X válido (letras, dígitos, guion bajo, máx. 15 caracteres).',
  socialLinksInvalidYoutubeHandle:
    'Introduce un usuario de YouTube válido sin "@" (3–30 caracteres).',
  socialLinksInvalidGithubUsername:
    'Introduce un nombre de usuario de GitHub válido (máx. 39 caracteres).',
  socialLinksSaveRejected: 'Se rechazó uno de los valores. Revisa el campo resaltado.',
  socialLinksGenericError: 'Algo salió mal. Inténtalo de nuevo.',

  // Linked accounts (OAuth login)
  oauthHeading: 'Cuentas vinculadas',
  oauthIntro: 'Cuentas de Google o GitHub con las que puedes iniciar sesión.',
  oauthEmpty: 'No hay ninguna cuenta vinculada.',
  oauthLinkedSince: 'Vinculada desde {date}',
  oauthUnlink: 'Desvincular',
  oauthUnlinkCancel: 'Cancelar',
  oauthUnlinkFailed: 'No se pudo desvincular. Inténtalo de nuevo.',
  oauthUnlinkConfirmTitle: '¿Desvincular esta cuenta?',
  oauthUnlinkConfirmBody:
    'Ya no podrás iniciar sesión con {provider}; con tu dirección de correo siempre podrás iniciar sesión mediante un enlace mágico.',

  // Privacy (profile-visibility)
  privacyHeading: 'Privacidad',
  privacyToggleLabel: 'Perfil visible públicamente',
  privacyStateVisible:
    'Los demás ven tu foto de perfil y tus enlaces sociales en tus ideas y comentarios.',
  privacyStateAnonymous:
    'Apareces en ideas y comentarios como "Votante", sin foto de perfil ni enlaces sociales.',
  privacyHint:
    'Por defecto eres anónimo. Si haces visible tu perfil, los demás podrán ver tu foto de perfil y tus enlaces sociales en tus ideas y comentarios, y abrir tu perfil.',
  privacyRoleBadgeNote:
    'Independientemente de esto: si eres propietario o moderador de una cuenta, ese rol siempre se muestra allí como insignia.',
  privacySaved: 'Guardado.',
  privacyGenericError: 'No se pudo guardar el ajuste. Inténtalo de nuevo.',

  // Notifications (notification-preferences)
  notificationsHeading: 'Notificaciones',
  notificationsHint: 'Elige sobre qué quieres recibir notificaciones, en la app y por correo.',
  notificationsIdeaCommentLabel: 'Nuevo comentario en mi idea',
  notificationsThreadReplyLabel: 'Nueva respuesta en un hilo en el que comenté',
  notificationsAbuseReportLabel: 'Nueva denuncia de contenido (operador de la plataforma)',
  notificationsSupportTicketLabel:
    'Ticket de soporte nuevo o respondido (operador de la plataforma)',
  notificationsWeeklyDigestLabel: 'Resumen semanal de mis tableros',
  notificationsWeeklyDigestHint:
    'Una vez por semana: ideas nuevas, más votadas, más rechazadas y más polémicas. Activado por defecto para propietarios; las semanas sin actividad se omiten.',
  notificationsInAppColumn: 'En la app',
  notificationsEmailColumn: 'Correo',
  notificationsEmailFieldLabel: 'Correo para notificaciones',
  notificationsEmailPlaceholder: 'name@example.com',
  notificationsEmailSubmit: 'Enviar enlace de confirmación',
  notificationsEmailSubmitting: 'Enviando…',
  notificationsEmailSentHint: 'Correo de confirmación enviado: haz clic en el enlace del correo.',
  notificationsEmailInvalidError: 'Introduce una dirección de correo válida.',
  notificationsEmailConfirmedPrefix: 'Correo confirmado:',
  notificationsEmailRemove: 'Eliminar correo',
  notificationsEmailRemoving: 'Eliminando…',
  notificationsSaved: 'Guardado.',
  notificationsGenericError: 'No se pudo guardar el ajuste. Inténtalo de nuevo.',

  // Password
  passwordHeading: 'Contraseña',
  passwordSubtitle: 'Opcional: además del enlace de inicio de sesión por correo.',
  currentPasswordLabel: 'Contraseña actual',
  newPasswordLabel: 'Nueva contraseña',
  setPasswordLabel: 'Contraseña',
  confirmPasswordLabel: 'Confirmar contraseña',
  passwordMinLengthHint: 'Al menos 10 caracteres.',
  passwordMismatchError: 'Las contraseñas no coinciden.',
  passwordSaveCta: 'Guardar',
  passwordSaving: 'Guardando…',
  passwordSavedSuccess: 'Contraseña guardada.',
  passwordGenericError: 'No se pudo guardar la contraseña.',
  passwordResetLinkCta: 'Enviarme un enlace de restablecimiento por correo',
  passwordResetLinkCancelCta: 'Cancelar',
  passwordResetLinkEmailLabel: 'Confirma tu correo',
  passwordResetLinkEmailHint:
    'No podemos consultar tu dirección; vuelve a escribirla para confirmar.',
  passwordResetLinkSendCta: 'Enviar enlace de restablecimiento',
  passwordResetLinkSending: 'Enviando…',
  passwordResetLinkSuccess:
    'Revisa tu bandeja de entrada: el enlace de restablecimiento está en camino.',
  passwordResetLinkMismatchError: 'No coincide con el correo de esta cuenta.',
  passwordResetLinkGenericError:
    'No se pudo enviar el enlace de restablecimiento. Inténtalo de nuevo.',

  // 2FA
  totpHeading: 'Autenticación en dos pasos',
  totpSubtitle: 'Opcional: añade protección extra con una app de autenticación.',
  totpEnableCta: 'Activar 2FA',
  totpEnabledStatus: '2FA está activa.',
  totpSetupInstructions:
    'Escanea el código QR con tu app de autenticación (p. ej. Google Authenticator, 1Password) e introduce el código que muestra.',
  totpQrAlt: 'Código QR para la app de autenticación',
  totpManualEntryHint: 'O introdúcelo manualmente:',
  totpCodeLabel: 'Código',
  totpConfirmCta: 'Confirmar',
  totpConfirming: 'Verificando…',
  totpCancelCta: 'Cancelar',
  totpConfirmError: 'El código no es válido o ha caducado.',
  totpGenericError: 'No se pudo iniciar la configuración de 2FA.',
  totpRegenerateCta: 'Regenerar códigos de respaldo',
  totpDisableCta: 'Desactivar 2FA',
  totpDisableConfirmPrompt: 'Confirma la desactivación con tu contraseña o un código 2FA.',
  totpRegenerateConfirmPrompt:
    'Confirma la regeneración de los códigos de respaldo con tu contraseña o un código 2FA.',
  totpConfirmationFailedError: 'La confirmación falló.',
  useCurrentPasswordInstead: 'Usar tu contraseña actual',
  useCodeInsteadOfPassword: 'Usar un código 2FA',
  backupCodesTitle: 'Códigos de respaldo',
  backupCodesSetupHint:
    'Estos 10 códigos solo se muestran ahora. Guárdalos en un lugar seguro: cada uno funciona una vez si pierdes el acceso a tu app de autenticación.',
  backupCodesRegenerateHint:
    'Los códigos de respaldo anteriores ya no son válidos. Guarda estos nuevos en un lugar seguro.',
  backupCodesAckCta: 'Códigos guardados',
  myDataHeading: 'Tus datos',
  myDataBody:
    'Descarga todo lo que Votepit guarda sobre ti — perfil, ideas, comentarios, votos, reacciones, preferencias de notificación y servicios de inicio de sesión vinculados, en todas las cuentas (JSON).',
  myDataDownload: 'Descargar mis datos',
  myDataDownloading: 'Preparando…',
  myDataDownloadFailed: 'La descarga ha fallado. Inténtalo de nuevo.',
  deleteMeHeading: 'Eliminar cuenta',
  deleteMeBody:
    'Elimina definitivamente tu cuenta de Votepit tras 48 horas. Tus ideas y comentarios se mantienen como «Usuario eliminado», tus votos siguen contando de forma anónima — se borran todos los datos personales.',
  deleteMeButton: 'Eliminar mi cuenta',
  deleteMeOwnerBlocked:
    'Todavía eres propietario de una cuenta. Elimínala primero en su configuración (o contacta con soporte para transferirla).',
  deleteMeConfirmLabel: 'Para confirmar, introduce tu dirección de correo',
  deleteMeConfirmSubmit: 'Confirmar eliminación',
  deleteMeSubmitting: 'Programando…',
  deleteMeCancel: 'Cancelar',
  deleteMeFailed: 'No se pudo programar la eliminación.',
  deleteMePendingTitle: 'Eliminación programada',
  deleteMePendingBody:
    'Tu cuenta se eliminará definitivamente el {date}. Hasta entonces puedes cancelar la eliminación.',
  deleteMeUndo: 'Cancelar eliminación',
  deleteMeUndoing: 'Cancelando…',
  deleteMeUndoFailed: 'No se pudo cancelar la eliminación.',
} satisfies typeof de

export default es
