<?php

namespace Omatech\AstroResource;

use App\Exceptions\EditoraNotFoundHttpException;
use Closure;
use Illuminate\Http\Request;

class ValidateAuth
{
    public function handle(Request $request, Closure $next)
    {
        if($request->hasHeader('astro-token') && $request->header('astro-token') === config('editora.astroToken')) {
            return $next($request);
        }
        throw new EditoraNotFoundHttpException();
    }
}
