<?php

declare(strict_types=1);

namespace HashOver\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

abstract class IntegrationTestCase extends TestCase
{
	protected TestServer $server;

	protected function setUp(): void
	{
		$this->server = TestServer::get();
		$this->server->resetPages();
		$this->errorsBefore = $this->server->phpErrors();
	}

	private string $errorsBefore = '';

	protected function tearDown(): void
	{
		// No test may cause PHP warnings, notices or deprecations
		$errors = trim(substr($this->server->phpErrors(), strlen($this->errorsBefore)));
		$this->assertSame('', $errors, 'PHP errors were logged by the server');
	}

	protected function client(): Client
	{
		return new Client($this->server);
	}

	/** Load a comment file of the "js.html" thread */
	protected function commentFile(string $id, string $thread = 'js-html'): SimpleXMLElement
	{
		$file = $this->server->pagesDir($thread) . '/' . $id . '.xml';
		$this->assertFileExists($file);

		$xml = simplexml_load_file($file);
		$this->assertInstanceOf(SimpleXMLElement::class, $xml);

		return $xml;
	}

	protected function assertCommentMissing(string $id, string $thread = 'js-html'): void
	{
		$this->assertFileDoesNotExist($this->server->pagesDir($thread) . '/' . $id . '.xml');
	}

	/** Render JavaScript mode for the "js.html" page */
	protected function renderJs(Client $client, string $page = '/js.html'): Response
	{
		return $client->get('/hashover/comments.php', ['Referer' => $this->server->baseUrl() . $page]);
	}

	/** Log in as the administrator */
	protected function admin(): Client
	{
		$admin = $this->client();
		$admin->comment(['name' => TestServer::ADMIN_NAME, 'password' => TestServer::ADMIN_PASSWORD, 'login' => '', 'comment' => '']);
		$this->assertNotNull($admin->cookie('hashover-login'));

		return $admin;
	}
}
