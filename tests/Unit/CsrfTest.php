<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Config;
use Pfpms\Http\HttpException;
use Pfpms\Security\Csrf;

/** CSRF refusals are the envelope's 400 csrf_failed (50-design §5.2, §5.5), for the token and for the browser's origin. */
final class CsrfTest extends TestCase
{
    private const TOKEN = 'a3f1c2d4e5b6a7980123456789abcdef0123456789abcdef0123456789abcdef';

    private array $server;
    private array $post;
    private ?array $session;
    private array $saved;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->post = $_POST;
        $this->session = $_SESSION ?? null;
        $this->saved = Config::snapshot();
        $app = $this->saved['app'] ?? [];
        $app['base_url'] = 'http://localhost:8088';
        Config::override(['app' => $app] + $this->saved);
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_SEC_FETCH_SITE']);
        $_POST = [];
        $_SESSION = ['csrf' => self::TOKEN];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_POST = $this->post;
        if ($this->session === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->session;
        }
        Config::override($this->saved);
    }

    private function refusal(): HttpException
    {
        try {
            Csrf::verify();
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail('expected Csrf::verify() to refuse');
    }

    private function assertCsrfFailed(string $message, string $why = ''): void
    {
        $e = $this->refusal();
        $this->assertSame([400, 'csrf_failed', $message], [$e->status, $e->code(), $e->getMessage()], $why);
    }

    public function testMissingTokenIsCsrfFailed400(): void
    {
        $this->assertCsrfFailed('This form has expired or was not sent from this site. Please reload the page and try again.');
    }

    public function testAWrongTokenIsCsrfFailed400(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('0', 64);
        $this->assertCsrfFailed('This form has expired or was not sent from this site. Please reload the page and try again.');
    }

    public function testASessionWithoutATokenRefusesEveryToken(): void
    {
        $_SESSION = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = self::TOKEN;
        $this->assertCsrfFailed('This form has expired or was not sent from this site. Please reload the page and try again.',
            'a server-side timeout restarted the session: the client re-reads api/session.php');
    }

    public function testTheHeaderTokenFromTheSameOriginPasses(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = self::TOKEN;
        $_SERVER['HTTP_ORIGIN'] = 'http://localhost:8088';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        Csrf::verify();
        $this->assertSame(self::TOKEN, $_SESSION['csrf'], 'verifying never rotates the token');
    }

    public function testTheFormFieldTokenPasses(): void
    {
        $_POST['_csrf'] = self::TOKEN;
        Csrf::verify();
        $this->assertSame(self::TOKEN, Csrf::token(), 'token() keeps the session\'s token');
    }

    public function testCrossSiteOriginIsRefused(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = self::TOKEN;
        foreach (['https://evil.example', 'http://localhost:8089', 'https://localhost:8088', 'http://localhost:8088.evil.example'] as $origin) {
            $_SERVER['HTTP_ORIGIN'] = $origin;
            $this->assertCsrfFailed('Cross-site request refused.', $origin);
        }
    }

    public function testTheOriginMatchIgnoresCaseAndATrailingSlash(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = self::TOKEN;
        $_SERVER['HTTP_ORIGIN'] = 'HTTP://LocalHost:8088/';
        Csrf::verify();
        $this->assertSame(self::TOKEN, $_SESSION['csrf']);
    }

    public function testCrossSiteSecFetchSiteIsRefused(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = self::TOKEN;
        foreach (['cross-site', 'same-site'] as $site) {
            $_SERVER['HTTP_SEC_FETCH_SITE'] = $site;
            $this->assertCsrfFailed('Cross-site request refused.', $site);
        }
    }

    public function testTheTokenIsCheckedBeforeTheOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $this->assertCsrfFailed('This form has expired or was not sent from this site. Please reload the page and try again.',
            'a missing token is reported as such, whatever the origin');
    }
}
