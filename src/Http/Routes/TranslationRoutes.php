<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Domain\TranslationGate;
use Votepit\Http\Action\BoardIntroTranslateAction;
use Votepit\Http\Action\CommentTranslateAction;
use Votepit\Http\Action\IdeaTranslateAction;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\TranslationRepository;
use Votepit\Security\RateLimiter;
use Votepit\Translation\TranslationProvider;

/**
 * On-demand machine translation of board content (idea, comment thread,
 * board intro) under {account}/{board}.
 */
final class TranslationRoutes
{
    /**
     * POST /{board}/ideas/{id}/translate + POST /{board}/ideas/{id}/comments/translate
     * (idea-comment-translation feature). AuthZ: user (anon → 401); CSRF
     * globally enforced. RateLimit `translation:idea`/`translation:comment`
     * is deliberately NOT route middleware here (unlike every other
     * per-action rate limit in the route modules) — both actions check it
     * themselves, AFTER their own cache lookup, so a cache HIT never
     * counts against the bucket. See each action's class doc for why a
     * middleware-level check (the original design) was wrong: it blocked
     * already-cached, previously-translated content the moment the bucket
     * filled up from translating OTHER ideas/threads.
     *
     * @param App<null> $app
     */
    public static function register(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuthzDenialLogger $authzDenialLogger,
        string $accountPrefix,
        BoardRepository $boardRepo,
        IdeaRepository $ideaRepo,
        CommentRepository $commentRepo,
        TranslationRepository $translationRepo,
        TranslationProvider $translationProvider,
        TranslationGate $translationGate,
    ): void {
        $ideaTranslateRateLimit = $config->rateLimit('translation:idea');
        $commentTranslateRateLimit = $config->rateLimit('translation:comment');
        $boardIntroTranslateRateLimit = $config->rateLimit('translation:board_intro');
        $rateLimiter = new RateLimiter($conn);

        $providerName = $config->translationProvider() !== '' ? $config->translationProvider() : 'google';

        $app->post(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/translate',
            new IdeaTranslateAction(
                $boardRepo,
                $ideaRepo,
                $translationRepo,
                $translationProvider,
                $translationGate,
                $rateLimiter,
                $ideaTranslateRateLimit['limit'],
                $ideaTranslateRateLimit['window'],
                $providerName,
            ),
        )
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $app->post(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/comments/translate',
            new CommentTranslateAction(
                $boardRepo,
                $ideaRepo,
                $commentRepo,
                $translationRepo,
                $translationProvider,
                $translationGate,
                $rateLimiter,
                $commentTranslateRateLimit['limit'],
                $commentTranslateRateLimit['window'],
                $config->translationConfigured(),
                $providerName,
            ),
        )
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $app->post(
            $accountPrefix . '/{board}/translate-intro',
            new BoardIntroTranslateAction(
                $boardRepo,
                $translationRepo,
                $translationProvider,
                $translationGate,
                $rateLimiter,
                $boardIntroTranslateRateLimit['limit'],
                $boardIntroTranslateRateLimit['window'],
                $providerName,
            ),
        )
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
    }
}
