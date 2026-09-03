<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Votepit\Security\AvatarProcessor;
use Votepit\Security\CsrfService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for avatar upload/delete/serve + social-links CRUD
 * (profile-avatar-social). Booted over AppFactory with SQLite-in-memory +
 * a throwaway temp avatar directory (IntegrationTestCase::$avatarDir).
 */
final class AccountProfileActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function seedUser(string $email): int
    {
        $this->conn->insert('users', [
            'email_hmac'    => (new IdentityHasher(self::identityServerKey()))->hash($email),
            'is_admin'      => 0,
            'is_blocked'    => 0,
            'token_version' => 0,
            'verified_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(self::APP_KEY, 3600, false);
    }

    private function makePngBytes(int $width = 100, int $height = 100): string
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('width/height must be positive');
        }

        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        $bg = imagecolorallocate($image, 20, 120, 200);
        self::assertNotFalse($bg);
        imagefill($image, 0, 0, $bg);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        return (string) $bytes;
    }

    private function authedRequest(string $method, string $uri, ?int $userId, bool $withCsrf = true): ServerRequestInterface
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();

        $cookies = [];
        $headers = [];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);
        }
        if ($withCsrf) {
            $cookies['votepit_csrf']   = $csrf->sign($csrfToken);
            $headers['X-CSRF-Token']   = $csrfToken;
        }

        $request = (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withCookieParams($cookies);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function uploadRequest(?int $userId, string $bytes, bool $withCsrf = true): ServerRequestInterface
    {
        $request = $this->authedRequest('POST', '/account/avatar', $userId, $withCsrf);

        $stream = (new StreamFactory())->createStream($bytes);
        $file   = new UploadedFile($stream, 'avatar.png', 'image/png', strlen($bytes), UPLOAD_ERR_OK);

        return $request->withUploadedFiles(['avatar' => $file]);
    }

    // -------------------------------------------------------------------------
    // AuthZ: user required
    // -------------------------------------------------------------------------

    public function test_get_profile_as_anon_is_rejected(): void
    {
        $response = $this->createApp()->handle($this->authedRequest('GET', '/account/profile', null));
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_upload_avatar_as_anon_is_rejected(): void
    {
        $response = $this->createApp()->handle($this->uploadRequest(null, $this->makePngBytes()));
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_upload_avatar_without_csrf_is_rejected(): void
    {
        $userId   = $this->seedUser('user1@example.com');
        $response = $this->createApp()->handle($this->uploadRequest($userId, $this->makePngBytes(), withCsrf: false));
        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Upload happy path + re-serve
    // -------------------------------------------------------------------------

    public function test_upload_valid_avatar_then_get_profile_reflects_it(): void
    {
        $userId = $this->seedUser('user2@example.com');
        $app    = $this->createApp();

        $uploadResp = $app->handle($this->uploadRequest($userId, $this->makePngBytes()));
        self::assertSame(200, $uploadResp->getStatusCode());
        $uploadData = json_decode((string) $uploadResp->getBody(), true);
        self::assertTrue($uploadData['ok'] ?? false);
        self::assertStringStartsWith('/avatar/', $uploadData['avatar_url']);

        $profileResp = $app->handle($this->authedRequest('GET', '/account/profile', $userId));
        $profileData = json_decode((string) $profileResp->getBody(), true);
        self::assertSame($uploadData['avatar_url'], $profileData['avatar_url']);

        // Serve route returns the re-encoded JPEG with a hardcoded Content-Type.
        $serveResp = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $uploadData['avatar_url']),
        );
        self::assertSame(200, $serveResp->getStatusCode());
        self::assertSame('image/jpeg', $serveResp->getHeaderLine('Content-Type'));
        $body = (string) $serveResp->getBody();
        $info = getimagesizefromstring($body);
        self::assertIsArray($info);
        self::assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    public function test_svg_upload_is_rejected_with_422(): void
    {
        $userId  = $this->seedUser('user3@example.com');
        $svg     = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>';
        $response = $this->createApp()->handle($this->uploadRequest($userId, $svg));

        self::assertSame(422, $response->getStatusCode());

        $row = $this->conn->fetchOne('SELECT avatar_filename FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNull($row);
    }

    public function test_non_image_upload_is_rejected_with_422(): void
    {
        $userId   = $this->seedUser('user4@example.com');
        $response = $this->createApp()->handle($this->uploadRequest($userId, 'not an image at all'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_oversized_upload_is_rejected_with_422(): void
    {
        $userId   = $this->seedUser('user5@example.com');
        $oversized = str_repeat('a', AvatarProcessor::MAX_UPLOAD_BYTES + 1);
        $response  = $this->createApp()->handle($this->uploadRequest($userId, $oversized));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_reupload_deletes_previous_file_on_disk(): void
    {
        $userId = $this->seedUser('user6@example.com');
        $app    = $this->createApp();

        $first  = json_decode((string) $app->handle($this->uploadRequest($userId, $this->makePngBytes(100, 100)))->getBody(), true);
        $firstFilename = basename((string) $first['avatar_url']);
        self::assertFileExists($this->avatarDir . '/' . $firstFilename);

        $second = json_decode((string) $app->handle($this->uploadRequest($userId, $this->makePngBytes(50, 50)))->getBody(), true);
        $secondFilename = basename((string) $second['avatar_url']);

        self::assertNotSame($firstFilename, $secondFilename, 'reupload must generate a fresh opaque filename');
        self::assertFileDoesNotExist($this->avatarDir . '/' . $firstFilename, 'the old file must be deleted, no orphan left behind');
        self::assertFileExists($this->avatarDir . '/' . $secondFilename);
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    public function test_delete_avatar_removes_file_and_clears_reference(): void
    {
        $userId = $this->seedUser('user7@example.com');
        $app    = $this->createApp();

        $uploaded = json_decode((string) $app->handle($this->uploadRequest($userId, $this->makePngBytes()))->getBody(), true);
        $filename = basename((string) $uploaded['avatar_url']);
        self::assertFileExists($this->avatarDir . '/' . $filename);

        $deleteResp = $app->handle($this->authedRequest('DELETE', '/account/avatar', $userId));
        self::assertSame(200, $deleteResp->getStatusCode());

        self::assertFileDoesNotExist($this->avatarDir . '/' . $filename);
        $row = $this->conn->fetchOne('SELECT avatar_filename FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNull($row);

        $profileData = json_decode(
            (string) $app->handle($this->authedRequest('GET', '/account/profile', $userId))->getBody(),
            true,
        );
        self::assertNull($profileData['avatar_url']);
    }

    public function test_delete_avatar_without_csrf_is_rejected(): void
    {
        $userId   = $this->seedUser('user8@example.com');
        $response = $this->createApp()->handle($this->authedRequest('DELETE', '/account/avatar', $userId, withCsrf: false));
        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Account-scoping: user A can never affect user B's avatar/profile.
    // -------------------------------------------------------------------------

    public function test_user_cannot_affect_another_users_avatar(): void
    {
        $userA = $this->seedUser('userA@example.com');
        $userB = $this->seedUser('userB@example.com');
        $app   = $this->createApp();

        $uploadedB = json_decode((string) $app->handle($this->uploadRequest($userB, $this->makePngBytes()))->getBody(), true);
        self::assertStringStartsWith('/avatar/', $uploadedB['avatar_url']);

        // User A deletes THEIR OWN (nonexistent) avatar — must not touch B's.
        $app->handle($this->authedRequest('DELETE', '/account/avatar', $userA));

        $profileB = json_decode(
            (string) $app->handle($this->authedRequest('GET', '/account/profile', $userB))->getBody(),
            true,
        );
        self::assertSame($uploadedB['avatar_url'], $profileB['avatar_url'], "user A's action must never affect user B's avatar");
    }

    // -------------------------------------------------------------------------
    // Serving unknown/tampered filenames
    // -------------------------------------------------------------------------

    public function test_serving_unknown_avatar_filename_returns_404(): void
    {
        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/avatar/' . str_repeat('0', 32) . '.jpg'),
        );
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_path_traversal_filename_is_rejected(): void
    {
        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/avatar/..%2F..%2Fconfig%2Fconfig.php'),
        );
        self::assertContains($response->getStatusCode(), [404, 400]);
    }

    // -------------------------------------------------------------------------
    // Social links (4 fixed identifiers — profile-avatar-social security redesign)
    // -------------------------------------------------------------------------

    /** @param array<string, string> $fields e.g. ['website_domain' => 'example.com'] */
    private function putSocialLinksRequest(?int $userId, array $fields, bool $withCsrf = true): ServerRequestInterface
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();

        $cookies = [];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);
        }
        if ($withCsrf) {
            $cookies['votepit_csrf'] = $csrf->sign($csrfToken);
            $fields['_csrf']         = $csrfToken;
        }

        return (new ServerRequestFactory())->createServerRequest('PUT', '/account/social-links')
            ->withCookieParams($cookies)
            ->withParsedBody($fields);
    }

    /** @return array{website_domain: ?string, x_handle: ?string, youtube_handle: ?string, github_username: ?string} */
    private function userSocialColumns(int $userId): array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT website_domain, x_handle, youtube_handle, github_username FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertIsArray($row);

        return [
            'website_domain'  => is_string($row['website_domain'] ?? null) ? $row['website_domain'] : null,
            'x_handle'        => is_string($row['x_handle'] ?? null) ? $row['x_handle'] : null,
            'youtube_handle'  => is_string($row['youtube_handle'] ?? null) ? $row['youtube_handle'] : null,
            'github_username' => is_string($row['github_username'] ?? null) ? $row['github_username'] : null,
        ];
    }

    public function test_save_valid_social_links(): void
    {
        $userId = $this->seedUser('linksuser1@example.com');
        $app    = $this->createApp();

        $response = $app->handle($this->putSocialLinksRequest($userId, [
            'website_domain'  => 'example.com',
            'x_handle'        => '@myhandle',
            'youtube_handle'  => 'my-channel',
            'github_username' => 'octocat',
        ]));

        self::assertSame(200, $response->getStatusCode());

        $row = $this->userSocialColumns($userId);
        self::assertSame('example.com', $row['website_domain']);
        // Leading "@" is stripped before storage.
        self::assertSame('myhandle', $row['x_handle']);
        self::assertSame('my-channel', $row['youtube_handle']);
        self::assertSame('octocat', $row['github_username']);
    }

    public function test_all_fields_optional_partial_fill_is_accepted(): void
    {
        $userId   = $this->seedUser('linksuser1b@example.com');
        $response = $this->createApp()->handle($this->putSocialLinksRequest($userId, [
            'website_domain' => 'example.com',
        ]));

        self::assertSame(200, $response->getStatusCode());

        $row = $this->userSocialColumns($userId);
        self::assertSame('example.com', $row['website_domain']);
        self::assertNull($row['x_handle']);
        self::assertNull($row['youtube_handle']);
        self::assertNull($row['github_username']);
    }

    public function test_full_url_as_website_is_rejected(): void
    {
        $userId   = $this->seedUser('linksuser2@example.com');
        $response = $this->createApp()->handle($this->putSocialLinksRequest($userId, [
            'website_domain' => 'https://example.com',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $row = $this->userSocialColumns($userId);
        self::assertNull($row['website_domain']);
    }

    public function test_javascript_scheme_as_website_is_rejected(): void
    {
        $userId   = $this->seedUser('linksuser3@example.com');
        $response = $this->createApp()->handle($this->putSocialLinksRequest($userId, [
            'website_domain' => 'javascript:alert(1)',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_ip_literal_as_website_is_rejected(): void
    {
        $userId   = $this->seedUser('linksuser3b@example.com');
        $response = $this->createApp()->handle($this->putSocialLinksRequest($userId, [
            'website_domain' => '127.0.0.1',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_overlong_x_handle_is_rejected(): void
    {
        $userId   = $this->seedUser('linksuser4@example.com');
        $response = $this->createApp()->handle($this->putSocialLinksRequest($userId, [
            'x_handle' => str_repeat('a', 16),
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_leading_at_in_youtube_handle_is_rejected(): void
    {
        $userId   = $this->seedUser('linksuser4b@example.com');
        $response = $this->createApp()->handle($this->putSocialLinksRequest($userId, [
            'youtube_handle' => '@my-channel',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_github_username_with_consecutive_hyphens_is_rejected(): void
    {
        $userId   = $this->seedUser('linksuser4c@example.com');
        $response = $this->createApp()->handle($this->putSocialLinksRequest($userId, [
            'github_username' => 'octo--cat',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_empty_string_clears_a_field(): void
    {
        $userId = $this->seedUser('linksuser5@example.com');
        $app    = $this->createApp();

        $app->handle($this->putSocialLinksRequest($userId, ['website_domain' => 'example.com']));
        self::assertSame('example.com', $this->userSocialColumns($userId)['website_domain']);

        $app->handle($this->putSocialLinksRequest($userId, ['website_domain' => '']));
        self::assertNull($this->userSocialColumns($userId)['website_domain']);
    }

    public function test_saving_replaces_only_the_submitted_fields(): void
    {
        $userId = $this->seedUser('linksuser6@example.com');
        $app    = $this->createApp();

        $app->handle($this->putSocialLinksRequest($userId, [
            'website_domain' => 'old.example.com',
            'x_handle'       => 'oldhandle',
        ]));
        $app->handle($this->putSocialLinksRequest($userId, [
            'website_domain' => 'new.example.com',
        ]));

        $row = $this->userSocialColumns($userId);
        self::assertSame('new.example.com', $row['website_domain']);
        // x_handle was omitted from the second request — empty string
        // semantics apply (the field is cleared, not left untouched), since
        // the form always submits its full current state.
        self::assertNull($row['x_handle']);
    }

    public function test_social_links_without_csrf_is_rejected(): void
    {
        $userId   = $this->seedUser('linksuser7@example.com');
        $response = $this->createApp()->handle($this->putSocialLinksRequest($userId, [
            'website_domain' => 'example.com',
        ], withCsrf: false));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_social_links_as_anon_is_rejected(): void
    {
        $response = $this->createApp()->handle($this->putSocialLinksRequest(null, [
            'website_domain' => 'example.com',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_user_cannot_affect_another_users_social_links(): void
    {
        $userA = $this->seedUser('scopeA@example.com');
        $userB = $this->seedUser('scopeB@example.com');
        $app   = $this->createApp();

        $app->handle($this->putSocialLinksRequest($userB, ['website_domain' => 'b.example.com']));
        $app->handle($this->putSocialLinksRequest($userA, ['website_domain' => 'a.example.com']));

        self::assertSame('b.example.com', $this->userSocialColumns($userB)['website_domain']);
    }

    // -------------------------------------------------------------------------
    // Username (optional, globally unique display name)
    // -------------------------------------------------------------------------

    private function putUsernameRequest(?int $userId, string $username, bool $withCsrf = true): ServerRequestInterface
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();

        $cookies = [];
        $body    = ['username' => $username];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);
        }
        if ($withCsrf) {
            $cookies['votepit_csrf'] = $csrf->sign($csrfToken);
            $body['_csrf']           = $csrfToken;
        }

        return (new ServerRequestFactory())->createServerRequest('PUT', '/account/username')
            ->withCookieParams($cookies)
            ->withParsedBody($body);
    }

    private function userUsername(int $userId): ?string
    {
        $value = $this->conn->fetchOne('SELECT username FROM users WHERE id = :id', ['id' => $userId]);
        return is_string($value) ? $value : null;
    }

    public function test_save_valid_username(): void
    {
        $userId   = $this->seedUser('usernameuser1@example.com');
        $response = $this->createApp()->handle($this->putUsernameRequest($userId, 'maxmustermann'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('maxmustermann', $this->userUsername($userId));

        $profileData = json_decode(
            (string) $this->createApp()->handle($this->authedRequest('GET', '/account/profile', $userId))->getBody(),
            true,
        );
        self::assertSame('maxmustermann', $profileData['username']);
    }

    public function test_empty_string_clears_the_username(): void
    {
        $userId = $this->seedUser('usernameuser2@example.com');
        $app    = $this->createApp();

        $app->handle($this->putUsernameRequest($userId, 'maxmustermann'));
        self::assertSame('maxmustermann', $this->userUsername($userId));

        $app->handle($this->putUsernameRequest($userId, ''));
        self::assertNull($this->userUsername($userId));
    }

    public function test_invalid_username_is_rejected_with_422(): void
    {
        $userId   = $this->seedUser('usernameuser3@example.com');
        $response = $this->createApp()->handle($this->putUsernameRequest($userId, 'a'));

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($this->userUsername($userId));
    }

    public function test_taken_username_is_rejected_with_409_case_insensitively(): void
    {
        $userA = $this->seedUser('usernameuser4a@example.com');
        $userB = $this->seedUser('usernameuser4b@example.com');
        $app   = $this->createApp();

        $app->handle($this->putUsernameRequest($userA, 'maxmustermann'));
        $response = $app->handle($this->putUsernameRequest($userB, 'MaxMustermann'));

        self::assertSame(409, $response->getStatusCode());
        self::assertNull($this->userUsername($userB));
    }

    public function test_username_without_csrf_is_rejected(): void
    {
        $userId   = $this->seedUser('usernameuser5@example.com');
        $response = $this->createApp()->handle($this->putUsernameRequest($userId, 'maxmustermann', withCsrf: false));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_username_as_anon_is_rejected(): void
    {
        $response = $this->createApp()->handle($this->putUsernameRequest(null, 'maxmustermann'));
        self::assertSame(401, $response->getStatusCode());
    }
}
