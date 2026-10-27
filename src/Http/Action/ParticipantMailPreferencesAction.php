<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Participant\ParticipantMailCategory;
use Votepit\Persistence\ParticipantMailRepository;

/**
 * GET / PUT /account/participant-mail-preferences — the per-category cadence
 * of the participant mails (close-the-loop re-engagement B/C/F).
 *
 * AuthZ: user (route level). PUT is partial: a category missing from the body
 * keeps its current cadence. Every cadence is validated against the allowed
 * set of its category (ParticipantMailCategory::isValidCadence); any invalid
 * value rejects the whole request, so nothing is half-written.
 */
final readonly class ParticipantMailPreferencesAction
{
    public function __construct(
        private ParticipantMailRepository $mail,
        private AuditLogger $audit,
    ) {}

    public function get(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->respond($response, $this->currentUserId($request));
    }

    public function put(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $body   = $request->getParsedBody();
        if (!is_array($body)) {
            return JsonError::respond($response, 400, 'invalid_body', 'Expected a JSON object.');
        }

        // Reject unknown keys instead of ignoring them: a typo like "new_idea"
        // would otherwise return 200 while nothing changed. "_csrf" is the form token.
        foreach (array_keys($body) as $key) {
            if ($key !== '_csrf' && !ParticipantMailCategory::isValid((string) $key)) {
                return JsonError::respond($response, 400, 'invalid_category', 'Unknown mail category.');
            }
        }

        $updates = [];
        foreach (ParticipantMailCategory::ALL as $category) {
            if (!array_key_exists($category, $body)) {
                continue;
            }

            $value = $body[$category];
            if (!is_string($value) || !ParticipantMailCategory::isValidCadence($category, $value)) {
                return JsonError::respond($response, 400, 'invalid_cadence', 'That delivery option is not available for this mail type.');
            }

            $updates[$category] = $value;
        }

        foreach ($updates as $category => $cadence) {
            $this->mail->setCadence($userId, $category, $cadence);
        }

        if ($updates !== []) {
            $this->audit->log('user.participant_mail_preferences_updated', ['user_id' => $userId, 'categories' => array_keys($updates)]);
        }

        return $this->respond($response, $userId);
    }

    private function respond(ResponseInterface $response, int $userId): ResponseInterface
    {
        $categories = [];
        foreach (ParticipantMailCategory::ALL as $category) {
            $categories[$category] = [
                'cadence' => $this->mail->cadenceFor($userId, $category),
                'allowed' => ParticipantMailCategory::allowedCadences($category),
            ];
        }

        $response->getBody()->write((string) json_encode(['categories' => $categories], JSON_UNESCAPED_SLASHES));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function currentUserId(ServerRequestInterface $request): int
    {
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }
}
