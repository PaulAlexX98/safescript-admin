<?php

namespace Tests\Unit;

use App\Models\User;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Tests\TestCase;

class AdminMultiFactorAuthenticationTest extends TestCase
{
    public function test_admin_panel_offers_recoverable_app_authentication_without_locking_out_existing_staff(): void
    {
        $panel = Filament::getPanel('admin');
        $providers = $panel->getMultiFactorAuthenticationProviders();

        $this->assertFalse($panel->isMultiFactorAuthenticationRequired());
        $this->assertFalse($panel->hasRegistration());
        $this->assertArrayHasKey('app', $providers);
        $this->assertTrue($providers['app']->isRecoverable());
    }

    public function test_user_supports_encrypted_app_authentication_credentials(): void
    {
        $user = new User;

        $this->assertInstanceOf(HasAppAuthentication::class, $user);
        $this->assertInstanceOf(HasAppAuthenticationRecovery::class, $user);
        $this->assertSame('encrypted', $user->getCasts()['app_authentication_secret']);
        $this->assertSame('encrypted:array', $user->getCasts()['app_authentication_recovery_codes']);
        $this->assertContains('app_authentication_secret', $user->getHidden());
        $this->assertContains('app_authentication_recovery_codes', $user->getHidden());
    }
}
