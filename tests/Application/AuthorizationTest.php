<?php

declare(strict_types=1);

namespace HashOver\Tests\Application;

use HashOver\Tests\Support\Browser;
use HashOver\Tests\Support\TestConfig;

final class AuthorizationTest extends ApplicationTestCase
{
    private Browser $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->browser();
        $this->alice->comment(['name' => 'Alice', 'password' => 'alice password', 'email' => 'alice@example.org', 'body' => 'original']);
    }

    public function testOwnerCanEditWithLoginCookie(): void
    {
        $response = $this->alice->post(['action' => 'edit', 'id' => '1', 'name' => 'Alice', 'body' => 'edited', 'email' => 'alice@example.org']);

        self::assertRedirectsWithMessage($response, 'message.comment_edited');
        $comment = $this->comment(1);
        self::assertNotNull($comment);
        self::assertSame('edited', $comment->body);
        self::assertNotNull($comment->updatedAt);
        self::assertFalse($comment->notify);
    }

    public function testStrangerCannotEditOrDelete(): void
    {
        $stranger = $this->browser();
        $edit = $stranger->post(['action' => 'edit', 'id' => '1', 'name' => 'Mallory', 'body' => 'hacked'], json: true);
        $delete = $stranger->post(['action' => 'delete', 'id' => '1', 'confirm' => '1'], json: true);

        self::assertSame(403, $edit->status);
        self::assertSame(403, $delete->status);
        self::assertSame('original', $this->comment(1)?->body);
    }

    public function testWrongPasswordIsRejected(): void
    {
        $response = $this->browser()->post(['action' => 'delete', 'id' => '1', 'confirm' => '1', 'password' => 'wrong'], json: true);

        self::assertSame(403, $response->status);
        self::assertSame('password', self::json($response)['field']);
        self::assertNotNull($this->comment(1));
    }

    public function testLoggingInWithSameNameAndPasswordGrantsAccessFromAnotherBrowser(): void
    {
        $other = $this->browser();
        $other->post(['action' => 'login', 'name' => 'alice', 'password' => 'alice password']);
        $other->post(['action' => 'delete', 'id' => '1', 'confirm' => '1']);

        self::assertNull($this->comment(1));
    }

    public function testSamePasswordWithAnotherNameGrantsNothing(): void
    {
        $bob = $this->browser();
        $bob->post(['action' => 'login', 'name' => 'Bob', 'password' => 'alice password']);
        $bob->post(['action' => 'edit', 'id' => '1', 'name' => 'Bob', 'body' => 'hacked']);

        self::assertSame('original', $this->comment(1)?->body);
    }

    public function testStoredVerifierIsNotTheCookieValue(): void
    {
        self::assertNotSame($this->alice->cookies['hashover-login'], $this->comment(1)?->loginVerifier);
    }

    public function testDeletingAsksForConfirmationWithoutJavaScript(): void
    {
        $response = $this->alice->post(['action' => 'delete', 'id' => '1']);

        self::assertSame(303, $response->status);
        self::assertStringContainsString('hashover_delete=1', (string) $response->header('Location'));
        self::assertNotNull($this->comment(1));

        $html = $this->alice->thread(TestConfig::PAGE, [])->body;
        self::assertStringNotContainsString('hashover-form-delete', $html);

        self::assertSame(400, $this->alice->post(['action' => 'delete', 'id' => '1'], json: true)->status);

        self::assertRedirectsWithMessage($this->alice->post(['action' => 'delete', 'id' => '1', 'confirm' => '1']), 'message.comment_deleted');
        self::assertNull($this->comment(1));
    }

    public function testCommentWithRepliesBecomesPlaceholder(): void
    {
        $this->browser()->comment(['body' => 'reply', 'parent' => '1']);
        $this->alice->post(['action' => 'delete', 'id' => '1', 'confirm' => '1']);

        $comment = $this->comment(1);
        self::assertNotNull($comment);
        self::assertTrue($comment->deleted);
        self::assertSame('', $comment->body);
        self::assertNull($comment->email);
        self::assertStringContainsString('This comment was deleted.', $this->alice->thread()->body);

        // The placeholder goes away with its last reply
        $this->admin()->post(['action' => 'delete', 'id' => '2', 'confirm' => '1']);
        self::assertSame(0, $this->commentCount());
    }

    public function testAdministratorCanEditAndDeleteAnyComment(): void
    {
        $admin = $this->admin();
        $admin->post(['action' => 'edit', 'id' => '1', 'name' => 'Alice', 'body' => 'moderated']);

        $comment = $this->comment(1);
        self::assertSame('moderated', $comment?->body);
        self::assertSame($this->alice->cookies['hashover-login'] !== '' ? 'alice@example.org' : '', $this->decryptEmail(1), 'The administrator keeps the author’s e-mail');

        $admin->post(['action' => 'delete', 'id' => '1', 'confirm' => '1']);
        self::assertNull($this->comment(1));
    }

    public function testAdminSessionExpiresAndCannotBeForged(): void
    {
        $forger = $this->browser();
        $forger->cookies['hashover-admin'] = (time() + 3600) . '.' . str_repeat('0', 64);
        $forger->post(['action' => 'delete', 'id' => '1', 'confirm' => '1']);
        self::assertNotNull($this->comment(1));

        $admin = $this->admin();
        [$expires] = explode('.', $admin->cookies['hashover-admin']);
        self::assertEqualsWithDelta(time() + 86400, (int) $expires, 5);

        // Changing the expiry invalidates the signature
        $admin->cookies['hashover-admin'] = ((int) $expires + 86400) . substr($admin->cookies['hashover-admin'], strlen($expires));
        $admin->post(['action' => 'delete', 'id' => '1', 'confirm' => '1']);
        self::assertNotNull($this->comment(1));
    }

    public function testWrongAdminPasswordIsRejected(): void
    {
        $response = $this->browser()->post(['action' => 'login', 'name' => TestConfig::ADMIN_NAME, 'password' => 'guess'], json: true);

        self::assertSame(403, $response->status);
        self::assertNull($response->cookie('hashover-admin'));
    }

    public function testOnlyTheAdministratorMayUseTheAdministratorName(): void
    {
        $response = $this->browser()->comment(['name' => ' boss ', 'body' => 'I am the boss'], json: true);
        self::assertSame(403, $response->status);
        self::assertSame('name', self::json($response)['field']);

        $this->alice->post(['action' => 'edit', 'id' => '1', 'name' => TestConfig::ADMIN_NAME, 'body' => 'x']);
        self::assertSame('Alice', $this->comment(1)?->name);

        $this->admin()->comment(['name' => TestConfig::ADMIN_NAME, 'body' => 'Real boss']);
        self::assertSame(TestConfig::ADMIN_NAME, $this->comment(2)?->name);
    }

    public function testPostingWithAdminCredentialsStartsAdminSession(): void
    {
        $browser = $this->browser();
        $browser->comment(['name' => TestConfig::ADMIN_NAME, 'password' => TestConfig::ADMIN_PASSWORD, 'body' => 'hello']);

        self::assertArrayHasKey('hashover-admin', $browser->cookies);
        self::assertArrayNotHasKey('hashover-login', $browser->cookies);
    }

    public function testLogoutClearsBothCookies(): void
    {
        $admin = $this->admin();
        $admin->cookies['hashover-login'] = 'x';
        self::assertRedirectsWithMessage($admin->post(['action' => 'logout']), 'message.logged_out');

        self::assertArrayNotHasKey('hashover-admin', $admin->cookies);
        self::assertArrayNotHasKey('hashover-login', $admin->cookies);
    }

    public function testEditLinksAreOnlyShownToOwnerAndAdministrator(): void
    {
        self::assertStringContainsString('hashover_edit=1', $this->alice->thread()->body);
        self::assertStringNotContainsString('hashover_edit=1', $this->browser()->thread()->body);
        self::assertStringContainsString('hashover_edit=1', $this->admin()->thread()->body);
    }

    public function testEditFormOnlyOpensForAuthorizedVisitors(): void
    {
        self::assertSame(404, $this->browser()->get(['action' => 'form', 'url' => TestConfig::PAGE, 'edit' => '1'])->status);

        $form = $this->alice->get(['action' => 'form', 'url' => TestConfig::PAGE, 'edit' => '1'])->body;
        self::assertStringContainsString('>original</textarea>', $form);
        self::assertStringContainsString('value="alice@example.org"', $form);

        // The administrator doesn't see the author's e-mail address
        self::assertStringNotContainsString('alice@example.org', $this->admin()->get(['action' => 'form', 'url' => TestConfig::PAGE, 'edit' => '1'])->body);
    }

    private function decryptEmail(int $id): string
    {
        return new \HashOver\Security\EmailCipher(new \HashOver\Security\Keys(TestConfig::create()))->decrypt($this->comment($id)?->email);
    }
}
