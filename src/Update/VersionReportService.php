<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * The daily version report to Votepit's own Matomo: at most one set of events
 * per UTC day, fire-and-forget.
 *
 * What leaves the installation, and nothing else (see documentation/installation.md,
 * "What your installation sends"): three Matomo events, each with the same
 * random installation ID as visitor ID (cid):
 *
 *   install / version  <installed version>   e.g. 0.1.8
 *   install / php      <PHP_VERSION>          e.g. 8.3.12
 *   install / database <engine version>       e.g. mariadb 10.11.6
 *
 * Plus the fixed page URL VERSION_REPORT_PAGE and the HTTP request itself
 * (the server's IP address and the User-Agent "Votepit-Community/<version>").
 * No e-mail address, no account, board or idea data, no domain of the operator.
 *
 * Failures are swallowed on purpose: the report must never affect the cron
 * job, the app or the installation.
 */
final readonly class VersionReportService
{
    public function __construct(
        private CommunityStateStore $state,
        private ReleaseHttp $http,
        private string $installedVersion,
    ) {}

    /**
     * @return bool true when today's report has been sent (now or earlier); false is never returned
     */
    public function run(\DateTimeImmutable $now, string $phpVersion, string $databaseVersion): bool
    {
        $today = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        if ($this->state->get(CommunityStateStore::VERSION_REPORTED_ON) === $today) {
            return true;
        }

        $cid     = $this->state->installationId();
        $headers = ['User-Agent: Votepit-Community/' . $this->installedVersion];

        $events = [
            ['version', $this->installedVersion],
            ['php', $phpVersion],
            ['database', $databaseVersion],
        ];
        foreach ($events as [$action, $name]) {
            $query = http_build_query([
                'idsite'     => CommunityEdition::VERSION_REPORT_SITE_ID,
                'rec'        => 1,
                'apiv'       => 1,
                'send_image' => 0,
                'cid'        => $cid,
                'url'        => CommunityEdition::VERSION_REPORT_PAGE,
                'e_c'        => 'install',
                'e_a'        => $action,
                'e_n'        => $name,
            ]);

            $this->http->get(CommunityEdition::VERSION_REPORT_URL . '?' . $query, $headers, CommunityEdition::HTTP_TIMEOUT_SECONDS);
        }

        $this->state->set(CommunityStateStore::VERSION_REPORTED_ON, $today);

        return true;
    }
}
