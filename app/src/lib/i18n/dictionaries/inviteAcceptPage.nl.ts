// machine-drafted, needs native review
import type de from './inviteAcceptPage.de'

const nl = {
  invalidTitle: 'Deze uitnodigingslink werkt niet meer',
  invalidBody:
    'De link is ongeldig, verlopen of al gebruikt. Vraag de persoon die je heeft uitgenodigd om een nieuwe link.',
  acceptFailedFallback:
    'De uitnodiging kon niet worden geaccepteerd. Probeer het zo meteen opnieuw.',
  accepting: 'Uitnodiging accepteren…',
  doneTitle: 'Welkom in het team',
  doneBodyOwner: 'Je bent nu eigenaar van dit account.',
  doneBodyAdmin: 'Je bent nu beheerder van dit account.',
  doneBodyModerator: 'Je bent nu moderator van dit account.',
  doneBodyMember: 'Je hebt nu toegang tot de privéboards van dit account.',
  goToMembers: 'Naar ledenoverzicht',
  goToBoard: 'Naar board',
  errorTitle: 'Tijdelijk niet bereikbaar',
  teamLimitTitle: 'Team is vol',
  teamLimitBody:
    'Dit account heeft het ledenlimiet van zijn abonnement bereikt. Neem contact op met de persoon die je heeft uitgenodigd – je link blijft geldig.',
  loginCta: 'Naar inloggen',
  retryCta: 'Opnieuw proberen',
  mismatchTitle: 'Verkeerd account ingelogd',
  mismatchBody:
    'Deze uitnodiging is voor een ander e-mailadres. Log in met het uitgenodigde account om haar te accepteren.',
  mismatchCurrentAccount: 'Momenteel ingelogd als: {publicId}',
  mismatchAliasHint:
    'Heb je deze e-mail wel zelf ontvangen? Een doorsturing of een aliasmailbox telt als een ander adres dan het adres waarnaar de uitnodiging is gestuurd.',
  switchAccountCta: 'Uitloggen en van account wisselen',
  switchingAccount: 'Uitloggen…',
} satisfies typeof de

export default nl
