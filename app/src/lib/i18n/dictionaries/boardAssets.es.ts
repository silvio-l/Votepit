import type de from './boardAssets.de'

const es = {
  heading: 'Imágenes y enlaces',
  subtitle:
    'Portada, icono e imagen de vista previa para enlaces, además de un enlace de retorno y una nota al pie para la página de tu tablero.',
  coverHeading: 'Imagen de portada',
  coverHint:
    'JPEG, PNG o WebP, de hasta 4 MB. Recomendado: 2400 × 400 px (6:1). Pon lo importante en el centro: en pantallas anchas solo se ve la parte central de la imagen.',
  faviconHeading: 'Icono de la pestaña del navegador',
  faviconHint: 'Cuadrado, se reduce a 64 × 64 px.',
  ogHeading: 'Imagen de vista previa al compartir',
  ogHint: 'Se muestra al compartir el tablero. Se recorta a 1200 × 630 px.',
  chooseFile: 'Elegir imagen',
  upload: 'Subir',
  remove: 'Quitar',
  uploading: 'Subiendo…',
  uploaded: 'Imagen guardada.',
  removed: 'Imagen eliminada.',
  notSet: 'Aún no hay ninguna imagen.',
  previewAlt: 'Vista previa de la imagen',
  lockedImages: 'Las imágenes propias no están incluidas en tu plan actual.',
  uploadFailed: 'No se pudo guardar la imagen.',
  invalidUpload: 'No es una imagen válida. Se admiten JPEG, PNG y WebP.',
  fileTooLarge: 'El archivo es demasiado grande.',
  removeFailed: 'No se pudo eliminar la imagen.',
  linkHeading: 'Enlace de retorno y pie',
  linkUrlLabel: 'Enlace a tu sitio web',
  linkUrlHint:
    'Adonde lleva el enlace de retorno, p. ej. https://ejemplo.org. Empieza por https://.',
  linkLabelLabel: 'Texto del enlace de retorno',
  linkLabelHint:
    'Se muestra al pie de tu board, p. ej. «Volver a ejemplo.org». De 1 a 40 caracteres.',
  footerLabel: 'Nota al pie',
  footerHint:
    'Breve texto al final de tu board, p. ej. «Asociación Ejemplo». Texto plano, hasta 280 caracteres.',
  linkSave: 'Guardar',
  linkSaved: 'Enlace de retorno y pie guardados.',
  linkSaveFailed: 'No se pudieron guardar los datos.',
  linkLocked: 'El enlace de retorno y el pie no están incluidos en tu plan actual.',
  planUpgradeLinkLabel: 'Ver planes',
  logoDrop: 'Arrastra una imagen aquí o elige un archivo.',
  logoUnsupported: 'Se permiten JPEG, PNG y WebP. Los archivos SVG no se admiten para logos.',
  logoTooSmall: 'El logo es demasiado pequeño. Mínimo 200 × 200 px.',
  logoTooLarge: 'Las dimensiones de la imagen son demasiado grandes.',
  logoHint:
    'JPEG, PNG o WebP, de al menos 200 × 200 px. Se ajusta como cuadrado: foto de perfil e icono de la pestaña. Se conserva la transparencia.',
  logoEmpty:
    'Todavía no hay símbolo de la app. Sin él, la foto de perfil muestra las iniciales del board.',
  wordmarkHint:
    'JPEG, PNG o WebP, de al menos 200 px por lado, p. ej. 1200 × 300 px. Logotipo ancho para la barra superior; se conserva la transparencia.',
  wordmarkEmpty:
    'Todavía no hay logotipo para la barra. La barra muestra entonces el símbolo de la app o las iniciales.',
  platedTip:
    'Sobre el color de tu barra superior el logotipo apenas se vería, por eso se coloca automáticamente una superficie clara detrás. Para barras oscuras, sube abajo una variante clara.',
  wordmarkLightHint:
    'Para barras oscuras. Solo se usa si se lee bien sobre la barra; si no, se mantiene el logotipo de arriba.',
  wordmarkLightEmpty: 'Aún no hay variante clara. En barras oscuras se usa el logotipo normal.',
  logoPendingAlt: 'Vista previa del logo seleccionado',
  logoLegacyHeading: 'Logo anterior (enlace)',
  logoLegacyHint:
    'Este enlace solo se muestra mientras no haya un logo subido. Sube una imagen para reemplazarlo.',
  logoLegacyRemove: 'Quitar enlace',
  patternHeading: 'Patrón de portada',
  patternHint: 'Se muestra mientras no haya una imagen de portada propia.',
  patternDefault: 'Estándar',
  pattern_waves: 'Ondas',
  pattern_dots: 'Puntos',
  pattern_stripes: 'Rayas',
  pattern_bokeh: 'Bokeh',
  pattern_grid: 'Cuadrícula',
  uploadTermsTitle: 'Condiciones para tus propias imágenes',
  uploadTermsIntro:
    'Antes de subir una imagen para tu tablero, lee estos puntos. Los confirmas una vez para tu cuenta; volveremos a preguntar solo si cambian las condiciones.',
  uploadTermsPoint1:
    'Solo puedes subir imágenes para las que tengas todos los derechos necesarios: imágenes propias o imágenes para las que tengas licencia de uso. Las marcas y logotipos de terceros solo con el permiso de sus titulares.',
  uploadTermsPoint2:
    'No se permite el contenido ilícito. Las personas reconocibles solo pueden mostrarse con su consentimiento, y los datos personales de otras personas solo si existe una base jurídica.',
  uploadTermsPoint3:
    'Comprobamos el formato y el tamaño, volvemos a codificar la imagen y eliminamos datos adicionales, como la información de ubicación. Se aceptan PNG, JPEG y WebP.',
  uploadTermsPoint4:
    'La imagen se muestra públicamente en la página de tu tablero, también en las vistas previas cuando alguien comparte el enlace. Si la reemplazas o la eliminas, el archivo anterior se borra. Si eliminas el tablero o la cuenta, las imágenes también se borran.',
  uploadTermsPoint5:
    'Eres responsable de tus imágenes y eximes a Votepit de las reclamaciones de terceros que se basen en ellas. Si una imagen es claramente ilícita o llega una denuncia fundada, podemos retirarla y te lo comunicaremos.',
  uploadTermsPoint6:
    'Como prueba solo guardamos la fecha, la versión de estas condiciones, tu cuenta y tu identificador de usuario seudónimo. Ni dirección de correo electrónico ni dirección IP.',
  uploadTermsCheckbox: 'He leído las condiciones para mis propias imágenes y las acepto.',
  uploadTermsAccept: 'Aceptar y subir',
  uploadTermsCancel: 'Cancelar',
  uploadTermsRequired: 'Acepta primero las condiciones para tus propias imágenes.',
  uploadTermsFailed: 'No se pudo guardar tu aceptación. Inténtalo de nuevo.',
} satisfies typeof de

export default es
