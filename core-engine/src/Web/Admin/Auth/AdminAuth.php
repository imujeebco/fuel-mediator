<?php

declare(strict_types=1);

namespace App\Web\Admin\Auth;

use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;

use function hash_equals;
use function in_array;
use function password_verify;

/**
 * Admin login for the dashboard (one account from the environment).
 *
 * `ADMIN_USER` + `ADMIN_PASSWORD_HASH` (password_hash, bcrypt/argon). With no hash configured the dashboard is open to
 * requests from the machine itself only (development, SSH tunnel) and refused from anywhere else.
 */
final class AdminAuth
{
    public const SESSION_KEY = 'admin.user';

    public function __construct(
        private readonly string $user,
        #[SensitiveParameter] private readonly string $passwordHash,
    ) {}

    public function isConfigured(): bool
    {
        return $this->passwordHash !== '';
    }

    public function verify(string $user, #[SensitiveParameter] string $password): bool
    {
        // Always run password_verify so a wrong user name costs the same time as a wrong password.
        $passwordOk = password_verify($password, $this->isConfigured() ? $this->passwordHash : '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinv');

        return $this->isConfigured() && hash_equals($this->user, $user) && $passwordOk;
    }

    public function user(): string
    {
        return $this->user;
    }

    public static function isLocal(ServerRequestInterface $request): bool
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        return in_array($ip, ['127.0.0.1', '::1'], true);
    }
}
