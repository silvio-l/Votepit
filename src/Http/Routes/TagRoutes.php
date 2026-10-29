<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Http\Action\IdeaTagAssignAction;
use Votepit\Http\Action\IdeaTagRemoveAction;
use Votepit\Http\Action\TagCreateAction;
use Votepit\Http\Action\TagDeleteAction;
use Votepit\Http\Action\TagListAction;
use Votepit\Http\Action\TagUpdateAction;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\TagRepository;

/**
 * Board tags: list/create/rename/delete and idea tag assignment.
 */
final class TagRoutes
{
    /**
     * Tag CRUD under {account}/{board}/tags + idea tag assign/remove.
     *
     * @param App<null> $app
     */
    public static function register(
        App $app,
        ResponseFactory $responseFactory,
        AuditLogger $audit,
        AuthzDenialLogger $authzDenialLogger,
        string $accountPrefix,
        BoardRepository $boardRepo,
        AccountMemberRepository $accountMemberRepo,
        IdeaRepository $ideaRepo,
        TagRepository $tagRepo,
    ): void {
        // GET /{board}/tags — list a board's tags (read-only, public).
        // AuthZ: anon. Unknown/private board → 404 (findPublicBySlugForAccount,
        // same visibility gate as BoardHomeAction/IdeaDetailAction).
        $app->get($accountPrefix . '/{board}/tags', new TagListAction($boardRepo, $tagRepo, $accountMemberRepo))
            ->add(AuthZMiddleware::anon($responseFactory));

        // POST /{board}/tags — create a tag.
        // AuthZ: accountModerate (owner|admin|moderator, anon → 401,
        // missing account role → 403); CSRF global. Duplicate name in the
        // same board → 422 (TagRepository::create() UNIQUE(board_id, name)).
        $app->post($accountPrefix . '/{board}/tags', new TagCreateAction($boardRepo, $tagRepo, $audit))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /{board}/tags/{id} — rename/recolor a tag.
        // AuthZ: accountModerate; CSRF global. Board-scoping in the action
        // via TagRepository::findInBoard (foreign tag → 404, no mutation).
        $app->post($accountPrefix . '/{board}/tags/{id:[0-9]+}', new TagUpdateAction($boardRepo, $tagRepo, $audit))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /{board}/tags/{id}/delete — delete a tag (cascades assignments).
        // AuthZ: accountModerate; CSRF global. Board-scoping via a board-scoped
        // WHERE clause in TagRepository::delete() (foreign tag → 404, no mutation).
        $app->post($accountPrefix . '/{board}/tags/{id:[0-9]+}/delete', new TagDeleteAction($boardRepo, $tagRepo, $audit))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /{board}/ideas/{id}/tags — assign a tag to an idea.
        // AuthZ: accountModerate; CSRF global. Both the idea and the tag are
        // loaded board-scoped (foreign idea/tag → 404, no mutation, and no
        // cross-board tag assignment even within the same account).
        $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}/tags', new IdeaTagAssignAction($boardRepo, $ideaRepo, $tagRepo, $audit))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /{board}/ideas/{id}/tags/{tagId}/remove — remove a tag from an idea.
        // AuthZ: accountModerate; CSRF global. Same board-scoping as the assign route above.
        $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}/tags/{tagId:[0-9]+}/remove', new IdeaTagRemoveAction($boardRepo, $ideaRepo, $tagRepo, $audit))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));
    }
}
