<?php

namespace App\Http\Middleware\Merchant;

use Closure;
use Illuminate\Http\Request;
use App\Models\Admin\BasicSettings;

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
        $merchant = auth()->user();
        $basic_settings = BasicSettings::first();
        if ($basic_settings->merchant_email_verification == true) {
            if($merchant->email_verified == false) return mailVerificationTemplateMerchant($merchant);
        }
        return $next($request);
    }
}
