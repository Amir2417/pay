<?php

namespace App\Http\Middleware;

use App\Models\Admin\BasicSettings;
use Closure;
use Illuminate\Http\Request;

class VerificationGuard
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();
        $basic_settings     = BasicSettings::first();
        if($basic_settings->email_verification == true){
            if($user->email_verified == false) return mailVerificationTemplate($user);
        }
        
        return $next($request);
    }
}
