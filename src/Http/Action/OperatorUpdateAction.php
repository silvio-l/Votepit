<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Update\CommunityEdition;
use Votepit\Update\CommunityStateStore;
use Votepit\Update\InstalledVersion;
use Votepit\Update\SemanticVersion;

/**
 * GET /operator/update — the Community update status for the operator banner.
 *
 * AuthZ: AuthZMiddleware::operator(). Reads only the stored result of the daily
 * update check (bin/community-update-check.php); this request never calls out.
 * On Cloud and the demo the check does not run, so update_check_enabled is false there.
 */
final readonly class OperatorUpdateAction
{
    public function __construct(
        private CommunityStateStore $state,
        private Config $config,
        private string $root,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $installed = InstalledVersion::fromRoot($this->root);

        $latest = $this->state->get(CommunityStateStore::LATEST_VERSION);
        $latest = is_string($latest) && SemanticVersion::isValid($latest) ? $latest : null;

        $body = [
            'installed_version'    => $installed,
            'latest_version'       => $latest,
            'update_available'     => $latest !== null && SemanticVersion::isNewer($latest, $installed),
            'release_url'          => $latest !== null ? sprintf(CommunityEdition::RELEASE_PAGE_URL, 'v' . $latest) : null,
            'docs_url'             => CommunityEdition::UPGRADE_DOCS_URL,
            'checked_at'           => $this->state->get(CommunityStateStore::CHECKED_AT),
            'update_check_enabled' => $this->config->updateCheckEnabled && CommunityEdition::runsUpdateChannel($this->config),
        ];

        $response->getBody()->write((string) json_encode($body));

        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
