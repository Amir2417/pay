<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Http\Helpers\Api\Helpers;
use App\Models\Admin\BasicSettings;
use App\Http\Helpers\ResponseHelper;
use Illuminate\Support\Facades\Auth;

class CheckStatusApiUser
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

        if((Auth::user()->email_verified == 1 ) && (Auth::user()->sms_verified == 1 ) && (Auth::user()->status == 1)){
            return $next($request);
        }else{
            if(Auth::user()->status == 0){
                $error = ['errors'=>[__('Account Is Deactivated')]];
                return Helpers::error($error);
            }
            $basic_settings = BasicSettings::first();
            if($basic_settings->email_verification == true){
                if($user->email_verified == 0){;
                   return mailVerificationTemplateApi($user);
                }
            }
            if($basic_settings->sms_verification == true){
                if($user->sms_verified == 0){;
                   return smsVerificationTemplateApi($user);
                }
            }
            
        }
    }
}
