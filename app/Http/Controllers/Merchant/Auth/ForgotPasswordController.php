<?php

namespace App\Http\Controllers\Merchant\Auth;

use App\Constants\GlobalConst;
use App\Http\Controllers\Controller;
use App\Models\Merchants\Merchant;
use App\Models\Merchants\MerchantPasswordReset;
use App\Models\User;
use App\Models\UserPasswordReset;
use App\Notifications\Merchant\Auth\PasswordResetEmail;
use App\Providers\Admin\BasicSettingsProvider;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class ForgotPasswordController extends Controller
{
    protected $basic_settings;
    public function __construct()
    {
        $this->basic_settings = BasicSettingsProvider::get();
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function showForgotForm()
    {
        $page_title = __("Forgot Password");
        return view('merchant.auth.forgot-password.forgot',compact('page_title'));
    }
    /**
     * Send Verification code to user email/phone.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function sendCode(Request $request)
    {
        $request->validate([
            'credentials'   => "required|string|max:100",
        ]);
        
        $user = Merchant::orWhere('full_mobile',$request->credentials)
                ->orWhere('email',$request->credentials)->first();
        if(!$user) {
            throw ValidationException::withMessages([
                'credentials'       => __("Merchant doesn't exists."),
            ]);
        }
        if($request->credentials == $user->full_mobile) {
            $type           = GlobalConst::PHONE;
        }else{
            $type           = GlobalConst::EMAIL;
        }
        if($type == GlobalConst::PHONE) {
            $token = generate_unique_string("merchant_password_resets","token",80);
            $code = generate_random_code();
    
            try{
                MerchantPasswordReset::where("merchant_id",$user->id)->delete();
                $password_reset = MerchantPasswordReset::create([
                    'merchant_id'   => $user->id,
                    'phone'         => $request->credentials,  
                    'token'         => $token,
                    'code'          => $code,
                ]);
                $message = __("Your forgot password code is :code",['code' => $code]);
               sendApiSMS($message,$request->credentials);
            }catch(Exception $e) {
                return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
            }
            return redirect()->route('merchant.password.forgot.code.verify.form',$token)->with(['success' => [__('Verification code sended to your phone number.')]]);
        }else{
            $token = generate_unique_string("merchant_password_resets","token",80);
            $code = generate_random_code();
    
            try{
                MerchantPasswordReset::where("merchant_id",$user->id)->delete();
                $password_reset = MerchantPasswordReset::create([
                    'merchant_id'   => $user->id,
                    'email'         => $request->credentials,  
                    'token'         => $token,
                    'code'          => $code,
                ]);
                if($this->basic_settings->merchant_email_notification == true && $this->basic_settings->merchant_email_verification == true){
                    $user->notify(new PasswordResetEmail($user,$password_reset));
                }
            }catch(Exception $e) {
                return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
            }
            return redirect()->route('merchant.password.forgot.code.verify.form',$token)->with(['success' => [__('Verification code sended to your email address.')]]);
        }
        
    }
    /**
     * Method for show verify code form
     */
    public function showVerifyForm($token) {
        $page_title = __("Verify Merchant");
        $password_reset = MerchantPasswordReset::where("token",$token)->first();
        if(!$password_reset) return redirect()->route('merchant.password.forgot')->with(['error' => [__('Password Reset Token Expired')]]);
        $resend_time = 0;
        if(Carbon::now() <= $password_reset->created_at->addMinutes(GlobalConst::USER_PASS_RESEND_TIME_MINUTE)) {
            $resend_time = Carbon::now()->diffInSeconds($password_reset->created_at->addMinutes(GlobalConst::USER_PASS_RESEND_TIME_MINUTE));
        }
        $user_phone = $password_reset->phone ?? $password_reset->email;
        return view('merchant.auth.forgot-password.verify',compact('page_title','token','user_phone','resend_time'));
    }
    /**
     * OTP Verification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function verifyCode(Request $request,$token)
    {
        $request->merge(['token' => $token]);
        $validated = Validator::make($request->all(),[
            'token'     => "required|string|exists:merchant_password_resets,token",
            'code'      => "required|array",
            'code.*'    => "required|numeric",
        ])->validate();
        $code = $request->code;
        $code = implode("",$code);

        $basic_settings = BasicSettingsProvider::get();
        $otp_exp_seconds = $basic_settings->merchant_otp_exp_seconds ?? 0;

        $password_reset = MerchantPasswordReset::where("token",$token)->first();
        if(!$password_reset){
            return back()->with(['error' => [__('Verification code already used')]]);
        }
        if( $password_reset){
            if(Carbon::now() >= $password_reset->created_at->addSeconds($otp_exp_seconds)) {
                foreach(UserPasswordReset::get() as $item) {
                    if(Carbon::now() >= $item->created_at->addSeconds($otp_exp_seconds)) {
                        $item->delete();
                    }
                }
                return redirect()->route('merchant.password.forgot')->with(['error' => [__('Session expired. Please try again.')]]);
            }
        }
        if($password_reset->code != $code) {
            throw ValidationException::withMessages([
                'code'      => __("Verification Otp is Invalid"),
            ]);
        }
        return redirect()->route('merchant.password.forgot.reset.form',$token);
    }
    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function resendCode($token)
    {
        $password_reset = MerchantPasswordReset::where('token',$token)->first();
        if(!$password_reset) return back()->with(['error' => [__('Password Reset Token Expired')]]);
        if(Carbon::now() <= $password_reset->created_at->addMinutes(GlobalConst::USER_PASS_RESEND_TIME_MINUTE)) {
            throw ValidationException::withMessages([
                'code'      => __('You can resend the verification code after').' '.Carbon::now()->diffInSeconds($password_reset->created_at->addMinutes(GlobalConst::USER_PASS_RESEND_TIME_MINUTE)). ' '. __('seconds'),
            ]);
        }

        DB::beginTransaction();
        $code = generate_random_code();
        try{
            $update_data = [
                'code'          => $code,
                'created_at'    => now(),
                'token'         => $token,
            ];
            DB::table('merchant_password_resets')->where('token',$token)->update($update_data);
            $message = __("Your forgot password code is :code",['code' => $code]);
            sendApiSMS($message,$password_reset->phone);
            DB::commit();
        }catch(Exception $e) {
            DB::rollback();
            return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
        }
        return redirect()->route('merchant.password.forgot.code.verify.form',$token)->with(['success' => [__('Verification code resend success')]]);

    }
    public function showResetForm($token) {
        $page_title = setPageTitle(__('Reset Password Page'));
        return view('merchant.auth.forgot-password.reset',compact('page_title','token'));
    }

    public function resetPassword(Request $request,$token) {
        $basic_settings = BasicSettingsProvider::get();
        $passowrd_rule = "required|string|min:6|confirmed";
        if($basic_settings->merchant_secure_password) {
            $passowrd_rule = ["required",Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised(),"confirmed"];
        }

        $request->merge(['token' => $token]);
        $validated = Validator::make($request->all(),[
            'token'         => "required|string|exists:merchant_password_resets,token",
            'password'      => $passowrd_rule,
        ])->validate();

        $password_reset = MerchantPasswordReset::where("token",$token)->first();
        if(!$password_reset) {
            throw ValidationException::withMessages([
                'password'      => __('Password Reset Token Expired'),
            ]);
        }
        try{
            $password_reset->merchant->update([
                'password'      => Hash::make($validated['password']),
            ]);
            $password_reset->delete();
        }catch(Exception $e) {
            return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
        }

        return redirect()->route('merchant.login')->with(['success' => [__('Password reset success. Please login with new password.')]]);
    }

}