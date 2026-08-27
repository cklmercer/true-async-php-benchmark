<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Http\Request;
use App\Http\Response;
use Closure;

interface Middleware
{
    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response;
}
