<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Security\AvatarProcessor;

/**
 * GET /avatar/{filename} — streams a stored avatar (AuthZ: anon — a profile
 * picture is meant to be visible to anyone who sees the profile/idea/comment
 * it's attached to, same trust level as a board's public logo_url).
 *
 * Content-Type is ALWAYS the hardcoded re-encoded format (AvatarProcessor::
 * outputContentType(), currently "image/jpeg") — never derived from the
 * stored file's extension/bytes/any request input. {filename} is matched
 * against a strict pattern by the route itself (AppFactory) AND re-checked
 * with basename() here before touching the filesystem, so this can never be
 * tricked into reading outside avatarDir.
 */
final readonly class AvatarServeAction
{
    public function __construct(
        private string $avatarDir,
        private AvatarProcessor $avatarProcessor,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $filename = is_string($args['filename'] ?? null) ? basename($args['filename']) : '';

        // Belt-and-braces: the route pattern already restricts this to
        // [0-9a-f]{32}\.jpg, but never trust a route pattern alone as the
        // only guard against path traversal.
        if ($filename === '' || preg_match('/^[0-9a-f]{32}\.jpg$/', $filename) !== 1) {
            return $response->withStatus(404);
        }

        $path = $this->avatarDir . '/' . $filename;
        if (!is_file($path)) {
            return $response->withStatus(404);
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return $response->withStatus(404);
        }

        $response->getBody()->write($bytes);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', $this->avatarProcessor->outputContentType())
            // Avatars are re-uploaded to a brand NEW opaque filename each
            // time (uploadAvatar() never reuses a name) — a given filename's
            // bytes never change, so a long, immutable cache lifetime is safe.
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
