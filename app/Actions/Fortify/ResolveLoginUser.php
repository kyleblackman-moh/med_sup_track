<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;
use LogicException;

/**
 * MFA: the ONE place a login checks the email and password.
 *
 * Fortify's own pipeline runs the authenticateUsing callback (AuthenticateUser,
 * with its lockout counting and audit rows) in RedirectIfTwoFactorAuthenticatable
 * and then again in AttemptToAuthenticate, so each login would count a bad
 * password twice and write two audit rows. This step calls the callback once
 * and keeps the user on the request; the two-factor step and LoginResolvedUser
 * reuse it instead of validating the credentials again.
 *
 * A failure behaves exactly like Fortify's AttemptToAuthenticate: the Failed
 * event, one hit on the login rate limiter and the generic auth.failed message.
 */
class ResolveLoginUser
{
    /** Request attribute holding the user whose credentials were accepted. */
    public const ATTRIBUTE = 'mfa.login_user';

    public function __construct(private readonly LoginRateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $callback = Fortify::$authenticateUsingCallback;

        if ($callback === null) {
            throw new LogicException('Fortify::authenticateUsing() must be set (FortifyServiceProvider).');
        }

        $user = $callback($request);

        if (! $user instanceof User) {
            event(new Failed(config('fortify.guard'), null, [
                Fortify::username() => $request->input(Fortify::username()),
                'password' => $request->input('password'),
            ]));

            $this->limiter->increment($request);

            throw ValidationException::withMessages([
                Fortify::username() => [trans('auth.failed')],
            ]);
        }

        $request->attributes->set(self::ATTRIBUTE, $user);

        return $next($request);
    }

    /** The user accepted earlier in this request's pipeline. */
    public static function user(Request $request): User
    {
        $user = $request->attributes->get(self::ATTRIBUTE);

        if (! $user instanceof User) {
            throw new LogicException('ResolveLoginUser must run before this login step.');
        }

        return $user;
    }
}
