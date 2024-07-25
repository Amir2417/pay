<?php

namespace App\Http\Middleware\Agent;

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
        $agent = auth()->user();
        $basic_settings     = BasicSettings::first();
        if($basic_settings->agent_email_verification == true){
            if($agent->email_verified == false) return mailVerificationTemplateAgent($agent);
        }
        
        return $next($request);
    }
}
