<?php

namespace App\Http\Controllers\Preferences;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Requests\Preferences\UpdateLanguageRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/**
 * Switch the language a visitor reads the guest app or a panel in.
 *
 * A full round trip rather than something React does on its own, and
 * deliberately: half of what a guest reads — the item names, the sections, the
 * tiles — is translated in the database, so only the server can answer in
 * another language. Redirecting back re-renders the page they were on with
 * everything, chrome and content alike, in the language they picked.
 */
class UpdateLanguageController extends Controller
{
    /**
     * How long the choice is remembered on this phone.
     */
    private const int REMEMBER_MINUTES = 60 * 24 * 365;

    public function __invoke(UpdateLanguageRequest $request): RedirectResponse
    {
        return back()->withCookie(Cookie::make(
            name: SetLocale::COOKIE,
            value: $request->locale()->value,
            minutes: self::REMEMBER_MINUTES,
            // Unencrypted so the guest app can read its own current
            // language; see bootstrap/app.php, where it is excepted alongside
            // the appearance cookie for the same reason.
            httpOnly: false,
        ));
    }
}
