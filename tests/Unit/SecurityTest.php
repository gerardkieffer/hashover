<?php

declare(strict_types=1);

namespace HashOver\Tests\Unit;

use HashOver\Exception\UserError;
use HashOver\Http\Request;
use HashOver\Security\Auth;
use HashOver\Security\EmailCipher;
use HashOver\Security\FormGuard;
use HashOver\Security\Keys;
use HashOver\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    public function testKeysAreIndependentPerPurposeAndSecret(): void
    {
        $keys = new Keys(TestConfig::create());
        $other = new Keys(TestConfig::create(['secret_key' => str_repeat('z', 40)]));

        self::assertSame(32, strlen($keys->for('email')));
        self::assertNotSame($keys->for('email'), $keys->for('login'));
        self::assertNotSame($keys->for('email'), $other->for('email'));
    }

    public function testEmailEncryption(): void
    {
        $cipher = new EmailCipher(new Keys(TestConfig::create()));
        $a = $cipher->encrypt('alice@example.org');
        $b = $cipher->encrypt('alice@example.org');

        self::assertNotNull($a);
        self::assertNotSame($a, $b, 'A random nonce is used');
        self::assertSame('alice@example.org', $cipher->decrypt($a));
        self::assertNull($cipher->encrypt(''));
        self::assertSame('', $cipher->decrypt(null));
        self::assertSame('', $cipher->decrypt('not base64!'));

        // Tampering is detected
        $tampered = base64_decode($a, true);
        self::assertIsString($tampered);
        $tampered[30] = chr(ord($tampered[30]) ^ 1);
        self::assertSame('', $cipher->decrypt(base64_encode($tampered)));

        // Another secret can't decrypt
        self::assertSame('', new EmailCipher(new Keys(TestConfig::create(['secret_key' => str_repeat('z', 40)])))->decrypt($a));
    }

    public function testLoginTokens(): void
    {
        $auth = new Auth(TestConfig::create(), new Keys(TestConfig::create()));

        self::assertSame($auth->loginToken('Alice', 'pw'), $auth->loginToken(' alice ', 'pw'));
        self::assertNotSame($auth->loginToken('Alice', 'pw'), $auth->loginToken('Alice', 'pw2'));
        self::assertNotSame($auth->loginToken('Alice', 'pw'), $auth->loginVerifier($auth->loginToken('Alice', 'pw')));
    }

    public function testCsrfTokenDependsOnLoginCookies(): void
    {
        $auth = new Auth(TestConfig::create(), new Keys(TestConfig::create()));

        $anonymous = $auth->csrfToken(new Request());
        $loggedIn = $auth->csrfToken(new Request(cookies: ['hashover-login' => 'token']));

        self::assertNotSame($anonymous, $loggedIn);
        self::assertTrue($auth->isValidCsrfToken(new Request(post: ['csrf' => $loggedIn], cookies: ['hashover-login' => 'token'])));
        self::assertFalse($auth->isValidCsrfToken(new Request(post: ['csrf' => $anonymous], cookies: ['hashover-login' => 'token'])));
    }

    public function testFormGuard(): void
    {
        $config = TestConfig::create(['minimum_submit_seconds' => 3]);
        $guard = new FormGuard($config, new Keys($config));

        $guard->check(new Request(post: ['ts' => $guard->timestamp(time() - 10)]));

        foreach ([$guard->timestamp(), $guard->timestamp(time() - 8 * 86400), '123.abc', ''] as $timestamp) {
            try {
                $guard->check(new Request(post: ['ts' => $timestamp]));
                self::fail('Accepted ' . $timestamp);
            } catch (UserError $error) {
                self::assertSame(400, $error->status);
            }
        }

        $this->expectException(UserError::class);
        $guard->check(new Request(post: ['ts' => $guard->timestamp(time() - 10), 'hashover-hp' => 'x']));
    }
}
