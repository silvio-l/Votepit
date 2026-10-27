// machine-drafted, needs native review
import type de from './widgetSettings.de'

const es = {
  heading: 'Widget integrable',
  intro:
    'Muestra este tablero como widget de feedback en tu propio sitio web. Los usuarios conectados de tu app votan y comentan directamente en el widget.',
  statusLive: 'Activo',
  statusOff: 'Desactivado',
  loadFailed: 'No se pudo cargar la configuración del widget.',
  planLocked: 'El widget no está incluido en tu plan actual. Tu configuración se conserva.',
  planUpgradeLinkLabel: 'Ver planes',
  privateBoard:
    'Los tableros privados no se pueden integrar. Cambia la visibilidad a público o no listado.',
  originsLabel: 'Sitios permitidos',
  originsHint:
    'Una dirección por línea, p. ej. https://www.ejemplo.com (hasta {max}). Solo direcciones de sitio: sin subpáginas ni comodines. Vacío = widget desactivado.',
  originsInvalid:
    'Al menos una entrada no es un origen https válido sin ruta ni comodín, o hay más de {max}.',
  originsSave: 'Guardar orígenes',
  originsSaved: 'Guardado.',
  saveFailed: 'No se pudo guardar.',
  secretHeading: 'Clave secreta para reconocer a los usuarios conectados de tu app',
  secretConfigured: 'Hay un secreto configurado. No se vuelve a mostrar.',
  secretMissing: 'Aún no hay secreto. Sin él, el widget es de solo lectura para los visitantes.',
  secretRevealedTitle: 'Cópialo ahora: el secreto solo se muestra esta vez',
  secretFieldLabel: 'Clave secreta (guárdala solo en tu servidor)',
  secretRotate: 'Rotar secreto',
  secretGenerate: 'Generar secreto',
  rotateFailed: 'No se pudo generar el secreto.',
  rotateConfirmTitle: '¿Rotar el secreto?',
  rotateConfirmBody:
    'El secreto anterior deja de funcionar de inmediato y todas las sesiones abiertas del widget terminan. Tu sitio necesitará el nuevo secreto.',
  snippetLabel: 'Código de inserción',
  promptLabel: 'Prompt para tu agente de programación con IA',
  lastSeen: 'Visto por última vez en {origin} el {date}.',
  notSeen: 'El widget aún no se ha cargado en ningún sitio permitido.',
} satisfies typeof de

export default es
