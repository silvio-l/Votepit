<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Logging\AuditLogger;
use Votepit\Participant\ParticipantMailCategory;
use Votepit\Persistence\ParticipantMailRepository;
use Votepit\Security\ParticipantUnsubscribeToken;

/**
 * POST /participant-mail/unsubscribe — body: { token }, or the token in the
 * query string for the RFC 8058 one-click POST (AuthZ: anon, IP rate-limited;
 * CSRF-exempt by route name because the mail client sends no cookie). The
 * one-click unsubscribe of the participant mails (RFC 8058 List-Unsubscribe-Post
 * target, and the SPA page /mail/unsubscribe).
 *
 * The signed token is the capability and is bound to ONE recipient and ONE
 * category (or "all"). It can only switch mails OFF, never on. A user token
 * sets the named categories to "off"; a guest token suppresses the guest's
 * address and drops its pending rows. A GET never changes anything, so link
 * scanners prefetching the mail link cannot unsubscribe anybody.
 *
 * Invalid, forged or stale-recipient tokens get one constant 400 (fail-secure).
 */
final readonly class ParticipantUnsubscribeAction
{
    public function __construct(
        private ParticipantMailRepository $mail,
        private ParticipantUnsubscribeToken $tokens,
        private AuditLogger $audit,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // The SPA page posts { token } in the body. A mail client's RFC 8058
        // one-click POST sends only `List-Unsubscribe=One-Click` as its body and
        // keeps the token in the List-Unsubscribe URL's query string.
        $parsed = $request->getParsedBody();
        $token  = is_array($parsed) && is_string($parsed['token'] ?? null) ? $parsed['token'] : '';
        if ($token === '') {
            $query = $request->getQueryParams()['token'] ?? null;
            $token = is_string($query) ? $query : '';
        }
        $claims = $token !== '' ? $this->tokens->verify($token) : null;

        if ($claims === null || ($claims['user_id'] !== null && $this->mail->userRecipient($claims['user_id']) === null)) {
            return $this->invalid($response);
        }

        $now = new \DateTimeImmutable();

        if ($claims['user_id'] !== null) {
            $categories = $claims['category'] === 'all' ? ParticipantMailCategory::ALL : [$claims['category']];
            foreach ($categories as $category) {
                $this->mail->setCadence($claims['user_id'], $category, ParticipantMailCategory::CADENCE_OFF);
            }
            $this->audit->log('user.participant_mail_unsubscribed', ['user_id' => $claims['user_id'], 'categories' => $categories]);
        } elseif ($claims['guest_key'] !== null) {
            $this->mail->suppressGuest($claims['guest_key'], $now);
            $this->audit->log('guest.participant_mail_unsubscribed', []);
        } else {
            return $this->invalid($response);
        }

        $response->getBody()->write((string) json_encode(['ok' => true]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function invalid(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => 'invalid_token', 'message' => 'The link is invalid.'],
        ]));

        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
}
