<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Builder\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeBuilder
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('rulecraft.builder.enabled'), 404);
        Gate::authorize(config('rulecraft.builder.ability', 'manage-rulecraft'));

        return $next($request);
    }
}
