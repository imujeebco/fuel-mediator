<?php

declare(strict_types=1);

namespace App\Web\Admin\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Session\SessionInterface;

use function in_array;
use function rawurlencode;
use function str_starts_with;

/**
 * Protects every dashboard page and action. Login pages stay reachable; everything else needs a logged-in admin,
 * except on a machine-local request while no admin password is configured yet.
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    private const OPEN_PATHS = ['/login', '/login/password-hash'];

    public function __construct(
        private readonly AdminAuth $auth,
        private readonly SessionInterface $session,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (in_array($path, self::OPEN_PATHS, true) || str_starts_with($path, '/assets/')) {
            return $handler->handle($request);
        }
        if ($this->session->get(AdminAuth::SESSION_KEY) !== null) {
            return $handler->handle($request);
        }
        if (!$this->auth->isConfigured() && AdminAuth::isLocal($request)) {
            return $handler->handle($request);
        }
        if ($request->getMethod() !== 'GET') {
            return $this->responseFactory->createResponse(403);
        }
        $back = $path . ($request->getUri()->getQuery() !== '' ? '?' . $request->getUri()->getQuery() : '');

        return $this->responseFactory->createResponse(302)->withHeader('Location', '/login?next=' . rawurlencode($back));
    }
}
