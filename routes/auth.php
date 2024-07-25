<?php

use App\Http\Controllers\Admin\Auth\LoginController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\Auth\ForgotPasswordController;
use App\Http\Controllers\Admin\Auth\ResetPasswordController;
use App\Http\Controllers\Agent\Auth\ForgotPasswordController as AgentAuthForgotPasswordController;
use App\Http\Controllers\Agent\Auth\LoginController as AgentAuthLoginController;
use App\Http\Controllers\Agent\Auth\RegisterController as AuthRegisterController;
use App\Http\Controllers\Agent\AuthorizationController as AgentAuthorizationController;
use App\Http\Controllers\Merchant\Auth\ForgotPasswordController as AuthForgotPasswordController;
use App\Http\Controllers\Merchant\Auth\LoginController as AuthLoginController;
use App\Http\Controllers\Merchant\Auth\RegisterController;
use App\Http\Controllers\Merchant\AuthorizationController as MerchantAuthorizationController;
use App\Http\Controllers\User\Auth\ForgotPasswordController as UserForgotPasswordController;
use App\Http\Controllers\User\Auth\LoginController as UserLoginController;
use App\Http\Controllers\User\Auth\RegisterController as UserRegisterController;
use App\Http\Controllers\User\AuthorizationController;

// Admin Authentication Route
Route::middleware(['guest','admin.login.guard'])->prefix('admin')->name('admin.')->group(function(){
    Route::get('/',function(){
        return redirect()->route('admin.login');
    });
    Route::get('login',[LoginController::class,"showLoginForm"])->name('login');
    Route::post('login/submit',[LoginController::class,"login"])->name('login.submit');

    Route::get('password/forgot',[ForgotPasswordController::class,"showLinkRequestForm"])->name('password.forgot');
    Route::post('password/forgot',[ForgotPasswordController::class,"sendResetLinkEmail"])->name('password.forgot.request');

    Route::get('password/reset/{token}',[ResetPasswordController::class,"showResetForm"])->name('password.reset');
    Route::post('password/update',[ResetPasswordController::class,'reset'])->name('password.update');
});

