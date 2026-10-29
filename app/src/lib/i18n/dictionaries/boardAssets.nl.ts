import type de from './boardAssets.de'

const nl = {
  heading: 'Afbeeldingen en links',
  subtitle:
    'Omslag, pictogram en voorbeeldafbeelding voor links, plus een terugverwijzing en voettekst voor de pagina van je board.',
  coverHeading: 'Omslagafbeelding',
  coverHint:
    'JPEG, PNG of WebP, maximaal 4 MB. Aanbevolen: 2400 × 400 px (6:1). Zet belangrijke content in het midden: op brede schermen is alleen het middelste deel van de afbeelding zichtbaar.',
  faviconHeading: 'Icoon in het browsertabblad',
  faviconHint:
    'Vierkant, minimaal 180 × 180 px. Gebruikt voor het browsertabblad en het pictogram op het beginscherm.',
  ogHeading: 'Voorbeeldafbeelding bij delen',
  ogHint: 'Wordt getoond wanneer de board wordt gedeeld. Bijgesneden naar 1200 × 630 px.',
  chooseFile: 'Afbeelding kiezen',
  remove: 'Verwijderen',
  uploading: 'Uploaden…',
  dropChoose: 'Afbeelding kiezen',
  dropReplace: 'Andere afbeelding kiezen',
  dropOr: 'of sleep hem hierheen – wordt meteen opgeslagen',
  dropRelease: 'Loslaten om te uploaden',
  uploaded: 'Afbeelding opgeslagen.',
  removed: 'Afbeelding verwijderd.',
  notSet: 'Nog geen afbeelding ingesteld.',
  previewAlt: 'Voorbeeld van de afbeelding',
  lockedImages: 'Eigen afbeeldingen zijn niet inbegrepen in je huidige abonnement.',
  uploadFailed: 'De afbeelding kon niet worden opgeslagen.',
  invalidUpload: 'Dit is geen geldige afbeelding. JPEG, PNG en WebP zijn toegestaan.',
  fileTooLarge: 'Het bestand is te groot.',
  imageTooSmall: 'De afbeelding is te klein. Minimaal {size} × {size} px.',
  removeFailed: 'De afbeelding kon niet worden verwijderd.',
  linkHeading: 'Terugverwijzing en voettekst',
  linkUrlLabel: 'Link naar je website',
  linkUrlHint:
    'Waar de terugverwijzing naartoe gaat, bijv. https://voorbeeld.nl. Begin met https://.',
  linkLabelLabel: 'Tekst van de terugverwijzing',
  linkLabelHint: 'Onderaan je board, bijv. „Terug naar voorbeeld.nl“. 1 tot 40 tekens.',
  footerLabel: 'Voettekst',
  footerHint:
    'Korte tekst helemaal onderaan je board, bijv. „Vereniging Voorbeeld“. Platte tekst, tot 280 tekens.',
  linkSave: 'Opslaan',
  linkSaved: 'Terugverwijzing en voettekst opgeslagen.',
  linkSaveFailed: 'De gegevens konden niet worden opgeslagen.',
  linkLocked: 'Terugverwijzing en voettekst zijn niet inbegrepen in je huidige abonnement.',
  planUpgradeLinkLabel: 'Abonnementen bekijken',
  logoUnsupported:
    'JPEG, PNG en WebP zijn toegestaan. SVG-bestanden zijn niet toegestaan voor logo’s.',
  logoTooSmall: 'Het logo is te klein. Minimaal 200 × 200 px.',
  logoTooLarge: 'De afmetingen van de afbeelding zijn te groot.',
  logoHint:
    'JPEG, PNG of WebP, minimaal 200 × 200 px. Vierkant ingepast: profielfoto en pictogram in het tabblad. Transparantie blijft behouden.',
  logoEmpty:
    'Nog geen app-symbool. Zonder symbool toont de profielfoto de initialen van het board.',
  wordmarkHint:
    'JPEG, PNG of WebP, minimaal 200 px per zijde, bijv. 1200 × 300 px. Breed logo voor de bovenbalk; transparantie blijft behouden.',
  wordmarkEmpty: 'Nog geen wordmark. De bovenbalk toont dan het app-symbool of de initialen.',
  platedTip:
    'Op de kleur van je bovenbalk zou het logo nauwelijks zichtbaar zijn, daarom komt er automatisch een lichte vlak achter. Voor donkere bovenbalken kun je hieronder een lichte variant uploaden.',
  wordmarkLightHint:
    'Voor donkere bovenbalken. Alleen gebruikt als hij goed leesbaar op de balk is; anders blijft het wordmark hierboven.',
  wordmarkLightEmpty:
    'Nog geen lichte variant. Op donkere bovenbalken geldt dan het normale wordmark.',
  logoPendingAlt: 'Voorbeeld van het gekozen logo',
  logoLegacyHeading: 'Vorig logo (link)',
  logoLegacyHint:
    'Deze link wordt alleen getoond zolang er geen logo is geüpload. Upload een afbeelding om hem te vervangen.',
  logoLegacyRemove: 'Link verwijderen',
  patternHeading: 'Patroon van de omslag',
  patternHint: 'Wordt getoond zolang er geen eigen omslagafbeelding is geüpload.',
  patternDefault: 'Standaard',
  pattern_waves: 'Golven',
  pattern_dots: 'Stippen',
  pattern_stripes: 'Strepen',
  pattern_bokeh: 'Bokeh',
  pattern_grid: 'Raster',
  uploadTermsTitle: 'Voorwaarden voor eigen afbeeldingen',
  uploadTermsIntro:
    'Lees deze punten voordat je een afbeelding voor je board uploadt. Je bevestigt ze één keer voor je account; we vragen pas opnieuw als de voorwaarden veranderen.',
  uploadTermsPoint1:
    "Je mag alleen afbeeldingen uploaden waarvoor je alle nodige rechten hebt: eigen afbeeldingen of afbeeldingen waarvoor je een gebruiksrecht hebt. Merken en logo's van derden alleen met toestemming van de rechthebbende.",
  uploadTermsPoint2:
    'Onwettige inhoud is niet toegestaan. Herkenbare personen mag je alleen tonen met hun toestemming, en persoonsgegevens van anderen alleen als daar een wettelijke grondslag voor is.',
  uploadTermsPoint3:
    'We controleren formaat en grootte, rekenen de afbeelding opnieuw uit en verwijderen extra gegevens zoals locatie-informatie. Toegestaan zijn PNG, JPEG en WebP.',
  uploadTermsPoint4:
    'De afbeelding wordt openbaar getoond op de pagina van je board, ook in voorvertoningen wanneer iemand de link deelt. Vervang of verwijder je de afbeelding, dan wordt het oude bestand gewist. Verwijder je het board of het account, dan worden de afbeeldingen ook gewist.',
  uploadTermsPoint5:
    'Je bent verantwoordelijk voor je afbeeldingen en vrijwaart Votepit van aanspraken van derden die erop gebaseerd zijn. Is een afbeelding duidelijk onrechtmatig of komt er een terechte melding binnen, dan kunnen we de afbeelding verwijderen en laten we je dat weten.',
  uploadTermsPoint6:
    'Als bewijs bewaren we alleen het tijdstip, de versie van deze voorwaarden, je account en je pseudonieme gebruikers-ID. Geen e-mailadres en geen IP-adres.',
  uploadTermsCheckbox: 'Ik heb de voorwaarden voor eigen afbeeldingen gelezen en accepteer ze.',
  uploadTermsAccept: 'Accepteren en uploaden',
  uploadTermsCancel: 'Annuleren',
  uploadTermsRequired: 'Accepteer eerst de voorwaarden voor eigen afbeeldingen.',
  uploadTermsFailed: 'Je akkoord kon niet worden opgeslagen. Probeer het opnieuw.',
} satisfies typeof de

export default nl
