// machine-drafted, needs native review
import type de from './editPage.de'

const es = {
  forbiddenError: 'Solo la persona que envió una idea puede editarla.',
  notFoundError: 'Esta idea ya no existe; puede que se haya retirado.',
  editWindowExpiredTitle: 'Plazo de edición vencido',
  editWindowExpiredError:
    'Las ideas solo se pueden editar durante las dos horas siguientes a su envío. Ese plazo ya terminó.',
  loadError: 'No se pudo cargar el formulario en este momento. Inténtalo de nuevo.',
  genericError: 'Algo salió mal. Inténtalo de nuevo.',
  loading: 'Cargando…',
  backToIdea: 'Volver a la idea',
  backToBoard: 'Volver al tablero',
  heading: 'Editar idea',
  subheading: 'Un título claro y un párrafo breve: texto sin formato.',
  websiteHoneypotLabel: 'Sitio web',
  titleLabel: 'Título',
  titlePlaceholder: 'Título breve y conciso',
  titleHint: '3–200 caracteres',
  bodyLabel: 'Descripción',
  bodyPlaceholder: '¿De qué se trata? ¿Qué debería cambiar?',
  bodyHint: 'Al menos 10 caracteres',
  saving: 'Guardando…',
  saveChanges: 'Guardar cambios',
} satisfies typeof de

export default es
