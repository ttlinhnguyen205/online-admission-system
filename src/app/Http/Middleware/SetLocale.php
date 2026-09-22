<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('locale', config('app.locale', 'vi'));

        // Chỉ cho phép các ngôn ngữ hệ thống hỗ trợ
        if (! in_array($locale, ['vi', 'en'], true)) {
            $locale = 'vi';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
