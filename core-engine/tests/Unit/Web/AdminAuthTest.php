<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Web\Admin\Auth\AdminAuth;
use Codeception\Test\Unit;

use function password_hash;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

final class AdminAuthTest extends Unit
{
    public function testOnlyTheConfiguredUserAndPasswordAreAccepted(): void
    {
        $auth = new AdminAuth('admin', password_hash('correct horse battery', PASSWORD_DEFAULT));

        assertTrue($auth->verify('admin', 'correct horse battery'));
        assertFalse($auth->verify('admin', 'wrong password'));
        assertFalse($auth->verify('root', 'correct horse battery'));
    }

    public function testNothingIsAcceptedWithoutAConfiguredHash(): void
    {
        $auth = new AdminAuth('admin', '');

        assertFalse($auth->isConfigured());
        assertFalse($auth->verify('admin', ''));
        assertFalse($auth->verify('admin', 'anything'));
    }
}
