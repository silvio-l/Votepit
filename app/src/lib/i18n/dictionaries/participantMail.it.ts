// machine-drafted, needs native review
import type de from './participantMail.de'

const it = {
  heading: 'Email sulle idee',
  hint: 'Con quale frequenza ricevi email sulle idee a cui partecipi. Ogni email spiega il motivo e contiene un link per annullare l’iscrizione.',
  myIdeasLabel: 'Le tue idee',
  myIdeasHint: 'Cambi di stato e risposte alle idee che hai scritto.',
  participatedLabel: 'Idee che sostieni o segui',
  participatedHint: 'Idee per cui hai votato, commentato o che segui.',
  newIdeasLabel: 'Nuove idee nelle bacheche a cui partecipi',
  newIdeasHint:
    'Solo se lo attivi: una panoramica delle nuove idee una volta a settimana. Disattivato di default.',
  cadenceInstant: 'Subito',
  cadenceDaily: 'Una volta al giorno',
  cadenceWeekly: 'Una volta a settimana',
  cadenceOff: 'Disattivato',
  save: 'Salva',
  saving: 'Salvataggio…',
  saved: 'Salvato.',
  saveError: 'Impossibile salvare le impostazioni. Riprova.',
  loadError: 'Impossibile caricare le impostazioni delle email.',
} satisfies typeof de

export default it
