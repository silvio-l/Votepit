<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Domain\MarketingConsent;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailLanguage;
use Votepit\Mail\MailTemplate;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Persistence\MarketingConsentRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\TokenVault;

/**
 * Profile toggle for the product-news / promotion consent (migration 0112,
 * W1). Only exists when a hosted installation enables marketing
 * (Config::marketingConsentEnabled); otherwise every route answers 404 and
 * nothing is read or written — self-host stays unchanged.
 *
 * GET    /account/marketing-consent         — own status (AuthZ: user).
 * PUT    /account/marketing-consent         — body { enabled }. true: pending
 *                                             + confirmation mail (double opt-in);
 *                                             false: withdrawn at once (AuthZ: user, CSRF).
 * GET    /account/marketing-consent/confirm — redeems the emailed link (AuthZ: user,
 *                                             GET → CSRF-exempt, same pattern as
 *                                             /account/notification-email/confirm).
 *
 * Own row only: every route acts on the session user id, never on client input.
 * The confirmation token is random, stored as SHA-256 hash only, single use and
 * valid for MarketingConsent::CONFIRM_TTL_SECONDS. The address the mail goes to is
 * the user's confirmed notification e-mail: a profile opt-in without one is refused.
 */
final readonly class MarketingConsentAction
{
    public function __construct(
        private Config $config,
        private UserRepository $users,
        private MarketingConsentRepository $consents,
        private TokenVault $vault,
        private ?Mailer $mailer,
        private SmtpConfigResolver $smtpResolver,
        private AuditLogger $audit,
    ) {}

    /** GET /account/marketing-consent */
    public function status(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->marketingConsentEnabled) {
            return $this->notFound($response);
        }

        $consent = $this->consents->findForUser($this->currentUserId($request));

        return $this->json($response, 200, [
            'status'       => $consent['status'] ?? null,
            'text_version' => MarketingConsent::TEXT_VERSION,
        ]);
    }

    /** PUT /account/marketing-consent — body: { enabled: bool } */
    public function toggle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->marketingConsentEnabled) {
            return $this->notFound($response);
        }

        $userId = $this->currentUserId($request);
        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];
        if (!array_key_exists('enabled', $body) || !is_bool($body['enabled'])) {
            return $this->error($response, 422, 'validation_error', 'Expected a boolean "enabled".');
        }

        $now = new \DateTimeImmutable();

        if ($body['enabled'] === false) {
            // Opt-out (and the § 7 Abs. 3 objection path): immediate, no confirmation needed.
            $this->consents->withdraw($userId, $now);
            $this->audit->log('marketing_consent.withdrawn', ['user_id' => $userId]);

            return $this->json($response, 200, ['status' => MarketingConsent::STATUS_WITHDRAWN]);
        }

        $current = $this->consents->findForUser($userId);
        if (($current['status'] ?? null) === MarketingConsent::STATUS_CONFIRMED) {
            // Double submit of an opt-in that already went through: nothing to send.
            return $this->json($response, 200, ['status' => MarketingConsent::STATUS_CONFIRMED]);
        }

        $settings = $this->users->findNotificationSettings($userId);
        $address  = is_array($settings) ? ($settings['notification_email'] ?? null) : null;
        if (!is_string($address) || $address === '') {
            return $this->error(
                $response,
                422,
                'notification_email_required',
                'Confirm a notification e-mail address first, so the confirmation can be sent there.',
            );
        }

        $pair      = $this->vault->generate();
        $expiresAt = $now->add(new \DateInterval('PT' . MarketingConsent::CONFIRM_TTL_SECONDS . 'S'));
        $this->consents->requestConfirmation(
            $userId,
            MarketingConsent::SOURCE_PROFILE,
            $pair['hash'],
            $expiresAt,
            $now,
        );

        $link        = $this->config->appUrl . '/account/marketing-consent/confirm?token=' . $pair['token'];
        $mailToUse   = $this->mailer ?? $this->smtpResolver->buildMailer(null);
        $language    = MailLanguage::resolve($settings['digest_language'] ?? null);
        $confirmMail = self::confirmMail($link, $language);
        $mailToUse->send(
            $address,
            $confirmMail['subject'],
            $confirmMail['text'],
            $confirmMail['html'],
            $confirmMail['image'],
        );
        $this->audit->log('marketing_consent.requested', ['user_id' => $userId]);

        return $this->json($response, 200, ['status' => MarketingConsent::STATUS_PENDING]);
    }

    /** GET /account/marketing-consent/confirm?token=<plaintext> */
    public function confirm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->marketingConsentEnabled) {
            return $this->notFound($response);
        }

        $userId = $this->currentUserId($request);
        $params = $request->getQueryParams();
        $token  = is_string($params['token'] ?? null) ? $params['token'] : '';
        $now    = new \DateTimeImmutable();

        // A second click on the link (or a link redeemed before) finds the consent already
        // confirmed: that is success. Only the session owner's own row is read here.
        $current = $this->consents->findForUser($userId);
        if (($current['status'] ?? null) === MarketingConsent::STATUS_CONFIRMED) {
            return $this->json($response, 200, ['ok' => true, 'status' => MarketingConsent::STATUS_CONFIRMED]);
        }

        $row = $token !== '' ? $this->consents->findPendingByConfirmTokenHash($this->vault->hash($token), $now) : null;

        // Fail-secure: unknown, tampered, expired or already used token, or a token that
        // belongs to a DIFFERENT user than the one logged in — never confirm into another account.
        if (!is_array($row) || (int) $row['user_id'] !== $userId) {
            $this->audit->log('marketing_consent.confirm_failed', ['user_id' => $userId]);

            return $this->error($response, 400, 'invalid_token', 'The link is invalid or has expired.');
        }

        // The delivery address goes live only now, from the confirmed notification e-mail.
        $settings = $this->users->findNotificationSettings($userId);
        $address  = is_array($settings) ? ($settings['notification_email'] ?? null) : null;
        if (!is_string($address) || $address === '') {
            return $this->error(
                $response,
                422,
                'notification_email_required',
                'Confirm a notification e-mail address first, so the confirmation can be sent there.',
            );
        }

        $this->consents->markConfirmed($userId, $now);
        $this->consents->setMarketingEmail($userId, $address);
        $this->audit->log('marketing_consent.confirmed', ['user_id' => $userId]);

        return $this->json($response, 200, ['ok' => true, 'status' => MarketingConsent::STATUS_CONFIRMED]);
    }

    /**
     * Copy of the product-news confirmation mail per language (double opt-in).
     * Each entry carries the same keys; es/fr/it/nl are machine-drafted.
     */
    private const TEXTS = [
        'en' => [
            'subject'  => 'Confirm your Votepit product news',
            'heading'  => 'Confirm product news',
            'hello'    => 'Hello,',
            'body'     => 'you asked to receive product news and offers from Votepit by e-mail, for example the referral bonus and current promotions. Click the following link to confirm this:',
            'cta'      => 'Confirm product news',
            'valid'    => 'The link is valid for 48 hours.',
            'withdraw' => 'You can withdraw this consent at any time in your profile settings. If you did not ask for this, please ignore this email.',
            'fallback' => 'If the button does not work, copy this link into your browser:',
        ],
        'de' => [
            'subject'  => 'Bestätige deine Votepit-Produktneuigkeiten', // export-ok: comment-language
            'heading'  => 'Produktneuigkeiten bestätigen', // export-ok: comment-language
            'hello'    => 'Hallo,', // export-ok: comment-language
            'body'     => 'du möchtest Produktneuigkeiten und Angebote von Votepit per E-Mail erhalten, zum Beispiel den Empfehlungsbonus und aktuelle Aktionen. Klicke auf den folgenden Link, um das zu bestätigen:', // export-ok: comment-language
            'cta'      => 'Produktneuigkeiten bestätigen', // export-ok: comment-language
            'valid'    => 'Der Link ist 48 Stunden gültig.', // export-ok: comment-language
            'withdraw' => 'Du kannst diese Einwilligung jederzeit in deinen Profileinstellungen widerrufen. Hast du das nicht angefordert, ignoriere diese E-Mail bitte.', // export-ok: comment-language
            'fallback' => 'Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:', // export-ok: comment-language
        ],
        // machine-drafted, needs native review
        'es' => [
            'subject'  => 'Confirma tus novedades de Votepit',
            'heading'  => 'Confirmar novedades de producto',
            'hello'    => 'Hola,',
            'body'     => 'has pedido recibir por correo electrónico novedades y ofertas de Votepit, por ejemplo el bono por recomendación y las promociones actuales. Haz clic en el siguiente enlace para confirmarlo:',
            'cta'      => 'Confirmar novedades de producto',
            'valid'    => 'El enlace es válido durante 48 horas.',
            'withdraw' => 'Puedes retirar este consentimiento en cualquier momento en los ajustes de tu perfil. Si no lo has pedido, ignora este correo.',
            'fallback' => 'Si el botón no funciona, copia este enlace en tu navegador:',
        ],
        // machine-drafted, needs native review
        'fr' => [
            'subject'  => 'Confirmez vos actualités Votepit',
            'heading'  => 'Confirmer les actualités produit',
            'hello'    => 'Bonjour,',
            'body'     => 'vous avez demandé à recevoir par e-mail les actualités et offres de Votepit, par exemple le bonus de recommandation et les promotions en cours. Cliquez sur le lien suivant pour le confirmer :',
            'cta'      => 'Confirmer les actualités produit',
            'valid'    => 'Le lien est valable 48 heures.',
            'withdraw' => 'Vous pouvez retirer ce consentement à tout moment dans les paramètres de votre profil. Si vous n’avez pas fait cette demande, ignorez cet e-mail.',
            'fallback' => 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :',
        ],
        // machine-drafted, needs native review
        'it' => [
            'subject'  => 'Conferma le novità di prodotto di Votepit',
            'heading'  => 'Conferma le novità di prodotto',
            'hello'    => 'Ciao,',
            'body'     => 'hai chiesto di ricevere via e-mail novità e offerte di Votepit, ad esempio il bonus referral e le promozioni in corso. Clicca sul seguente link per confermarlo:',
            'cta'      => 'Conferma le novità di prodotto',
            'valid'    => 'Il link è valido per 48 ore.',
            'withdraw' => 'Puoi revocare questo consenso in qualsiasi momento nelle impostazioni del tuo profilo. Se non l’hai richiesto, ignora questa e-mail.',
            'fallback' => 'Se il pulsante non funziona, copia questo link nel tuo browser:',
        ],
        // machine-drafted, needs native review
        'nl' => [
            'subject'  => 'Bevestig je Votepit-productnieuws',
            'heading'  => 'Productnieuws bevestigen',
            'hello'    => 'Hallo,',
            'body'     => 'je hebt gevraagd om productnieuws en aanbiedingen van Votepit per e-mail te ontvangen, bijvoorbeeld de verwijzingsbonus en actuele acties. Klik op de volgende link om dit te bevestigen:',
            'cta'      => 'Productnieuws bevestigen',
            'valid'    => 'De link is 48 uur geldig.',
            'withdraw' => 'Je kunt deze toestemming op elk moment intrekken in je profielinstellingen. Heb je dit niet gevraagd, negeer dan deze e-mail.',
            'fallback' => 'Werkt de knop niet, kopieer dan deze link naar je browser:',
        ],
    ];

    /**
     * Subject and rendered body of the product-news confirmation mail (double
     * opt-in from the profile; shared with bin/send-mail-previews.php). Distinct
     * from the login link and the notification-e-mail confirmation: it asks for a
     * separate, optional consent and says so. Rendered in the recipient's stored
     * language (MailLanguage::resolve of users.digest_language).
     *
     * @return array{subject: string, text: string, html: string, image: \Votepit\Mail\InlineImage}
     */
    public static function confirmMail(string $link, string $language = MailLanguage::DEFAULT): array
    {
        $t = self::TEXTS[MailLanguage::resolve($language)];

        return ['subject' => $t['subject'], ...MailTemplate::render(
            $t['heading'],
            [$t['hello'], $t['body']],
            $link,
            $t['cta'],
            [$t['valid'], $t['withdraw']],
            buttonFallback: $t['fallback'],
        )];
    }

    private function currentUserId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return $this->error($response, 404, 'marketing_consent_disabled', 'Not found.');
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function error(ResponseInterface $response, int $status, string $key, string $message): ResponseInterface
    {
        return $this->json($response, $status, ['error' => ['key' => $key, 'message' => $message]]);
    }
}
