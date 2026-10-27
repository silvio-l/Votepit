// machine-drafted, needs native review
import type de from './boardQr.de'

const fr = {
  show: 'Afficher le code QR',
  hide: 'Masquer le code QR',
  alt: 'Code QR pour {url}',
  hint: 'Le code contient uniquement le lien public du tableau, aucune donnée de connexion.',
  downloadSvg: 'Télécharger en SVG',
  downloadPng: 'Télécharger en PNG',
  openPoster: "Ouvrir l'affiche imprimable",
  posterRegion: 'Affiche',
  posterCallToAction: 'Proposez vos idées et votez',
  posterScanHint: 'Scannez le code ou ouvrez le lien',
  posterPrint: 'Imprimer',
  posterBack: 'Retour aux paramètres du tableau',
  posterPrintHint:
    'Tient sur une page A4 au format portrait. Les menus et les boutons ne sont pas imprimés.',
  posterPrivate:
    "Les tableaux privés n'ont pas de code QR : leur lien ne fonctionne que pour les membres connectés. Rendez le tableau public ou non répertorié pour créer une affiche.",
} satisfies typeof de

export default fr
