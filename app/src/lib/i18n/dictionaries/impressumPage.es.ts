// machine-drafted, needs native review
import type de from './impressumPage.de'

const es = {
  loading: 'Cargando…',
  errorHeading: 'No se pudo cargar esta página',
  errorLoadFailed: 'Inténtalo de nuevo en un momento.',
  notFoundTitle: 'Este aviso legal no está aquí ahora mismo',
  notFoundDescription:
    'Puede que se haya movido o eliminado. La página de inicio es un buen punto para seguir.',
  retry: 'Reintentar',
  heading: 'Aviso legal',
  subtitle: 'Aviso legal de {name}, tal como lo proporciona su operador.',
  nameLabel: 'Operador',
  addressLabel: 'Dirección',
  emailLabel: 'Contacto',
  noticeHeading: 'Información adicional',
  missingTitle: 'No hay aviso legal disponible',
  missingBody: 'El operador de este tablero aún no ha proporcionado los datos del aviso legal.',
  privacyHeading: 'Protección de datos (RGPD)',
  privacyBody:
    'Este tablero funciona con Votepit. Votepit trata datos por encargo del operador indicado arriba, que es el responsable del tratamiento de este tablero conforme al RGPD. Para más detalles sobre cómo Votepit trata los datos como plataforma, consulta',
  privacyLinkLabel: 'la política de privacidad de Votepit',
} satisfies typeof de

export default es
