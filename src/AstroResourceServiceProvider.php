<?php

namespace Omatech\AstroResource;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class AstroResourceServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->routes(function(Route $route) {
            $route::prefix('api/astro')->middleware(ValidateAuth::class)->post('routes', function() {
                return response()->json(AstroResource::routes());
            });
        });
    }
}
