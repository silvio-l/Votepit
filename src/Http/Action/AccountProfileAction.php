<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\UsernameTakenException;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\UserSocialLinkRepository;
use Votepit\Security\AvatarProcessor;
use Votepit\Security\SocialLinkValidator;
use Votepit\Security\UsernameValidator;

/**
 * GET    /account/profile       — own avatar_url + username + the 4 fixed social identifiers (AuthZ: user).
 * POST   /account/avatar        — upload/replace the avatar (AuthZ: user, CSRF).
 * DELETE /account/avatar        — remove the avatar (AuthZ: user, CSRF).
 * PUT    /account/social-links  — set/clear the 4 fixed social identifiers (AuthZ: user, CSRF).
 * PUT    /account/privacy       — set the profile-visibility toggle (AuthZ: user, CSRF).
 * PUT    /account/username      — set/clear the optional public display name (AuthZ: user, CSRF).
 *
 * User-scoped (NOT account-scoped, no /{account} prefix) — a profile is the
 * same across every account the user belongs to, mirroring how `users`
 * itself is global (ADR 0001 §2c). AuthZ::user() only: no account-admin
 * check applies — anyone acts on their OWN row exclusively, identified from
 * the session (AuthNMiddleware::ATTR_USER), never from a client-supplied id.
 *
 * Storage: `storage/avatars/` — a directory OUTSIDE public/ (see
 * AppFactory's $avatarDir wiring + documentation/installation.md docroot layout), never
 * served directly by Apache. AvatarServeAction is the only reader, always
 * emitting a hardcoded `image/jpeg` (AvatarProcessor::outputContentType()) —
 * the stored bytes are never trusted to carry their own Content-Type.
 */
