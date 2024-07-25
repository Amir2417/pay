<?php

namespace App\Http\Controllers\Api\Merchant;

use Exception;
use App\Models\User;
use App\Models\Agent;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Models\Admin\SetupKyc;
use Illuminate\Support\Carbon;
use App\Http\Helpers\Api\Helpers;
use App\Models\Merchants\Merchant;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Traits\ControlDynamicInputFields;
use Illuminate\Support\Facades\Validator;;
use Illuminate\Support\Facades\Notification;
use App\Providers\Admin\BasicSettingsProvider;
use App\Models\Merchants\MerchantAuthorization;
use App\Notifications\User\Auth\SendVerifyCode;
use App\Notifications\Merchant\Auth\SendAuthorizationCode as AuthSendAuthorizationCode;

class AuthorizationController extends Controller
{
    use ControlDynamicInputFields;

    protected $basic_settings;

    public function __construct()
    {
        $this->basic_settings = BasicSettingsProvider::get();
    }
    public function sendMailCode()
    {
        $user = auth()->user();
        $resend = MerchantAuthorization::where("merchant_id",$user->id)->first();
        if( $resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                $error = ['error'=>[ __("You can resend the verification code after").' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)). ' '.__('seconds')]];
                return Helpers::error($error);
            }
        }

        $data = [
            'merchant_id'       =>  $user->id,
            'code'          => generate_random_code(),
            'token'         => generate_unique_string("merchant_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            if($resend) {
                MerchantAuthorization::where("merchant_id", $user->id)->delete();
            }
            DB::table("merchant_authorizations")->insert($data);
            $user->notify(new AuthSendAuthorizationCode((object) $data));
            DB::commit();
            $message =  ['success'=>[__('Verification code sended to your email address.')]];
            return Helpers::onlysuccess($message);
        }catch(Exception $e) {
            DB::rollBack();
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
    }
    public function sendSMSCode()
    {
        $user = auth()->user();
        $resend = MerchantAuthorization::where("merchant_id",$user->id)->first();
        if( $resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                $error = ['error'=>[ __("You can resend the verification code after").' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)). ' '.__('seconds')]];
                return Helpers::error($error);
            }
        }
        $code               = generate_random_code();
        $data = [
            'merchant_id'   => $user->id,
            'code'          => $code,
            'token'         => generate_unique_string("merchant_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            if($resend) {
                MerchantAuthorization::where("merchant_id", $user->id)->delete();
            }
            DB::table("merchant_authorizations")->insert($data);
            $message = __("Your code is :code",['code' => $code]);
           sendApiSMS($message,$user->full_mobile);
            DB::commit();
            $message =  ['success'=>[__('Verification code sended to your phone number.')]];
            return Helpers::onlysuccess($message);
        }catch(Exception $e) {
            DB::rollBack();
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
    }
    public function mailVerify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|numeric',
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $user = auth()->user();
        $code = $request->code;
        $otp_exp_sec = BasicSettingsProvider::get()->merchant_otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = MerchantAuthorization::where("merchant_id",$user->id)->first();

        if(!$auth_column){
             $error = ['error'=>[__('Verification code already used')]];
            return Helpers::error($error);
        }
        if($auth_column->code !=  $code){
             $error = ['error'=>[__('Verification is invalid')]];
            return Helpers::error($error);
        }
        if($auth_column->created_at->addSeconds($otp_exp_sec) < now()) {
            $error = ['error'=>[__('Time expired. Please try again')]];
            return Helpers::error($error);
        }
        try{
            $auth_column->merchant->update([
                'email_verified'    => true,
            ]);
            $auth_column->delete();
        }catch(Exception $e) {
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
        $message =  ['success'=>[__('Account successfully verified')]];
        return Helpers::onlysuccess($message);
    }
    /**
     * Method for send sms code
     */
    public function smsVerify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|numeric',
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $user = auth()->user();
        $code = $request->code;
        $otp_exp_sec = BasicSettingsProvider::get()->merchant_otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = MerchantAuthorization::where("merchant_id",$user->id)->first();

        if(!$auth_column){
             $error = ['error'=>[__('Verification code already used')]];
            return Helpers::error($error);
        }
        if($auth_column->code !=  $code){
             $error = ['error'=>[__('Verification is invalid')]];
            return Helpers::error($error);
        }
        if($auth_column->created_at->addSeconds($otp_exp_sec) < now()) {
            $error = ['error'=>[__('Time expired. Please try again')]];
            return Helpers::error($error);
        }
        try{
            $auth_column->merchant->update([
                'sms_verified'    => true,
            ]);
            $auth_column->delete();
        }catch(Exception $e) {
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
        $message =  ['success'=>[__('Account successfully verified')]];
        return Helpers::onlysuccess($message);
    }
    public function showKycFrom(){
        $user = auth()->user();
        $kyc_status = $user->kyc_verified;
        $user_kyc = SetupKyc::merchantKyc()->first();
        $status_info = "1==verified, 2==pending, 0==unverified; 3=rejected";
        $kyc_data = $user_kyc->fields;
        $kyc_fields = [];
        if($kyc_data) {
            $kyc_fields = array_reverse($kyc_data);
        }
        $data =[
            'status_info' => $status_info,
            'kyc_status' => $kyc_status,
            'merchantKyc' => $kyc_fields
        ];
        $message = ['success'=>[ __("KYC Verification")]];
        return Helpers::success($data,$message);

    }
    public function kycSubmit(Request $request){
        $user = auth()->user();
        if($user->kyc_verified == GlobalConst::VERIFIED){
            $message = ['error'=>[__('You are already KYC Verified User')]];
            return Helpers::error($message);

        }
        $user_kyc_fields = SetupKyc::merchantKyc()->first()->fields ?? [];
        $validation_rules = $this->generateValidationRules($user_kyc_fields);
        $validated = Validator::make($request->all(), $validation_rules);

        if ($validated->fails()) {
            $message =  ['error' => $validated->errors()->all()];
            return Helpers::error($message);
        }
        $validated = $validated->validate();
        $get_values = $this->placeValueWithFields($user_kyc_fields, $validated);
        $create = [
            'merchant_id'       => auth()->user()->id,
            'data'          => json_encode($get_values),
            'created_at'    => now(),
        ];

        DB::beginTransaction();
        try{
            DB::table('merchant_kyc_data')->updateOrInsert(["merchant_id" => $user->id],$create);
            $user->update([
                'kyc_verified'  => GlobalConst::PENDING,
            ]);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            $user->update([
                'kyc_verified'  => GlobalConst::DEFAULT,
            ]);
            // $this->generatedFieldsFilesDelete($get_values);
            $message = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($message);
        }
        $message = ['success'=>[__('KYC information successfully submitted')]];
        return Helpers::onlysuccess($message);

    }

     //========================before registration======================================
    public function checkExist(Request $request){
        $validator = Validator::make($request->all(), [
            'phone'     => 'required',
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        
        $user               = Merchant::where('full_mobile',$request->phone)->first();
        $exists_user        = User::where('full_mobile',$request->phone)->first();
        $exists_agent       = Agent::where('full_mobile',$request->phone)->first();
        if($user){
            $error = ['error'=>[__('Merchant already exist, please select another phone number')]];
            return Helpers::validation($error);
        }
        if($exists_agent){
            $error = ['error'=>[__('Sorry! Mobile number already exist in agent.')]];
            return Helpers::validation($error);
        }
        if($exists_user){
            $error = ['error'=>[__('Sorry! Mobile number already exist in user.')]];
            return Helpers::validation($error);
        }
        $message = ['success'=>[__('Now,You can register')]];
        return Helpers::onlysuccess($message);

    }
    public function checkEmailExist(Request $request){
        $validator = Validator::make($request->all(), [
            'email'     => 'required',
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        
        $exists_merchant    = Merchant::where('email',$request->email)->first();
        if($exists_merchant){
            $error = ['error'=>[__("Merchant already exist, please select another email address.")]];
            return Helpers::validation($error);
        }
        $message = ['success'=>[__('Now,You can register')]];
        return Helpers::onlysuccess($message);

    }
    /**
     * Method for check merchant username
     */
    public function checkUsername(Request $request){
        $validator = Validator::make($request->all(), [
            'username'     => 'required',
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated  = $validator->validate();
        $user_name          = User::where('username',$validated['username'])->first();
        $agent_name         = Agent::where('username',$validated['username'])->first();
        $merchant_name      = Merchant::where('username',$validated['username'])->first();

        if($user_name || $agent_name || $merchant_name){
            $error = ['error'=>[__('Username already exist, please select another username address.')]];
            return Helpers::validation($error);
        }
        $message = ['success'=>[__('Username is valid.')]];
        return Helpers::onlysuccess($message);
    }
    /**
     * Method for send email otp
     * @param Illuminate\Http\Request $request
     */
    public function sendEmailOtp(Request $request){
        $basic_settings = $this->basic_settings;
        
        $validator = Validator::make($request->all(), [
            'email'         => 'required|email'
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated = $validator->validate();

        $field_name = "username";
        if(check_email($validated['email'])) {
            $field_name = "email";
        }
        $exist = Merchant::where($field_name,$validated['email'])->active()->first();
        if( $exist){
            $message = ['error'=>[__("Merchant already exist, please select another email address")]];
            return Helpers::error($message);
        }

        $code = generate_random_code();
        $data = [
            'merchant_id'       =>  0,
            'email'         => $validated['email'],
            'code'          => $code,
            'token'         => generate_unique_string("merchant_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            $oldToken = MerchantAuthorization::where("email",$validated['email'])->get();
            if($oldToken){
                foreach($oldToken as $token){
                    $token->delete();
                }
            }
            DB::table("merchant_authorizations")->insert($data);
            if($basic_settings->merchant_email_notification == true && $basic_settings->merchant_email_verification == true){
                Notification::route("mail",$validated['email'])->notify(new SendVerifyCode($validated['email'], $code));
            }
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            $message = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($message);
        };
        $message = ['success'=>[__('Verification code sended to your email address.')]];
        return Helpers::onlysuccess($message);
    }
    /**
     * Method for send sms otp
     * @param Illuminate\Http\Request $request
     */
    public function sendSMSOtp(Request $request){
        $basic_settings = $this->basic_settings;
        if($basic_settings->merchant_agree_policy){
            $agree = 'required';
        }else{
            $agree = '';
        }
        if( $request->agree != 1){
            return Helpers::error(['error' => [__('Terms Of Use & Privacy Policy Field Is Required!')]]);
        }
        $validator = Validator::make($request->all(), [
            'phone'         => 'required',
            'agree'         =>  $agree,
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated = $validator->validate();

        
        $exist = Merchant::where('full_mobile',$validated['phone'])->active()->first();
        if( $exist){
            $message = ['error'=>[__("Merchant already exist, please select another phone number")]];
            return Helpers::error($message);
        }

        $code = generate_random_code();
        $data = [
            'merchant_id'   => 0,
            'phone'         => $validated['phone'],
            'code'          => $code,
            'token'         => generate_unique_string("merchant_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            $oldToken = MerchantAuthorization::where("phone",$validated['phone'])->get();
            if($oldToken){
                foreach($oldToken as $token){
                    $token->delete();
                }
            }
            DB::table("merchant_authorizations")->insert($data);
            if($basic_settings->merchant_sms_notification == true && $basic_settings->merchant_sms_verification == true){
                $message = __("Your code is :code",['code' => $code]);
               sendApiSMS($message,$request->phone);
            }
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            $message = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($message);
        };
        $message = ['success'=>[__('Verification code sended to your phone number.')]];
        return Helpers::onlysuccess($message);
    }
    /**
     * Method for verify otp
     */
    public function verifyEmailOtp(Request $request){
        $validator = Validator::make($request->all(), [
            'email'     => "required|email",
            'code'    => "required|max:6",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $code = $request->code;
        $otp_exp_sec = BasicSettingsProvider::get()->merchant_otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = MerchantAuthorization::where("email",$request->email)->first();
        if(!$auth_column){
            $message = ['error'=>[__('Invalied request')]];
            return Helpers::error($message);
        }
        if( $auth_column->code != $code){
            $message = ['error'=>[__('The verification code does not match')]];
            return Helpers::error($message);
        }
        if($auth_column->created_at->addSeconds($otp_exp_sec) < now()) {
            $auth_column->delete();
            $message = ['error'=>[__('Verification code is expired')]];
            return Helpers::error($message);
        }
        try{
            $auth_column->delete();
        }catch(Exception $e) {
            $message = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($message);
        }
        $message = ['success'=>[__('Otp successfully verified')]];
        return Helpers::onlysuccess($message);
    }
    /**
     * Method for verify otp
     */
    public function verifySmsOtp(Request $request){
        $validator = Validator::make($request->all(), [
            'phone'     => "required",
            'code'    => "required|max:6",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $code = $request->code;
        $otp_exp_sec = BasicSettingsProvider::get()->merchant_otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = MerchantAuthorization::where("phone",$request->phone)->first();
        if(!$auth_column){
            $message = ['error'=>[__('Invalied request')]];
            return Helpers::error($message);
        }
        if( $auth_column->code != $code){
            $message = ['error'=>[__('The verification code does not match')]];
            return Helpers::error($message);
        }
        if($auth_column->created_at->addSeconds($otp_exp_sec) < now()) {
            $auth_column->delete();
            $message = ['error'=>[__('Verification code is expired')]];
            return Helpers::error($message);
        }
        try{
            $auth_column->delete();
        }catch(Exception $e) {
            $message = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($message);
        }
        $message = ['success'=>[__('Otp successfully verified')]];
        return Helpers::onlysuccess($message);
    }
    /**
     * Method for email otp resend
     */
    public function resendEmailOtp(Request $request){
        $validator = Validator::make($request->all(), [
            'email'     => "required|email",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $resend = MerchantAuthorization::where("email",$request->email)->first();
        if($resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                $message = ['error'=>[__("You can resend the verification code after").' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)). ' '.__('seconds')]];
                return Helpers::error($message);
            }
        }
        $code = generate_random_code();
        $data = [
            'merchant_id'       =>  0,
            'email'         => $request->email,
            'code'          => $code,
            'token'         => generate_unique_string("merchant_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            $oldToken = MerchantAuthorization::where("email",$request->email)->get();
            if($oldToken){
                foreach($oldToken as $token){
                    $token->delete();
                }
            }
            DB::table("merchant_authorizations")->insert($data);
            Notification::route("mail",$request->email)->notify(new SendVerifyCode($request->email, $code));
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            $message = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($message);
        }
        $message = ['success'=>[__('Verification code resend success')]];
        return Helpers::onlysuccess($message);
    }
    /**
     * Method for email otp resend
     */
    public function resendSMSOtp(Request $request){
        $validator = Validator::make($request->all(), [
            'phone'     => "required",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $resend = MerchantAuthorization::where("phone",$request->phone)->first();
        if($resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                $message = ['error'=>[__("You can resend the verification code after").' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)). ' '.__('seconds')]];
                return Helpers::error($message);
            }
        }
        $code = generate_random_code();
        $data = [
            'merchant_id'   =>  0,
            'phone'         => $request->phone,
            'code'          => $code,
            'token'         => generate_unique_string("merchant_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            $oldToken = MerchantAuthorization::where("phone",$request->phone)->get();
            if($oldToken){
                foreach($oldToken as $token){
                    $token->delete();
                }
            }
            DB::table("merchant_authorizations")->insert($data);
            $message = __("Your code is :code",['code' => $code]);
           sendApiSMS($message,$request->phone);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            $message = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($message);
        }
        $message = ['success'=>[__('Verification code resend success')]];
        return Helpers::onlysuccess($message);
    }
}
