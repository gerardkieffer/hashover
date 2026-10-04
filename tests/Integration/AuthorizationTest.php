<?php

declare(strict_types=1);

namespace HashOver\Tests\Integration;

final class AuthorizationTest extends IntegrationTestCase
{
	private Client $alice;

	protected function setUp(): void
	{
		parent::setUp();

		$this->alice = $this->client();
		$this->alice->comment(['name' => 'Alice', 'password' => 'alicepw', 'comment' => 'original']);
	}

	public function testStrangerCannotDeleteWithWrongPassword(): void
	{
		$this->client()->comment(['cmtfile' => '1', 'password' => 'nope', 'delete' => 'Delete', 'comment' => 'x']);

		$this->commentFile('1');
	}

	public function testStrangerCannotEditWithoutPassword(): void
	{
		$this->client()->comment(['cmtfile' => '1', 'edit' => 'Save', 'name' => 'Mallory', 'comment' => 'hacked']);

		$comment = $this->commentFile('1');
		$this->assertSame('Alice', (string) $comment->name);
		$this->assertSame('original', (string) $comment->body);
	}

	public function testOwnerCanEditWithLoginCookie(): void
	{
		$this->alice->comment(['cmtfile' => '1', 'edit' => 'Save', 'name' => 'Alice', 'comment' => 'edited']);

		$this->assertSame('edited', (string) $this->commentFile('1')->body);
	}

	public function testOwnerCanDeleteWithPassword(): void
	{
		$this->client()->comment(['cmtfile' => '1', 'password' => 'alicepw', 'delete' => 'Delete', 'comment' => 'x']);

		$this->assertCommentMissing('1');
	}

	public function testLoginCookieOfAnotherNameDoesNotGrantAccess(): void
	{
		$bob = $this->client();
		$bob->comment(['name' => 'Bob', 'password' => 'alicepw', 'login' => '', 'comment' => '']);
		$bob->comment(['cmtfile' => '1', 'edit' => 'Save', 'name' => 'Alice', 'comment' => 'hacked']);

		$this->assertSame('original', (string) $this->commentFile('1')->body);
	}

	public function testOnlyOwnerSeesEditLink(): void
	{
		$own = $this->renderJs($this->alice)->jsComments()[0];
		$other = $this->renderJs($this->client())->jsComments()[0];

		$this->assertArrayHasKey('edit_link', $own);
		$this->assertArrayNotHasKey('like_link', $own);
		$this->assertArrayNotHasKey('edit_link', $other);
		$this->assertArrayHasKey('like_link', $other);
	}

	public function testAdminCanDeleteAnyComment(): void
	{
		$this->admin()->comment(['cmtfile' => '1', 'delete' => 'Delete', 'comment' => 'x']);

		$this->assertCommentMissing('1');
	}

	public function testAdminCanEditWithoutChangingAuthorPassword(): void
	{
		$this->admin()->comment(['cmtfile' => '1', 'edit' => 'Save', 'name' => 'Alice', 'comment' => 'moderated']);

		$comment = $this->commentFile('1');
		$this->assertSame('moderated', (string) $comment->body);
		$this->assertTrue(password_verify('alicepw', (string) $comment->passwd));
	}

	public function testWrongAdminPasswordDoesNotGrantAdminRights(): void
	{
		$fake = $this->client();
		$fake->comment(['name' => TestServer::ADMIN_NAME, 'password' => 'guess', 'login' => '', 'comment' => '']);
		$fake->comment(['cmtfile' => '1', 'delete' => 'Delete', 'comment' => 'x']);

		$this->commentFile('1');
	}

	public function testForgedAdminCookieIsRejected(): void
	{
		$fake = $this->client();
		$fake->setCookie('hashover-login', hash('ripemd160', TestServer::ADMIN_NAME . md5(TestServer::ADMIN_PASSWORD)));
		$fake->setCookie('name', TestServer::ADMIN_NAME);
		$fake->setCookie('password', TestServer::ADMIN_PASSWORD);
		$fake->comment(['cmtfile' => '1', 'delete' => 'Delete', 'comment' => 'x']);

		$this->commentFile('1');
	}

	public function testOnlyAdminMayUseAdminNickname(): void
	{
		$response = $this->client()->comment(['name' => strtolower(TestServer::ADMIN_NAME), 'comment' => 'I am the admin']);

		$this->assertSame('/js.html#comments', $response->location());
		$this->assertCommentMissing('2');

		$this->admin()->comment(['name' => TestServer::ADMIN_NAME, 'comment' => 'Real admin']);
		$this->assertSame(TestServer::ADMIN_NAME, (string) $this->commentFile('2')->name);
	}

	public function testOwnerCannotRenameToAdminNickname(): void
	{
		$this->alice->comment(['cmtfile' => '1', 'edit' => 'Save', 'name' => TestServer::ADMIN_NAME, 'comment' => 'edited']);

		$this->assertSame('Alice', (string) $this->commentFile('1')->name);
	}

	public function testOldPasswordCookiesAreDeleted(): void
	{
		$client = $this->client();
		$client->setCookie('password', 'secret');
		$client->setCookie('hashover-alice', 'abc');
		$response = $this->renderJs($client);

		foreach (['password', 'hashover-alice'] as $name) {
			$this->assertArrayHasKey($name, $response->setCookies);
			$this->assertLessThan(time(), $response->setCookies[$name]['expires']);
			$this->assertNull($client->cookie($name));
		}
	}
}
