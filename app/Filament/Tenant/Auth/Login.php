<?php

namespace App\Filament\Restaurant\Auth;

use App\Enums\FilamentPanel;
use App\Filament\Auth\OtpLogin;

/**
 * Sign-in for a single restaurant's panel, served from its subdomain.
 */
class Login extends OtpLogin
{
    protected function panel(): FilamentPanel
    {
        return FilamentPanel::Restaurant;
    }
}
