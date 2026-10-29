// machine-drafted, needs native review
import type de from './boardsAdminPage.de'

const it = {
  loading: 'Caricamento…',
  accessDeniedTitle: 'Accesso negato',
  accessDeniedBody: 'Questa pagina è accessibile solo agli amministratori dell’account.',
  loadError: 'Impossibile caricare la pagina.',
  title: 'Bacheche',
  subtitle: 'Tutte le bacheche del tuo account.',
  noBoards: 'Ancora nessuna bacheca creata.',
  createFirstBoard: 'Crea subito la tua prima bacheca',
  boardsAriaLabel: 'Bacheche',
  boardsHeading: 'Bacheche ({count})',
  frozen: 'Congelata',
  complianceFrozenAccount: 'Nascosta — completa i dati del gestore',
  complianceFrozenHintAccount:
    'Questa bacheca è nascosta perché l’identità del gestore legale a livello di account (nome, indirizzo ed e-mail di contatto) è incompleta. Clicca per completarla nella pagina Account — questo sblocca ogni bacheca che ha già confermato la propria dichiarazione del gestore.',
  complianceFrozenBoard: 'Nascosta — conferma l’avviso del gestore',
  complianceFrozenHintBoard:
    'Il nome del gestore legale a livello di account è impostato, ma questa bacheca richiede ancora la conferma del proprio avviso di responsabilità del gestore. Clicca per confermarlo qui.',
  manage: 'Impostazioni',
  view: 'Visualizza',
  copyLink: 'Copia link della bacheca',
  copyLinkCopied: 'Link copiato!',
  createHeading: 'Crea una nuova bacheca',
  nameLabel: 'Nome',
  namePlaceholder: 'es. Le nostre idee',
  slugLabel: 'Slug',
  slugPlaceholder: 'es. nostre-idee',
  slugHint: 'Suggerito in base al nome, ma liberamente modificabile.',
  createSubmitting: 'Creazione…',
  createSubmit: 'Crea bacheca',
  newBoard: 'Nuova bacheca',
  statBoards: 'Bacheche',
  statIdeas: 'Idee',
  statVotes: 'Voti',
  statCaptionAll: 'in tutte le bacheche',
  ideasCount: '{count} idee',
  ideasCountSingular: '{count} idea',
  votesCount: '{count} voti',
  votesCountSingular: '{count} voto',
  createHint:
    'Una bacheca è una pagina pubblica con un proprio link dove le persone propongono idee e votano.',
  emptyDescription:
    'Crea una bacheca e condividine il link — idee e voti si raccolgono lì da soli.',
  operatorAckLabel:
    'Confermo di essere il gestore legale di questa bacheca — responsabile delle sue regole della community, della moderazione e degli eventuali obblighi di note legali/privacy a livello di bacheca.',
} satisfies typeof de

export default it
