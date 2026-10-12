<?php

declare(strict_types=1);

namespace App\Web\Admin\Auth;

use App\Shared\Audit\AuditLog;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_array;
use function is_string;
use function password_hash;
use function sleep;
use function str_starts_with;
use function strlen;

/**
 * Login, logout, and a helper that turns a chosen password into the ADMIN_PASSWORD_HASH line for `.env` (the hash is
 * only displayed, never stored, so knowing the page gives no access).
 */
final class LoginPages
{
    private const FAILURES_KEY = 'admin.login_failures';

    public function __construct(
        private readonly AdminAuth $auth,
        private readonly SessionInterface $session,
        private readonly WebViewRenderer $viewRenderer,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly AuditLog $audit,
        private readonly LoggerInterface $logger,
    ) {}

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page('login', ['error' => null, 'next' => self::next($request), 'configured' => $this->auth->isConfigured(), 'user' => '']);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $user = self::field($request, 'user');
        $password = self::field($request, 'password');
        $next = self::next($request);

        if ($this->auth->verify($user, $password)) {
            $this->session->regenerateId();
            $this->session->set(AdminAuth::SESSION_KEY, $user);
            $this->session->remove(self::FAILURES_KEY);
            $this->audit->record('admin', $user, 'login', 'dashboard');

            return $this->responseFactory->createResponse(303)->withHeader('Location', $next);
        }

        $failures = (int) $this->session->get(self::FAILURES_KEY, 0) + 1;
        $this->session->set(self::FAILURES_KEY, $failures);
        $this->logger->warning('Dashboard login failed', ['user' => $user, 'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? null, 'failures' => $failures]);
        sleep(min(5, $failures)); // slows down guessing

        return $this->page('login', ['error' => 'Wrong user name or password.', 'next' => $next, 'configured' => $this->auth->isConfigured(), 'user' => $user]);
    }

    public function logout(): ResponseInterface
    {
        $this->session->remove(AdminAuth::SESSION_KEY);
        $this->session->regenerateId();

        return $this->responseFactory->createResponse(303)->withHeader('Location', '/login');
    }

    /** Shows the `.env` line for a chosen password. Never stores anything. */
    public function passwordHash(ServerRequestInterface $request): ResponseInterface
    {
        $line = null;
        $error = null;
        if ($request->getMethod() === 'POST') {
            $password = self::field($request, 'password');
            if (strlen($password) < 12) {
                $error = 'Use at least 12 characters.';
            } else {
                $line = 'ADMIN_PASSWORD_HASH=' . password_hash($password, PASSWORD_DEFAULT);
            }
        }

        return $this->page('password-hash', ['line' => $line, 'error' => $error, 'user' => $this->auth->user()]);
    }

    /** @param array<string, mixed> $params */
    private function page(string $view, array $params): ResponseInterface
    {
        return $this->viewRenderer
            ->withLayout(__DIR__ . '/../layout.php')
            ->render(__DIR__ . '/../views/' . $view, $params + ['adminNav' => ['collecting' => [], 'active' => '', 'refresh' => false, 'flash' => null, 'guest' => true]]);
    }

    /** Same-site path to return to after login. */
    private static function next(ServerRequestInterface $request): string
    {
        $body = $request->getParsedBody();
        $next = is_array($body) && is_string($body['next'] ?? null) ? $body['next'] : ($request->getQueryParams()['next'] ?? '/');

        return is_string($next) && str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/';
    }

    private static function field(ServerRequestInterface $request, string $name): string
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return '';
        }
        $value = $body[$name] ?? null;

        return is_string($value) ? $value : '';
    }
}
