<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Exceptions\AuthenticationException;
use Laravel\Passport\Http\Middleware\ValidateToken;
use Laravel\Passport\Passport;
use Laravel\Passport\Contracts\ScopeAuthorizable;
use Laravel\Passport\Exceptions\MissingScopeException;
use Symfony\Component\HttpFoundation\Response;

class IdentifyOAuthClient extends ValidateToken
{
    protected function validate(ScopeAuthorizable $token, string ...$params): void
    {
        if (! $token instanceof AccessToken) {
            throw new AuthenticationException;
        }

        $clientId = $token->oauth_client_id;

        if (! is_string($clientId) || $clientId === '' || ! Passport::client()->newQuery()
            ->whereKey($clientId)
            ->where('revoked', false)
            ->exists()) {
            throw new AuthenticationException;
        }

        foreach ($params as $scope) {
            if ($token->cant($scope)) {
                throw new MissingScopeException($scope);
            }
        }
    }

    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $token = $this->validateToken($request);

        $this->validate($token, ...$params);

        $request->attributes->set('oauth_client_id', $token->oauth_client_id);

        return $next($request);
    }
}
