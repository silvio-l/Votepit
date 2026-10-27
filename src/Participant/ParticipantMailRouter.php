<?php

declare(strict_types=1);

namespace Votepit\Participant;

use Doctrine\DBAL\Connection;
use Votepit\Config;
use Votepit\Persistence\ParticipantMailRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\ParticipantUnsubscribeToken;

/**
 * Decides, per recipient and change, whether a participant mail goes out now
 * or is batched into the digest queue (close-the-loop slice 03, re-engagement
 * C/G). Called by IdeaStatusChanger and CommentCreateAction inside their
 * transactions, so a queued item commits together with the change it reports
 * (a deferred item is never lost to a crash between commit and send).
 *
 * Results:
 *  - 'instant': the caller sends the mail now through its existing path;
 *  - 'queued':  a queue row was written, the digest job sends it later;
 *  - 'dropped': the recipient turned the category off, or has been inactive
 *               for 90 days, or is a suppressed guest. Nothing is written.
 *
 * Also builds the one-click unsubscribe headers and links for every
 * participant mail (RFC 8058; the token binds recipient and category).
 */
final readonly class ParticipantMailRouter
{
    public const RESULT_INSTANT = 'instant';
    public const RESULT_QUEUED  = 'queued';
    public const RESULT_DROPPED = 'dropped';

    /** Event kinds a queued item can describe. */
    public const EVENT_STATUS       = 'status';
    public const EVENT_COMMENT      = 'comment';
    public const EVENT_THREAD_REPLY = 'thread_reply';

    private const MAX_TITLE   = 200;
    private const MAX_STATUS  = 64;
    private const MAX_REASON  = 500;
    private const MAX_EXCERPT = 300;

    public function __construct(
        private ParticipantMailRepository $mail,
        private EncryptionService $guestEmailEncryption,
        private string $guestKeyHmacKey,
        private ParticipantUnsubscribeToken $tokens,
        private string $appUrl,
        // Installation kill switch (Config::$participantMailsEnabled): while off,
        // every participant mail is dropped — nothing is sent or queued.
        private bool $participantMailsEnabled = true,
    ) {}

    /** Wiring shared by AppFactory and the route files (same keys, same contexts). */
    public static function forConfig(Connection $conn, Config $config): self
    {
        return new self(
            new ParticipantMailRepository($conn),
            new EncryptionService($config->appKey, 'anon_vote_email'),
            $config->identityServerKey,
            new ParticipantUnsubscribeToken($config->appKey),
            $config->appUrl,
            $config->participantMailsEnabled,
        );
    }

    /**
     * A registered recipient: instant when the cadence and the recent visit
     * allow it, otherwise queued for the digest, or dropped.
     *
     * @param array{title: string, status?: ?string, reason?: ?string, excerpt?: ?string} $details
     */
    public function routeUser(
        int $userId,
        string $category,
        int $boardId,
        int $ideaId,
        string $eventKind,
        array $details,
        string $linkPath,
        \DateTimeImmutable $now,
    ): string {
        if (!$this->participantMailsEnabled) {
            return self::RESULT_DROPPED;
        }

        $cadence   = $this->mail->cadenceFor($userId, $category);
        $effective = ParticipantMailPolicy::effectiveCadence($category, $cadence, $this->mail->activityBaseline($userId), $now);

        if ($effective === ParticipantMailCategory::CADENCE_INSTANT) {
            return self::RESULT_INSTANT;
        }
        if ($effective === ParticipantMailPolicy::STOP || $effective === ParticipantMailCategory::CADENCE_OFF) {
            return self::RESULT_DROPPED;
        }

        $this->mail->enqueue([
            'recipient_user_id'   => $userId,
            'recipient_key'       => null,
            'recipient_email_enc' => null,
            'category'            => $category,
            'board_id'            => $boardId,
            'idea_id'             => $ideaId,
            'event_kind'          => $eventKind,
            'payload'             => self::payload($details),
            'link_path'           => $linkPath,
        ], $now);

        return self::RESULT_QUEUED;
    }

    /**
     * A guest with a stored anonymous-vote notification e-mail. Guests never
     * get an instant mail: they always receive the digest, unless suppressed.
     * The address is stored only encrypted (anon_vote_email context).
     *
     * @param array{title: string, status?: ?string, reason?: ?string, excerpt?: ?string} $details
     */
    public function routeGuest(
        string $email,
        int $boardId,
        int $ideaId,
        string $eventKind,
        array $details,
        string $linkPath,
        \DateTimeImmutable $now,
    ): string {
        if (!$this->participantMailsEnabled) {
            return self::RESULT_DROPPED;
        }

        $key = $this->guestKey($email);
        if ($this->mail->isGuestSuppressed($key)) {
            return self::RESULT_DROPPED;
        }

        $this->mail->enqueue([
            'recipient_user_id'   => null,
            'recipient_key'       => $key,
            'recipient_email_enc' => $this->guestEmailEncryption->encrypt($email),
            'category'            => ParticipantMailCategory::PARTICIPATED,
            'board_id'            => $boardId,
            'idea_id'             => $ideaId,
            'event_kind'          => $eventKind,
            'payload'             => self::payload($details),
            'link_path'           => $linkPath,
        ], $now);

        return self::RESULT_QUEUED;
    }

    /**
     * Keyed HMAC of the normalised address (domain-separated). Stands in for
     * the address in suppressions, claims and the unsubscribe token; never the
     * address itself.
     */
    public function guestKey(string $email): string
    {
        return hash_hmac('sha256', 'participant-mail:' . strtolower(trim($email)), $this->guestKeyHmacKey);
    }

    /**
     * RFC 8058 headers for a participant mail: a one-click POST endpoint
     * whose signed token binds recipient and category.
     *
     * @return array<string, string>
     */
    public function unsubscribeHeaders(?int $userId, ?string $guestKey, string $category): array
    {
        return [
            'List-Unsubscribe'      => '<' . $this->unsubscribeEndpoint($userId, $guestKey, $category) . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /** The human-readable unsubscribe page (SPA route, posts to the endpoint). */
    public function unsubscribePageUrl(?int $userId, ?string $guestKey, string $category): string
    {
        return $this->appUrl . '/mail/unsubscribe?token=' . $this->token($userId, $guestKey, $category);
    }

    public function preferencesUrl(): string
    {
        return $this->appUrl . '/profile';
    }

    /**
     * The JSON payload of one queue row. Strings are trimmed and length-bounded
     * here, once, so nothing unbounded reaches the queue.
     *
     * @param array{title: string, status?: ?string, reason?: ?string, excerpt?: ?string} $details
     */
    public static function payload(array $details): string
    {
        $bound = static fn (?string $value, int $max): ?string => $value === null || trim($value) === ''
            ? null
            : mb_substr(trim($value), 0, $max);

        return (string) json_encode([
            'title'   => $bound($details['title'], self::MAX_TITLE) ?? '',
            'status'  => $bound($details['status'] ?? null, self::MAX_STATUS),
            'reason'  => $bound($details['reason'] ?? null, self::MAX_REASON),
            'excerpt' => $bound($details['excerpt'] ?? null, self::MAX_EXCERPT),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function unsubscribeEndpoint(?int $userId, ?string $guestKey, string $category): string
    {
        return $this->appUrl . '/participant-mail/unsubscribe?token=' . $this->token($userId, $guestKey, $category);
    }

    private function token(?int $userId, ?string $guestKey, string $category): string
    {
        if ($userId !== null) {
            return $this->tokens->signForUser($userId, $category);
        }
        if ($guestKey !== null) {
            return $this->tokens->signForGuest($guestKey, $category);
        }

        throw new \InvalidArgumentException('An unsubscribe token needs a recipient.');
    }
}