final readonly class AccountProfileAction
{
    public function __construct(
        private UserRepository $users,
        private UserSocialLinkRepository $socialLinks,
        private AvatarProcessor $avatarProcessor,
        private string $avatarDir,
        private AuditLogger $audit,
    ) {}

    /** GET /account/profile */
    public function getProfile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $user   = $this->users->findById($userId);

        $avatarFilename = is_array($user) && is_string($user['avatar_filename'] ?? null)
            ? $user['avatar_filename']
            : null;

        $links = $this->socialLinks->getForUser($userId);

        $profileVisible = is_array($user) && (bool) ($user['profile_visible'] ?? false);

        $username = is_array($user) && is_string($user['username'] ?? null) ? $user['username'] : null;

        $response->getBody()->write((string) json_encode([
            'avatar_url'      => $avatarFilename !== null ? '/avatar/' . $avatarFilename : null,
            'profile_visible' => $profileVisible,
            'username'        => $username,
            'website_domain'  => $links['website_domain'],
            'x_handle'        => $links['x_handle'],
            'youtube_handle'  => $links['youtube_handle'],
            'github_username' => $links['github_username'],
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** POST /account/avatar (multipart/form-data, field name "avatar") */
    public function uploadAvatar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);

        $files = $request->getUploadedFiles();
        $file  = $files['avatar'] ?? null;

        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->errorResponse($response, 422, 'invalid_upload', 'No valid file was uploaded.');
        }

        if ($file->getSize() !== null && $file->getSize() > AvatarProcessor::MAX_UPLOAD_BYTES) {
            return $this->errorResponse($response, 422, 'file_too_large', 'The file exceeds the 5 MB limit.');
        }

        $rawBytes = (string) $file->getStream();

        $encoded = $this->avatarProcessor->process($rawBytes);
        if ($encoded === null) {
            // Deliberately generic: "not a decodable raster image" covers
            // SVG, non-image files, corrupt/truncated uploads, and an
            // oversized decoded image alike — no need to distinguish for the
            // caller, and no internal decode-failure detail is ever leaked.
            return $this->errorResponse($response, 422, 'invalid_image', 'The file could not be processed as an image.');
        }

        if (!is_dir($this->avatarDir) && !mkdir($this->avatarDir, 0o755, true) && !is_dir($this->avatarDir)) {
            return $this->errorResponse($response, 500, 'storage_error', 'Could not prepare avatar storage.');
        }

        // Opaque, server-generated filename — never derived from the
        // original filename or any other user-supplied value.
        $filename = bin2hex(random_bytes(16)) . '.' . $this->avatarProcessor->outputExtension();
        $path     = $this->avatarDir . '/' . $filename;

        if (file_put_contents($path, $encoded, LOCK_EX) === false) {
            return $this->errorResponse($response, 500, 'storage_error', 'Could not save the avatar.');
        }

        $user           = $this->users->findById($userId);
        $previousAvatar = is_array($user) && is_string($user['avatar_filename'] ?? null)
            ? $user['avatar_filename']
            : null;

        $this->users->setAvatarFilename($userId, $filename);

        // Delete the OLD file only after the new one is durably written and
        // the DB row points at it — never leave a window where the DB
        // references a file that doesn't exist yet.
        if ($previousAvatar !== null) {
            $this->deleteAvatarFile($previousAvatar);
        }

        $this->audit->log('user.avatar_uploaded', ['user_id' => $userId]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'avatar_url' => '/avatar/' . $filename]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** DELETE /account/avatar */
    public function deleteAvatar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $user   = $this->users->findById($userId);

        $avatarFilename = is_array($user) && is_string($user['avatar_filename'] ?? null)
            ? $user['avatar_filename']
            : null;

        if ($avatarFilename !== null) {
            $this->users->clearAvatarFilename($userId);
            $this->deleteAvatarFile($avatarFilename);
            $this->audit->log('user.avatar_removed', ['user_id' => $userId]);
        }

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * PUT /account/social-links — sets/clears the caller's 4 fixed social
     * identifiers, independently (0-4 filled; any field omitted from the
     * request body is treated the same as an empty string). Each is either
     * a bare platform identifier (never a URL — see SocialLinkValidator's
     * class doc for why the user can never supply a URL at all) or an
     * empty string, which explicitly CLEARS that field (mirrors
     * AccountBrandingAction's "empty string resets" convention). Client-
     * side validation in ProfilePage is UX-only — every value is
     * re-validated here, authoritatively, before it ever reaches storage.
     */
    public function putSocialLinks(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];

        /** @var array<string, callable(string): ?string> $validators */
        $validators = [
            'website_domain'  => SocialLinkValidator::website(...),
            'x_handle'        => SocialLinkValidator::xHandle(...),
            'youtube_handle'  => SocialLinkValidator::youtubeHandle(...),
            'github_username' => SocialLinkValidator::githubUsername(...),
        ];

        $fields = [];
        foreach ($validators as $field => $validate) {
            $raw = (string) ($body[$field] ?? '');

            if ($raw === '') {
                $fields[$field] = null;
                continue;
            }

            $value = $validate($raw);
            if ($value === null) {
                return $this->errorResponse(
                    $response,
                    422,
                    'invalid_' . $field,
                    sprintf('The %s value is invalid.', $field),
                );
            }

            $fields[$field] = $value;
        }

        $this->socialLinks->updateForUser($userId, $fields);
        $this->audit->log('user.social_links_updated', ['user_id' => $userId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * PUT /account/privacy — flips the caller's own profile-visibility
     * toggle (profile-visibility feature). Body: { "visible": bool }.
     * Default is anonymous (migration 0021) — this is the only write path.
     */
    public function putPrivacy(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];
        $visible = (bool) ($body['visible'] ?? false);

        $this->users->setProfileVisible($userId, $visible);
        $this->audit->log('user.privacy_updated', ['user_id' => $userId, 'profile_visible' => $visible]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'profile_visible' => $visible]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * PUT /account/username — sets or clears the caller's own optional
     * public display name. Body: { "username": string } — an empty string
     * clears it (mirrors putSocialLinks' "empty string resets" convention).
     * Only ever shown on public surfaces while profile_visible = true (see
     * AuthorBadge/PublicProfileAction) — this endpoint itself doesn't touch
     * that toggle.
     */
    public function putUsername(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];
        $raw    = (string) ($body['username'] ?? '');

        if ($raw === '') {
            $this->users->setUsername($userId, null);
            $this->audit->log('user.username_updated', ['user_id' => $userId, 'username' => null]);
            $response->getBody()->write((string) json_encode(['ok' => true, 'username' => null]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        $username = UsernameValidator::validate($raw);
        if ($username === null) {
            return $this->errorResponse($response, 422, 'invalid_username', 'The username is invalid.');
        }

        try {
            $this->users->setUsername($userId, $username);
        } catch (UsernameTakenException) {
            return $this->errorResponse($response, 409, 'username_taken', 'This username is already taken.');
        }

        $this->audit->log('user.username_updated', ['user_id' => $userId, 'username' => $username]);
        $response->getBody()->write((string) json_encode(['ok' => true, 'username' => $username]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function currentUserId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    private function deleteAvatarFile(string $filename): void
    {
        // Filename always originates from our own DB column (never directly
        // from user input at this call site), but basename() is cheap
        // defense in depth against ever unlinking outside avatarDir.
        $path = $this->avatarDir . '/' . basename($filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function errorResponse(ResponseInterface $response, int $status, string $key, string $message): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => $key, 'message' => $message],
        ]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
