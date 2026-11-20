<?php

namespace App\Http\Requests\Auth;

use App\Support\ClientAddress;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * How long a failed attempt counts against its source. The limit is per hour.
     */
    private const SOURCE_WINDOW_SECONDS = 3600;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureSourceIsNotRateLimited();
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->sourceThrottleKey(), self::SOURCE_WINDOW_SECONDS);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Only the per-address count is cleared. The per-source count is never reduced by a
        // success, so signing in to an account of one's own does not buy more guesses.
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure this source has not failed too many logins, whatever addresses it tried.
     *
     * The per-address lockout below stops one account being guessed at; this stops one
     * source cycling through addresses to get around it. It counts failed attempts only,
     * so a source that signs in successfully is never held up, and it is keyed on the
     * source alone, so a source past the limit never affects any other. Form posts through Inertia
     * get the wait as an error on the email field; any other client gets a 429.
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    public function ensureSourceIsNotRateLimited(): void
    {
        $max = max(1, (int) config('auth.login_limits.failed_per_ip_per_hour'));

        if (! RateLimiter::tooManyAttempts($this->sourceThrottleKey(), $max)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->sourceThrottleKey());
        $minutes = max(1, (int) ceil($seconds / 60));
        $message = 'Too many failed sign-in attempts. Try again in '.$minutes.' '.Str::plural('minute', $minutes).'.';

        if ($this->header('X-Inertia')) {
            throw ValidationException::withMessages(['email' => $message]);
        }

        throw new HttpResponseException(response($message, 429, ['Retry-After' => $seconds]));
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        // Hashed with the application key, so the cache never holds the address and a list of
        // addresses cannot be tested against it. The client address is the exact one, not its
        // network: this lockout is for one account being guessed at, and a network is held to
        // its failure limit by sourceThrottleKey(). Counting the network here would let anyone
        // in an operator's /64 keep that operator's address locked.
        return hash_hmac('sha256', Str::transliterate(Str::lower($this->input('email'))), (string) config('app.key'))
            .'|'.$this->ip();
    }

    /**
     * Get the rate limiting key for failed attempts from this request's source. An IPv6
     * address counts as its /64, so one machine on one network cannot start again with a new
     * address.
     */
    public function sourceThrottleKey(): string
    {
        return 'login-failures:ip:'.ClientAddress::key($this->ip());
    }
}
