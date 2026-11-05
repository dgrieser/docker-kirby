<?php

declare(strict_types=1);

namespace Mittwald\KirbySmoke\Tests;

use Mittwald\KirbySmoke\Container;
use Mittwald\KirbySmoke\Response;
use Mittwald\KirbySmoke\SmokeTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * With KIRBY_DEBUG on, Kirby's error page lists $_SERVER and $_ENV to whoever
 * triggers an error, and PHP copies the server's environment into both. The
 * variables that carry secrets are masked there, and the two that are only
 * needed before the server starts are gone by the time it does.
 */
final class DebugPageTest extends SmokeTestCase
{
	/** variable => [value, what must not appear on the page] */
	private const array SECRETS = [
		'KIRBY_ADMIN_PASSWORD' => ['admin-password-not-on-the-page', 'admin-password-not-on-the-page'],
		'KIRBY_LICENSE' => ['{"license":"license-not-on-the-page"}', 'license-not-on-the-page'],
		'KIRBY_EMAIL_PASSWORD' => ['mail-password-not-on-the-page', 'mail-password-not-on-the-page'],
		'KIRBY_CONTENT_SALT' => ['salt-not-on-the-page', 'salt-not-on-the-page'],
		'KIRBY_COOKIE_KEY' => ['cookie-key-not-on-the-page', 'cookie-key-not-on-the-page'],
		'KIRBY_OPTIONS_JSON' => ['{"smoke":"options-not-on-the-page"}', 'options-not-on-the-page'],
	];

	private const string VISIBLE = 'variable-on-the-page';

	private static Container $kirby;

	private static Response $page;

	public static function setUpBeforeClass(): void
	{
		$env = ['-e', 'KIRBY_DEBUG=true', '-e', 'KIRBY_ADMIN_EMAIL=admin@example.com', '-e', 'SMOKE_VISIBLE=' . self::VISIBLE];
		foreach (self::SECRETS as $variable => [$value]) {
			array_push($env, '-e', "{$variable}={$value}");
		}

		self::$kirby = self::startHealthy('debug', $env);

		// A preview token that is an array instead of a string throws a
		// TypeError in Kirby 4 (`token`) and 5 (`_token`) alike.
		self::$kirby->exec('sh', '-c', 'mkdir -p /app/content/_drafts/smoke && printf "Title: Smoke\n" > /app/content/_drafts/smoke/default.txt');
		self::$page = self::$kirby->get('/smoke?token%5B%5D=1&_token%5B%5D=1', headers: ['Accept' => 'text/html']);
	}

	/** Shows that the page does list the environment the secrets are hidden from. */
	public function testAnErrorShowsTheDebugPageWithTheEnvironment(): void
	{
		self::assertSame(500, self::$page->status);
		self::assertStringContainsString('Kirby CMS Debugger', self::$page->body);
		self::assertStringContainsString(self::VISIBLE, self::$page->body);
	}

	#[DataProvider('secrets')]
	public function testTheDebugPageDoesNotShow(string $variable): void
	{
		self::assertStringNotContainsString(self::SECRETS[$variable][1], self::$page->body);
	}

	public static function secrets(): array
	{
		return self::cases(...array_keys(self::SECRETS));
	}

	#[DataProvider('startOnlySecrets')]
	public function testTheServerDoesNotCarry(string $variable): void
	{
		self::assertSame(
			['getenv' => false, 'server' => false, 'env' => false],
			self::$kirby->webProbe('environment.php')[$variable],
		);
	}

	public static function startOnlySecrets(): array
	{
		return self::cases('KIRBY_ADMIN_PASSWORD', 'KIRBY_LICENSE');
	}

	/** Shows that the probe sees what is there, and that the mail password still reaches Kirby. */
	public function testTheServerStillCarriesWhatKirbyReadsOnEveryRequest(): void
	{
		$environment = self::$kirby->webProbe('environment.php');

		self::assertTrue($environment['SMOKE_VISIBLE']['getenv']);
		self::assertTrue($environment['KIRBY_EMAIL_PASSWORD']['getenv']);
	}

	public function testTheAdminWasCreatedBeforeThePasswordWasDropped(): void
	{
		self::assertSame(1, self::$kirby->probe('accounts.php')['count']);
	}
}
