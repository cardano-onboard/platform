<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\AuthenticateSession as BaseAuthenticateSession;

/**
 * Laravel's session check, with one gap closed.
 *
 * The framework middleware treats a session that has a user but no stored password hash as
 * new and records the account's current hash. Such a session can come from a login made
 * before the hash was written at login, and it would then survive a password change that
 * happened before its first request. A session like that is signed out here instead. The
 * one case that legitimately has no hash yet is a session created from the remember-me
 * cookie on this very request, and the framework stores the hash for it afterwards.
 */
class AuthenticateSession extends BaseAuthenticateSession
{
    public function handle($request, Closure $next)
    {
        if ($request->hasSession()
            && $request->user()
            && ! $this->guard()->viaRemember()
            && $request->session()->has($this->guard()->getName())
            && ! $request->session()->has('password_hash_'.$this->auth->getDefaultDriver())) {
            $this->logout($request);
        }

        return parent::handle($request, $next);
    }
}
