import type de from './boardAssets.de'

const fr = {
  heading: 'Images et liens',
  subtitle:
    "Couverture, icône et image d'aperçu des liens, ainsi qu'un lien de retour et une note de pied de page pour la page de ton tableau.",
  coverHeading: 'Image de couverture',
  coverHint:
    "JPEG, PNG ou WebP, jusqu'à 4 Mo. Recommandé : 2400 × 400 px (6:1). Placez l'essentiel au centre : sur les grands écrans, seule la partie centrale de l'image est visible.",
  faviconHeading: "Icône de l'onglet du navigateur",
  faviconHint: 'Carrée, réduite à 64 × 64 px.',
  ogHeading: "Image d'aperçu au partage",
  ogHint: 'Affichée lors du partage du tableau. Recadrée en 1200 × 630 px.',
  chooseFile: 'Choisir une image',
  upload: 'Importer',
  remove: 'Retirer',
  uploading: 'Importation…',
  uploaded: 'Image enregistrée.',
  removed: 'Image retirée.',
  notSet: 'Aucune image pour le moment.',
  previewAlt: "Aperçu de l'image",
  lockedImages: 'Les images personnalisées ne sont pas incluses dans ton offre actuelle.',
  uploadFailed: "L'image n'a pas pu être enregistrée.",
  invalidUpload: "Ce n'est pas une image valide. JPEG, PNG et WebP sont autorisés.",
  fileTooLarge: 'Le fichier est trop volumineux.',
  removeFailed: "L'image n'a pas pu être retirée.",
  linkHeading: 'Lien de retour et pied de page',
  linkUrlLabel: 'Lien vers votre site',
  linkUrlHint: 'Destination du lien de retour, p. ex. https://exemple.org. Commence par https://.',
  linkLabelLabel: 'Texte du lien de retour',
  linkLabelHint:
    'Affiché en bas de votre board, p. ex. « Retour à exemple.org ». De 1 à 40 caractères.',
  footerLabel: 'Note de pied de page',
  footerHint:
    'Court texte tout en bas de votre board, p. ex. « Association Exemple ». Texte brut, jusqu’à 280 caractères.',
  linkSave: 'Enregistrer',
  linkSaved: 'Lien de retour et pied de page enregistrés.',
  linkSaveFailed: "Les informations n'ont pas pu être enregistrées.",
  linkLocked: 'Le lien de retour et le pied de page ne sont pas inclus dans ton offre actuelle.',
  planUpgradeLinkLabel: 'Voir les offres',
  logoDrop: 'Dépose une image ici ou choisis un fichier.',
  logoUnsupported:
    'JPEG, PNG et WebP sont autorisés. Les fichiers SVG ne sont pas admis pour les logos.',
  logoTooSmall: 'Le logo est trop petit. Au moins 200 × 200 px.',
  logoTooLarge: 'Les dimensions de l’image sont trop grandes.',
  logoHint:
    'JPEG, PNG ou WebP, au moins 200 × 200 px. Adapté en carré : photo de profil et icône de l’onglet. La transparence est conservée.',
  logoEmpty:
    'Aucun symbole d’application pour l’instant. Sans lui, la photo de profil affiche les initiales du board.',
  wordmarkHint:
    'JPEG, PNG ou WebP, au moins 200 px de chaque côté, p. ex. 1200 × 300 px. Logo large pour la barre supérieure ; la transparence est conservée.',
  wordmarkEmpty:
    'Aucune marque textuelle pour l’instant. La barre affiche alors le symbole ou les initiales.',
  platedTip:
    'Sur la couleur de votre barre supérieure, le logo serait à peine visible : une surface claire est donc placée automatiquement derrière. Pour les barres sombres, téléversez une variante claire ci-dessous.',
  wordmarkLightHint:
    'Pour les barres sombres. Utilisée seulement si elle se lit bien sur la barre ; sinon la marque ci-dessus reste.',
  wordmarkLightEmpty:
    "Pas encore de variante claire. Sur les barres sombres, la marque normale s'applique.",
  logoPendingAlt: 'Aperçu du logo sélectionné',
  logoLegacyHeading: 'Ancien logo (lien)',
  logoLegacyHint:
    'Ce lien n’est affiché que tant qu’aucun logo n’est importé. Importe une image pour le remplacer.',
  logoLegacyRemove: 'Retirer le lien',
  patternHeading: 'Motif de la couverture',
  patternHint: 'Affiché tant qu’aucune image de couverture propre n’est importée.',
  patternDefault: 'Par défaut',
  pattern_waves: 'Vagues',
  pattern_dots: 'Points',
  pattern_stripes: 'Rayures',
  pattern_bokeh: 'Bokeh',
  pattern_grid: 'Grille',
  uploadTermsTitle: 'Conditions pour vos propres images',
  uploadTermsIntro:
    "Avant d'importer une image pour votre board, lisez ces points. Vous les confirmez une fois pour votre compte ; nous ne demanderons à nouveau qu'en cas de modification des conditions.",
  uploadTermsPoint1:
    "Vous ne pouvez importer que des images pour lesquelles vous disposez de tous les droits nécessaires : vos propres images ou des images pour lesquelles vous avez une licence d'utilisation. Les marques et logos de tiers uniquement avec l'accord des titulaires de droits.",
  uploadTermsPoint2:
    "Les contenus illicites ne sont pas autorisés. Les personnes reconnaissables ne peuvent être montrées qu'avec leur consentement, et les données personnelles d'autrui uniquement lorsqu'une base juridique existe.",
  uploadTermsPoint3:
    "Nous vérifions le format et la taille, recalculons l'image et supprimons les données annexes, comme les informations de localisation. Sont acceptés : PNG, JPEG et WebP.",
  uploadTermsPoint4:
    "L'image est affichée publiquement sur la page de votre board, y compris dans les aperçus lorsque quelqu'un partage le lien. Si vous la remplacez ou la retirez, l'ancien fichier est supprimé. Supprimer le board ou le compte supprime aussi les images.",
  uploadTermsPoint5:
    "Vous êtes responsable de vos images et vous dégagez Votepit des réclamations de tiers qui s'y rapportent. Si une image est manifestement illicite ou qu'un signalement fondé nous parvient, nous pouvons la retirer et vous en informer.",
  uploadTermsPoint6:
    "Comme preuve, nous ne conservons que la date, la version de ces conditions, votre compte et votre identifiant d'utilisateur pseudonyme. Ni adresse e-mail, ni adresse IP.",
  uploadTermsCheckbox: "J'ai lu les conditions pour mes propres images et je les accepte.",
  uploadTermsAccept: 'Accepter et importer',
  uploadTermsCancel: 'Annuler',
  uploadTermsRequired: "Veuillez d'abord accepter les conditions pour vos propres images.",
  uploadTermsFailed: "Votre accord n'a pas pu être enregistré. Veuillez réessayer.",
} satisfies typeof de

export default fr