Route::name('user.')->group(function(){
    Route::get('login',[UserLoginController::class,"showLoginForm"])->name('login');
    Route::post('login',[UserLoginController::class,"login"])->name('login.submit');

    Route::get('register',[UserRegisterController::class,"showRegistrationForm"])->name('register')->middleware(['user.registration.permission']);
    
    // for sms 
    Route::post('register',[UserRegisterController::class,"register"])->name('register.submit')->middleware(['user.registration.permission']);
    Route::post('send/verify-code',[UserRegisterController::class,"sendVerifyCode"])->name('send.code')->middleware(['user.registration.permission']);
    Route::get('sms/verify/{token}',[AuthorizationController::class,"showSmsFormBeforRegister"])->name('sms.verify')->middleware(['user.registration.permission']);
    Route::post('verify/code/{token}',[UserRegisterController::class,"verifyCode"])->name('verify.code')->middleware(['user.registration.permission']);
    Route::get('resend/code',[UserRegisterController::class,"resendCode"])->name('resend.code')->middleware(['user.registration.permission']);
    Route::get('sms/otp/send/{token}',[AuthorizationController::class,"smsOtpSend"])->name('sms.otp.send')->middleware(['user.registration.permission']);
    Route::post('sms/otp/verify/code/{token}',[UserRegisterController::class,"smsOtpVerifyCode"])->name('sms.otp.verify.code')->middleware(['user.registration.permission']);
    Route::get('sms/otp/resend/code',[UserRegisterController::class,"smsOtpResendCode"])->name('sms.otp.resend.code')->middleware(['user.registration.permission']);


    // for email
    Route::get('email/verify/{token}',[AuthorizationController::class,"showMailFormBeforRegister"])->name('email.verify')->middleware(['user.registration.permission']);
    Route::get('email/send/otp/code/{token}',[AuthorizationController::class,"showEmailSendOtp"])->name('email.send.otp.code')->middleware(['user.registration.permission']);
    Route::post('email/verify/code/{token}',[UserRegisterController::class,"EmailVerifyCode"])->name('email.verify.code')->middleware(['user.registration.permission']);
    Route::get('email/resend/code',[UserRegisterController::class,"emailResendCode"])->name('email.resend.code')->middleware(['user.registration.permission']);
    Route::post('email/otp/verify/code/{token}',[UserRegisterController::class,"EmailOtpVerifyCode"])->name('email.otp.verify.code')->middleware(['user.registration.permission']);
    Route::get('email/otp/resend/code',[UserRegisterController::class,"emailOtpResendCode"])->name('email.otp.resend.code')->middleware(['user.registration.permission']);
    Route::get('register/kyc',[UserRegisterController::class,"registerKyc"])->name('register.kyc')->middleware(['user.registration.permission']);

    // recovery password by email
    Route::controller(UserForgotPasswordController::class)->prefix("password")->name("password.")->group(function(){
        Route::get('forgot','showForgotForm')->name('forgot');
        Route::post('forgot/send/code','sendCode')->name('forgot.send.code');
        Route::get('forgot/code/verify/form/{token}','showVerifyForm')->name('forgot.code.verify.form');
        Route::post('forgot/verify/{token}','verifyCode')->name('forgot.verify.code');
        Route::get('forgot/resend/code/{token}','resendCode')->name('forgot.resend.code');
        Route::get('forgot/reset/form/{token}','showResetForm')->name('forgot.reset.form');
        Route::post('forgot/reset/{token}','resetPassword')->name('reset');

    });
    Route::controller(AuthorizationController::class)->prefix("authorize")->name('authorize.')->middleware("auth")->group(function(){
        Route::get('mail/{token}','showMailFrom')->name('mail');
        Route::get('sms/{token}','showSMSFrom')->name('sms');
        Route::post('mail/verify/{token}','mailVerify')->name('mail.verify');
        Route::post('sms/verify/{token}','smsVerify')->name('sms.verify');
        Route::get('resend/code','resendCode')->name('resend.code');
        Route::get('sms/resend/code','smsResendCode')->name('sms.resend.code');
        Route::get('kyc','showKycFrom')->name('kyc');
        Route::post('kyc/submit','kycSubmit')->name('kyc.submit');
        Route::get('google/2fa','showGoogle2FAForm')->name('google.2fa');
        Route::post('google/2fa/submit','google2FASubmit')->name('google.2fa.submit');


    });
});
// //merchants
Route::prefix('merchant')->name('merchant.')->group(function(){
    Route::get('/',function(){
        return redirect()->route('merchant.login');
    });
    Route::get('login',[AuthLoginController::class,"showLoginForm"])->name('login');
    Route::post('login',[AuthLoginController::class,"login"])->name('login.submit');

    //register
    Route::get('register',[RegisterController::class,"showRegistrationForm"])->name('register')->middleware(['merchant.registration.permission']);
    // for sms 
    Route::post('register',[RegisterController::class,"register"])->name('register.submit')->middleware(['merchant.registration.permission']);
    Route::post('send/verify-code',[RegisterController::class,"sendVerifyCode"])->name('send.code')->middleware(['merchant.registration.permission']);
    Route::get('sms/verify/{token}',[MerchantAuthorizationController::class,"showSmsFormBeforRegister"])->name('sms.verify')->middleware(['merchant.registration.permission']);
    Route::post('verify/code/{token}',[RegisterController::class,"verifyCode"])->name('verify.code')->middleware(['merchant.registration.permission']);
    Route::get('resend/code',[RegisterController::class,"resendCode"])->name('resend.code')->middleware(['merchant.registration.permission']);
    Route::get('sms/otp/send/{token}',[MerchantAuthorizationController::class,"smsOtpSend"])->name('sms.otp.send')->middleware(['merchant.registration.permission']);
    Route::post('sms/otp/verify/code/{token}',[RegisterController::class,"smsOtpVerifyCode"])->name('sms.otp.verify.code')->middleware(['merchant.registration.permission']);
    Route::get('sms/otp/resend/code',[RegisterController::class,"smsOtpResendCode"])->name('sms.otp.resend.code')->middleware(['merchant.registration.permission']);


    // for email
    Route::get('email/verify/{token}',[MerchantAuthorizationController::class,"showMailFormBeforRegister"])->name('email.verify')->middleware(['merchant.registration.permission']);
    Route::get('email/send/otp/code/{token}',[MerchantAuthorizationController::class,"showEmailSendOtp"])->name('email.send.otp.code')->middleware(['merchant.registration.permission']);
    Route::post('email/verify/code/{token}',[RegisterController::class,"EmailVerifyCode"])->name('email.verify.code')->middleware(['merchant.registration.permission']);
    Route::get('email/resend/code',[RegisterController::class,"emailResendCode"])->name('email.resend.code')->middleware(['merchant.registration.permission']);
    Route::post('email/otp/verify/code/{token}',[RegisterController::class,"EmailOtpVerifyCode"])->name('email.otp.verify.code')->middleware(['merchant.registration.permission']);
    Route::get('email/otp/resend/code',[RegisterController::class,"emailOtpResendCode"])->name('email.otp.resend.code')->middleware(['merchant.registration.permission']);
    Route::get('register/kyc',[RegisterController::class,"registerKyc"])->name('register.kyc')->middleware(['merchant.registration.permission']);

     // recovery password by email
     Route::controller(AuthForgotPasswordController::class)->prefix("password")->name("password.")->group(function(){
        Route::get('forgot','showForgotForm')->name('forgot');
        Route::post('forgot/send/code','sendCode')->name('forgot.send.code');
        Route::get('forgot/code/verify/form/{token}','showVerifyForm')->name('forgot.code.verify.form');
        Route::post('forgot/verify/{token}','verifyCode')->name('forgot.verify.code');
        Route::get('forgot/resend/code/{token}','resendCode')->name('forgot.resend.code');
        Route::get('forgot/reset/form/{token}','showResetForm')->name('forgot.reset.form');
        Route::post('forgot/reset/{token}','resetPassword')->name('reset');

    });

    Route::controller(MerchantAuthorizationController::class)->prefix("authorize")->name('authorize.')->middleware("auth:merchant")->group(function(){
        Route::get('mail/{token}','showMailFrom')->name('mail');
        Route::get('sms/{token}','showSmsFrom')->name('sms');
        Route::post('mail/verify/{token}','mailVerify')->name('mail.verify');
        Route::post('sms/verify/{token}','smsVerify')->name('sms.verify');
        Route::get('resend/code','resendCode')->name('resend.code');
        Route::get('sms/resend/code','smsResendCode')->name('sms.resend.code');
        Route::get('kyc','showKycFrom')->name('kyc');
        Route::post('kyc/submit','kycSubmit')->name('kyc.submit');
        Route::get('google/2fa','showGoogle2FAForm')->name('google.2fa');
        Route::post('google/2fa/submit','google2FASubmit')->name('google.2fa.submit');

    });
});
// //agents
Route::prefix('agent')->name('agent.')->group(function(){
    Route::get('/',function(){
        return redirect()->route('agent.login');
    });
    Route::get('login',[AgentAuthLoginController::class,"showLoginForm"])->name('login');
    Route::post('login',[AgentAuthLoginController::class,"login"])->name('login.submit');

    //register AuthRegisterController AgentAuthorizationController  agent.registration.permission
    // for sms 
    Route::get('register',[AuthRegisterController::class,"showRegistrationForm"])->name('register')->middleware(['agent.registration.permission']);
    Route::post('register',[AuthRegisterController::class,"register"])->name('register.submit')->middleware(['agent.registration.permission']);
    Route::post('send/verify-code',[AuthRegisterController::class,"sendVerifyCode"])->name('send.code')->middleware(['agent.registration.permission']);
    Route::get('sms/verify/{token}',[AgentAuthorizationController::class,"showSmsFormBeforRegister"])->name('sms.verify')->middleware(['agent.registration.permission']);
    Route::post('verify/code/{token}',[AuthRegisterController::class,"verifyCode"])->name('verify.code')->middleware(['agent.registration.permission']);
    Route::get('resend/code',[AuthRegisterController::class,"resendCode"])->name('resend.code')->middleware(['agent.registration.permission']);
    Route::get('sms/otp/send/{token}',[AgentAuthorizationController::class,"smsOtpSend"])->name('sms.otp.send')->middleware(['agent.registration.permission']);
    Route::post('sms/otp/verify/code/{token}',[AuthRegisterController::class,"smsOtpVerifyCode"])->name('sms.otp.verify.code')->middleware(['agent.registration.permission']);
    Route::get('sms/otp/resend/code',[AuthRegisterController::class,"smsOtpResendCode"])->name('sms.otp.resend.code')->middleware(['agent.registration.permission']);


    // for email
    Route::get('email/verify/{token}',[AgentAuthorizationController::class,"showMailFormBeforRegister"])->name('email.verify')->middleware(['agent.registration.permission']);
    Route::get('email/send/otp/code/{token}',[AgentAuthorizationController::class,"showEmailSendOtp"])->name('email.send.otp.code')->middleware(['agent.registration.permission']);
    Route::post('email/verify/code/{token}',[AuthRegisterController::class,"EmailVerifyCode"])->name('email.verify.code')->middleware(['agent.registration.permission']);
    Route::get('email/resend/code',[AuthRegisterController::class,"emailResendCode"])->name('email.resend.code')->middleware(['agent.registration.permission']);
    Route::post('email/otp/verify/code/{token}',[AuthRegisterController::class,"EmailOtpVerifyCode"])->name('email.otp.verify.code')->middleware(['agent.registration.permission']);
    Route::get('email/otp/resend/code',[AuthRegisterController::class,"emailOtpResendCode"])->name('email.otp.resend.code')->middleware(['agent.registration.permission']);
    Route::get('register/kyc',[AuthRegisterController::class,"registerKyc"])->name('register.kyc')->middleware(['agent.registration.permission']);
    
    // recovery password by email
    Route::controller(AgentAuthForgotPasswordController::class)->prefix("password")->name("password.")->group(function(){
        Route::get('forgot','showForgotForm')->name('forgot');
        Route::post('forgot/send/code','sendCode')->name('forgot.send.code');
        Route::get('forgot/code/verify/form/{token}','showVerifyForm')->name('forgot.code.verify.form');
        Route::post('forgot/verify/{token}','verifyCode')->name('forgot.verify.code');
        Route::get('forgot/resend/code/{token}','resendCode')->name('forgot.resend.code');
        Route::get('forgot/reset/form/{token}','showResetForm')->name('forgot.reset.form');
        Route::post('forgot/reset/{token}','resetPassword')->name('reset');

    });

    Route::controller(AgentAuthorizationController::class)->prefix("authorize")->name('authorize.')->middleware("auth:agent")->group(function(){
        Route::get('mail/{token}','showMailFrom')->name('mail');
        Route::get('sms/{token}','showSmsFrom')->name('sms');
        Route::post('mail/verify/{token}','mailVerify')->name('mail.verify');
        Route::get('resend/code','resendCode')->name('resend.code');
        Route::post('sms/verify/{token}','smsVerify')->name('sms.verify');
        Route::get('sms/resend/code','smsResendCode')->name('sms.resend.code');
        Route::get('kyc','showKycFrom')->name('kyc');
        Route::post('kyc/submit','kycSubmit')->name('kyc.submit');
        Route::get('google/2fa','showGoogle2FAForm')->name('google.2fa');
        Route::post('google/2fa/submit','google2FASubmit')->name('google.2fa.submit');

    });
});
