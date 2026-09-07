<?php

declare(strict_types=1);

namespace Votepit\Http;

/**
 * Names of the core routes an extension may attach middleware to through
 * AppExtension::routeMiddleware().
 *
 * Core keeps its routes anonymous except for this deliberately short list:
 * every name here is a stable contract, and everything NOT listed is out
 * of reach for extensions on purpose (a hosted service may wrap an
 * outbound-mail endpoint or a rate-limited write, but never the AuthZ of
 * an admin route). Adding a name is a review point, not a convenience.
 *
 * The routes are named in AppFactory (`->setName(CoreRoute::…)`) and
 * looked up by name once all routes exist, so extension middleware ends
 * up OUTERMOST on the route — outside AuthZ and the per-action rate
 * limit. An extension can therefore both short-circuit the request
 * (reply before core runs) and observe core's response (e.g. a 429 from
 * the rate limit) on the way back out.
 */
final class CoreRoute
{
    /** GET /robots.txt — crawler policy. */
    public const ROBOTS_TXT = 'core.robots_txt';

    /** POST /login — magic-link request (sends mail). */
    public const LOGIN_REQUEST = 'core.login.request';

    /** POST /password/reset/request — password-reset request (sends mail). */
    public const PASSWORD_RESET_REQUEST = 'core.password_reset.request';

    /** POST {account}/admin/invites — member invitation (sends mail). */
    public const INVITE_SEND = 'core.invite.send';

    /**
     * POST {account}/admin/boards/{slug}/smtp/test — test mail through a
     * board's own SMTP relay. Self-host only: the route does not exist in
     * routing_mode 'cloud', so attaching middleware to it there is a
     * configuration error.
     */
    public const BOARD_SMTP_TEST = 'core.board_smtp.test';

    /**
     * GET/PUT {account}/admin/boards/{slug}/smtp — read/save a board's own
     * SMTP relay. Self-host only, same non-existence-in-cloud caveat as
     * BOARD_SMTP_TEST above.
     */
    public const BOARD_SMTP_GET = 'core.board_smtp.get';
    public const BOARD_SMTP_PUT = 'core.board_smtp.put';

    /** POST {account}/{board}/ideas — idea submission (rate limit idea:submit). */
    public const IDEA_CREATE = 'core.idea.create';

    /** POST {account}/{board}/ideas/{id}/vote — vote (rate limit idea:vote). */
    public const IDEA_VOTE = 'core.idea.vote';

    /** POST {account}/{board}/ideas/{id}/comments — comment (rate limit comment:user). */
    public const COMMENT_CREATE = 'core.comment.create';

    /** GET {account}/{board}/ideas/search-duplicates — duplicate recall (rate limit dupsearch:user). */
    public const IDEA_SEARCH_DUPLICATES = 'core.idea.search_duplicates';

    /** POST {account}/admin/support — dashboard support-request submission (entirely in-app; the reply lands in the customer's notification inbox). */
    public const SUPPORT_REQUEST_SUBMIT = 'core.support_request.submit';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::ROBOTS_TXT,
            self::LOGIN_REQUEST,
            self::PASSWORD_RESET_REQUEST,
            self::INVITE_SEND,
            self::BOARD_SMTP_TEST,
            self::BOARD_SMTP_GET,
            self::BOARD_SMTP_PUT,
            self::IDEA_CREATE,
            self::IDEA_VOTE,
            self::COMMENT_CREATE,
            self::IDEA_SEARCH_DUPLICATES,
            self::SUPPORT_REQUEST_SUBMIT,
        ];
    }
}
