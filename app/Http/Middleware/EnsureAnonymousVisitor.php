<?php

namespace App\Http\Middleware;

use App\Services\AnonymousVisitor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAnonymousVisitor
{
    public function __construct(
        private readonly AnonymousVisitor $visitors,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $visitorId = $this->visitors->ensureCookie($request);
        $request->attributes->set('visitor_id', $visitorId);

        return $next($request);
    }
}
