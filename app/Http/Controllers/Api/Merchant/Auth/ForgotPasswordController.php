<?php

namespace App\Http\Controllers\Api\Merchant\Auth;

use Exception;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use Illuminate\Support\Carbon;
use App\Http\Helpers\Api\Helpers;
use App\Models\Merchants\Merchant;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use App\Providers\Admin\BasicSettingsProvider;
use App\Models\Merchants\MerchantPasswordReset;
use App\Notifications\Merchant\Auth\PasswordResetEmail;
use App\Notifications\Merchant\Auth\PasswordResetEmail as AuthPasswordResetEmail;

class ForgotPasswordController extends Controller
{
    protected $basic_settings;
    public function __construct()
    {
        $this->basic_settings = BasicSettingsProvider::get();
    }
    public function sendCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone'   => "required|max:100",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $user = Merchant::orWhere('full_mobile',$request->phone)
                ->orWhere('email',$request->phone)->first();
        if(!$user) {
            $error = ['error'=>[__("Merchant doesn't exists.")]];
            return Helpers::error($error);
        }
        if($request->phone == $user->full_mobile) {
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
                    'phone'         => $request->phone,
                    'token'         => $token,
                    'code'          => $code,
                ]);
                $message = __("Your forgot password code is :code",['code' => $code]);
                sendApiSMS($message,$request->phone);
            }catch(Exception $e) {
                    $error = ['error'=>[__('Something went wrong! Please try again.')]];
                return Helpers::error($error);
            }

            $message =  ['success'=>[__('Verification code sended to your phone number.')]];
            return Helpers::onlysuccess($message);
        }else{
            $token = generate_unique_string("merchant_password_resets","token",80);
            $code = generate_random_code();

            try{
                MerchantPasswordReset::where("merchant_id",$user->id)->delete();
                $password_reset = MerchantPasswordReset::create([
                    'merchant_id'   => $user->id,
                    'email'         => $request->phone,
                    'token'         => $token,
                    'code'          => $code,
                ]);
                if($this->basic_settings->merchant_email_notification == true && $this->basic_settings->merchant_email_verification == true){
                    $user->notify(new PasswordResetEmail($user,$password_reset));
                }
            }catch(Exception $e) {
                    $error = ['error'=>[__('Something went wrong! Please try again.')]];
                return Helpers::error($error);
            }

            $message =  ['success'=>[__('Verification code sended to your email address.')]];
            return Helpers::onlysuccess($message);
        }
        
    }

    public function verifyCode(Request $request)
    {
        $validator      = Validator::make($request->all(), [
            'phone'     => 'required',
            'code'      => 'required|numeric',
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $code = $request->code;
        $basic_settings = BasicSettingsProvider::get();
        $otp_exp_seconds = $basic_settings->merchant_otp_exp_seconds ?? 0;
        $password_reset = MerchantPasswordReset::where("code", $code)->orWhere('phone',$request->phone)->orWhere('email',$request->phone)->first();
        if(!$password_reset) {
            $error = ['error'=>[__('Verification Otp is Invalid')]];
            return Helpers::error($error);
        }
        if(Carbon::now() >= $password_reset->created_at->addSeconds($otp_exp_seconds)) {
            foreach(MerchantPasswordReset::get() as $item) {
                if(Carbon::now() >= $item->created_at->addSeconds($otp_exp_seconds)) {
                    $item->delete();
                }
            }
            $error = ['error'=>[__('Time expired. Please try again')]];
            return Helpers::error($error);
        }

        $message =  ['success'=>[__('Your Verification is successful, Now you can recover your password')]];
        return Helpers::onlysuccess($message);
    }
    public function resetPassword(Request $request) {
        $basic_settings = BasicSettingsProvider::get();
        $passowrd_rule = "required|string|min:6|confirmed";
        if($basic_settings->merchant_secure_password) {
            $passowrd_rule = ["required",Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised(),"confirmed"];
        }

        $validator          = Validator::make($request->all(), [
            'code'          => 'required|numeric',
            'phone'         => 'required',
            'password'      => $passowrd_rule,
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $code = $request->code;
        $password_reset = MerchantPasswordReset::where("code",$code)->where('phone',$request->phone)->first();
        if(!$password_reset) {
            $error = ['error'=>[__('Invalid request')]];
            return Helpers::error($error);
        }
        try{
            $password_reset->merchant->update([
                'password'      => Hash::make($request->password),
            ]);
            $password_reset->delete();
        }catch(Exception $e) {
                $error = ['error'=>[__('Something went wrong! Please try again.')]];
            return Helpers::error($error);
        }
        $message =  ['success'=>[__('Password reset success. Please login with new password.')]];
        return Helpers::onlysuccess($message);
    }

}
