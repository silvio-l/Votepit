<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\ApiTokenScope;
use Votepit\Security\OAuthServer\OAuthScope;

/**
 * The `manage` grant (migration 0117) sits above `moderate`, and is never
 * offered over OAuth. Unknown values stay fail-secure.
 */
final class ApiTokenManageScopeTest extends TestCase
{
    public function test_manage_includes_every_lower_level(): void
    {
        self::assertTrue(ApiTokenScope::allowsWrite(ApiTokenScope::MANAGE));
        self::assertTrue(ApiTokenScope::allowsModerate(ApiTokenScope::MANAGE));
        self::assertTrue(ApiTokenScope::allowsManage(ApiTokenScope::MANAGE));
    }

    public function test_only_manage_grants_branding(): void
    {
        foreach ([ApiTokenScope::READ, ApiTokenScope::WRITE, ApiTokenScope::MODERATE, 'unknown', ''] as $scope) {
            self::assertFalse(ApiTokenScope::allowsManage($scope), $scope);
        }
    }

    public function test_moderate_does_not_imply_manage(): void
    {
        self::assertTrue(ApiTokenScope::allowsModerate(ApiTokenScope::MODERATE));
        self::assertFalse(ApiTokenScope::allowsManage(ApiTokenScope::MODERATE));
    }

    public function test_oauth_never_requests_or_grants_manage(): void
    {
        self::assertNotContains(ApiTokenScope::MANAGE, OAuthScope::SUPPORTED);
        self::assertNull(OAuthScope::maxLevel('manage'));
        self::assertFalse(OAuthScope::within(ApiTokenScope::MANAGE, ApiTokenScope::MODERATE));
    }
}
