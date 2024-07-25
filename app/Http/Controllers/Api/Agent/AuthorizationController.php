<?php

namespace App\Http\Controllers\Api\Agent;

use Exception;
use App\Models\User;
use App\Models\Agent;
use App\Models\AgentQrCode;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Models\Admin\SetupKyc;
use Illuminate\Support\Carbon;
use App\Http\Helpers\Api\Helpers;
use App\Models\AgentAuthorization;
use App\Models\Merchants\Merchant;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use App\Traits\Agent\RegisteredUsers;
use App\Traits\ControlDynamicInputFields;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;;
use Illuminate\Support\Facades\Notification;
use App\Providers\Admin\BasicSettingsProvider;
use App\Notifications\Agent\Auth\SendVerifyCode;
use App\Notifications\Agent\Auth\SendAuthorizationCode;

class AuthorizationController extends Controller
{
    use ControlDynamicInputFields,RegisteredUsers;

    protected $basic_settings;

    public function __construct()
    {
        $this->basic_settings = BasicSettingsProvider::get();
    }
    public function sendMailCode()
    {
        $user = auth()->user();
        $resend = AgentAuthorization::where("agent_id",$user->id)->first();
        if( $resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                $error = ['error'=>[ __("You can resend the verification code after").' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)). ' '.__('seconds')]];
                return Helpers::error($error);
            }
        }
        $data = [
            'agent_id'       =>  $user->id,
            'code'          => generate_random_code(),
            'token'         => generate_unique_string("agent_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            if($resend) {
                AgentAuthorization::where("agent_id", $user->id)->delete();
            }
            DB::table("agent_authorizations")->insert($data);
            $user->notify(new SendAuthorizationCode((object) $data));
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
        $resend = AgentAuthorization::where("agent_id",$user->id)->first();
        if( $resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                $error = ['error'=>[ __("You can resend the verification code after").' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)). ' '.__('seconds')]];
                return Helpers::error($error);
            }
        }
        $code = generate_random_code(); 
        $data = [
            'agent_id'      => $user->id,
            'code'          => $code,
            'token'         => generate_unique_string("agent_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            if($resend) {
                AgentAuthorization::where("agent_id", $user->id)->delete();
            }
            DB::table("agent_authorizations")->insert($data);
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
        $otp_exp_sec = BasicSettingsProvider::get()->agent_otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = AgentAuthorization::where("agent_id",$user->id)->first();

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
            $auth_column->agent->update([
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
        $otp_exp_sec = BasicSettingsProvider::get()->agent_otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = AgentAuthorization::where("agent_id",$user->id)->first();

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
            $auth_column->agent->update([
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
    public function verify2FACode(Request $request) {
        $validator = Validator::make($request->all(), [
            'otp' => 'required',
        ]);

        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }

        $code = $request->otp;
        $user = authGuardApi()['user'];

        if(!$user->two_factor_secret) {
            $error = ['error'=>[__('Your secret key is not stored properly. Please contact with system administrator')]];
            return Helpers::error($error);
        }

        if(google_2fa_verify_api($user->two_factor_secret,$code)) {
            $user->update([
                'two_factor_verified'   => true,
            ]);
            $message = ['success'=>[ __("Two factor verified successfully")]];
            return Helpers::onlySuccess($message);
        }
        $message = ['error'=>[ __('Failed to login. Please try again')]];
        return Helpers::error($message);
    }
    public function showKycFrom(){
        $user = auth()->user();
        $kyc_status = $user->kyc_verified;
        $user_kyc = SetupKyc::agentKyc()->first();
        $status_info = "1==verified, 2==pending, 0==unverified; 3=rejected";
        $kyc_data = $user_kyc->fields;
        $kyc_fields = [];
        if($kyc_data) {
            $kyc_fields = array_reverse($kyc_data);
        }
        $data =[
            'status_info' => $status_info,
            'kyc_status' => $kyc_status,
            'agentKyc' => $kyc_fields
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
        $user_kyc_fields = SetupKyc::agentKyc()->first()->fields ?? [];
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
       
        $user               = Agent::where('full_mobile',$request->phone)->first();
        $exists_merchant    = Merchant::where('full_mobile',$request->phone)->first();
        $exists_user        = User::where('full_mobile',$request->phone)->first();
        if($user){
            $error = ['error'=>[__('Agent already exist, please select another phone number')]];
            return Helpers::validation($error);
        }
        if($exists_user){
            $error = ['error'=>[__('Sorry! Mobile number already exist in user.')]];
            return Helpers::validation($error);
        }
        if($exists_merchant){
            $error = ['error'=>[__('Sorry! Mobile number already exist in merchant.')]];
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
       
        $user = Agent::where('email',$request->email)->first();
        if($user){
            $error = ['error'=>[__("Agent already exist, please select another email address..")]];
            return Helpers::validation($error);
        }
        $message = ['success'=>[__('Now,You can register')]];
        return Helpers::onlysuccess($message);

    }
    /**
     * Method for check username
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
     */
    public function sendEmailOtp(Request $request){
        $basic_settings = $this->basic_settings;
        
        $validator = Validator::make($request->all(), [
            'email'         => 'required|email',
            
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
        $exist = Agent::where($field_name,$validated['email'])->active()->first();
        if( $exist){
            $message = ['error'=>[__("Agent already exist, please select another email address")]];
            return Helpers::error($message);
        }

        $code = generate_random_code();
        $data = [
            'agent_id'       =>  0,
            'email'         => $validated['email'],
            'code'          => $code,
            'token'         => generate_unique_string("agent_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            $oldToken = AgentAuthorization::where("email",$validated['email'])->get();
            if($oldToken){
                foreach($oldToken as $token){
                    $token->delete();
                }
            }
            DB::table("agent_authorizations")->insert($data);
            if($basic_settings->agent_email_notification == true && $basic_settings->agent_email_verification == true){
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
     */
    public function sendSMSOtp(Request $request){
        $basic_settings = $this->basic_settings;
        if($basic_settings->agree_policy){
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

        
        $exist = Agent::where('full_mobile',$validated['phone'])->active()->first();
        if( $exist){
            $message = ['error'=>[__("Agent already exist, please select another phone number.")]];
            return Helpers::error($message);
        }

        $code = generate_random_code();
        $data = [
            'agent_id'      =>  0,
            'phone'         => $validated['phone'],
            'code'          => $code,
            'token'         => generate_unique_string("agent_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            $oldToken = AgentAuthorization::where("phone",$validated['phone'])->get();
            if($oldToken){
                foreach($oldToken as $token){
                    $token->delete();
                }
            }
            DB::table("agent_authorizations")->insert($data);
            if($basic_settings->agent_sms_notification == true && $basic_settings->agent_sms_verification == true){
                $message = __("Your authorization resend code is :code",['code' => $code]);
               sendApiSMS($message,$validated['phone']);  
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
    public function verifyOtp(Request $request){
        $validator = Validator::make($request->all(), [
            'email'     => "required|email",
            'code'    => "required|max:6",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $code = $request->code;
        $otp_exp_sec = BasicSettingsProvider::get()->agent_otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = AgentAuthorization::where("email",$request->email)->first();
        if(!$auth_column){
            $message = ['error'=>[__('Invalid request')]];
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
     * Method for verify email otp
     */
    public function verifyEmailOtp(Request $request){
        $basic_settings = $this->basic_settings;
        $passowrd_rule = "required|string|min:6|confirmed";
        if($basic_settings->agent_secure_password) {
            $passowrd_rule = ["required","confirmed",Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()];
        }
        if( $basic_settings->agent_agree_policy){
            $agree ='required';
        }else{
            $agree ='';
        }
        $validator = Validator::make($request->all(), [
            'email'         => "required|email",
            'code'          => "required|max:6",
            'firstname'     => 'required|string|max:60',
            'lastname'      => 'required|string|max:60',
            'store_name'    => 'required|string|max:100',
            'password'      => $passowrd_rule,
            'country'       => 'required|string|max:150',
            'city'          => 'required|string|max:150',
            'phone'         => 'nullable|string|max:20',
            'zip_code'      => 'required|string|max:8',
            'agree'         =>  $agree,
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        if($basic_settings->agent_kyc_verification == true){
            $user_kyc_fields = SetupKyc::agentKyc()->first()->fields ?? [];
            $validation_rules = $this->generateValidationRules($user_kyc_fields);
            $validated = Validator::make($request->all(), $validation_rules);

            if ($validated->fails()) {
                $message =  ['error' => $validated->errors()->all()];
                return Helpers::error($message);
            }
            $validated = $validated->validate();
            $get_values = $this->registerPlaceValueWithFields($user_kyc_fields, $validated);
        }
        $code = $request->code;
        $otp_exp_sec = BasicSettingsProvider::get()->agent_otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = AgentAuthorization::where("email",$request->email)->first();
        if(!$auth_column){
            $message = ['error'=>[__('Invalid request')]];
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

            $data                       = $request->all();
            $mobile                     = remove_speacial_char($data['phone']);
            $complete_phone             = $mobile;
            $userName                   = $data['username'];

            $check_user_name = User::where('username',$userName)->first();
            if($check_user_name){
                $error = ['error'=>[__('Username already exist')]];
                return Helpers::validation($error);
            }
            $check_user_name_agent = Agent::where('username',$userName)->first();
            if($check_user_name_agent){
                $error = ['error'=>[__('Username already exist')]];
                return Helpers::validation($error);
            }
            $check_user_name_merchant = Merchant::where('username',$userName)->first();
            if($check_user_name_merchant){
                $error = ['error'=>[__('Username already exist')]];
                return Helpers::validation($error);
            }

            //check email
            $check_user_email = User::where('email',$data['email'])->first();
            if($check_user_email){
                $error = ['error'=>[__('Email already exist')]];
                return Helpers::validation($error);
            }
            $check_email_agent = Agent::where('email',$data['email'])->first();
            if($check_email_agent){
                $error = ['error'=>[__('Email already exist')]];
                return Helpers::validation($error);
            }
            $check_email_merchant = Merchant::where('email',$data['email'])->first();
            if($check_email_merchant){
                $error = ['error'=>[__('Email already exist')]];
                return Helpers::validation($error);
            }
            //check sms
            $check_user_sms = User::where('full_mobile',$data['phone'])->first();
            if($check_user_sms){
                $error = ['error'=>[__('Phone already exist')]];
                return Helpers::validation($error);
            }
            $check_sms_agent = Agent::where('full_mobile',$data['phone'])->first();
            if($check_sms_agent){
                $error = ['error'=>[__('Phone already exist')]];
                return Helpers::validation($error);
            }
            $check_sms_merchant = Merchant::where('full_mobile',$data['phone'])->first();
            if($check_sms_merchant){
                $error = ['error'=>[__('Phone already exist')]];
                return Helpers::validation($error);
            }
            //Agent Create
            $user = new Agent();
            $user->firstname = isset($data['firstname']) ? $data['firstname'] : null;
            $user->lastname = isset($data['lastname']) ? $data['lastname'] : null;
            $user->store_name = isset($data['store_name']) ? $data['store_name'] : null;
            $user->email = strtolower(trim($data['email']));
            $user->mobile =  $mobile;
            $user->full_mobile =    $complete_phone;
            $user->password = Hash::make($data['password']);
            $user->username = $userName;
            $user->address = [
                'address' => isset($data['address']) ? $data['address'] : '',
                'city' => isset($data['city']) ? $data['city'] : '',
                'zip' => isset($data['zip_code']) ? $data['zip_code'] : '',
                'country' =>isset($data['country']) ? $data['country'] : '',
                'state' => isset($data['state']) ? $data['state'] : '',
            ];
            $user->status = 1;
            $user->email_verified   = true;
            $user->sms_verified =  true;
            $user->kyc_verified =  ($basic_settings->agent_kyc_verification == true) ? false : true;
            $user->save();
            if( $user && $basic_settings->agent_kyc_verification == true){
                $create = [
                    'agent_id'       => $user->id,
                    'data'          => json_encode($get_values),
                    'created_at'    => now(),
                ];

                DB::beginTransaction();
                try{
                    DB::table('agent_kyc_data')->updateOrInsert(["agent_id" => $user->id],$create);
                    $user->update([
                        'kyc_verified'  => GlobalConst::PENDING,
                    ]);
                    DB::commit();
                }catch(Exception $e) {
                    DB::rollBack();
                    $user->update([
                        'kyc_verified'  => GlobalConst::DEFAULT,
                    ]);
                    $error = ['error'=>[__('Something went wrong! Please try again.')]];
                    return Helpers::validation($error);
                }

            }
            $token = $user->createToken('agent_token')->accessToken;
            $this->createUserWallets($user);
            $this->createQr($user);

        }catch(Exception $e) {
            $message = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($message);
        }
        $data = ['token' => $token, 'agent' => $user, ];
        $message =  ['success'=>[__('Registration Successful')]];
        return Helpers::success($data,$message);
    }
    public function createQr($user){
		$user = $user;
	    $qrCode = $user->qrCode()->first();
        $in['agent_id'] = $user->id;;
        $in['receiver_type'] = 'Agent';
        $in['sender_type'] = 'Agent';
        $data 				= [
			'receiver_type' => 'Agent',
			'sender_type' 	=> 'Agent',
			'phone'			=> $user->full_mobile,
			'amount'		=> null,
		];
        $in['qr_code'] 		= $data;
	    if(!$qrCode){
            AgentQrCode::create($in);
	    }else{
            $qrCode->fill($in)->save();
        }
	    return $qrCode;
	}
    /**
     * Method for verify sms otp
     */
    public function verifySms(Request $request){
        $validator      = Validator::make($request->all(), [
            'phone'     => "required",
            'code'      => "required|max:6",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $code = $request->code;
        $otp_exp_sec = BasicSettingsProvider::get()->agent_otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = AgentAuthorization::where("phone",$request->phone)->first();
        if(!$auth_column){
            $message = ['error'=>[__('Invalid request')]];
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
     * Method for verify sms otp
     */
    public function verifySmsOtp(Request $request){
        $basic_settings = $this->basic_settings;
        $passowrd_rule = "required|string|min:6|confirmed";
        if($basic_settings->agent_secure_password) {
            $passowrd_rule = ["required","confirmed",Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()];
        }
        if( $basic_settings->agent_agree_policy){
            $agree ='required';
        }else{
            $agree ='';
        }
        $validator = Validator::make($request->all(), [
            'email'         => "required|email",
            'code'          => "required|max:6",
            'firstname'     => 'required|string|max:60',
            'lastname'      => 'required|string|max:60',
            'store_name'    => 'required|string|max:100',
            'password'      => $passowrd_rule,
            'country'       => 'required|string|max:150',
            'city'          => 'required|string|max:150',
            'phone'         => 'nullable|string|max:20',
            'zip_code'      => 'required|string|max:8',
            'agree'         =>  $agree,
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        if($basic_settings->agent_kyc_verification == true){
            $user_kyc_fields = SetupKyc::agentKyc()->first()->fields ?? [];
            $validation_rules = $this->generateValidationRules($user_kyc_fields);
            $validated = Validator::make($request->all(), $validation_rules);

            if ($validated->fails()) {
                $message =  ['error' => $validated->errors()->all()];
                return Helpers::error($message);
            }
            $validated = $validated->validate();
            $get_values = $this->registerPlaceValueWithFields($user_kyc_fields, $validated);
        }
        $code = $request->code;
        $otp_exp_sec = BasicSettingsProvider::get()->agent_otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = AgentAuthorization::where("phone",$request->phone)->first();
        if(!$auth_column){
            $message = ['error'=>[__('Invalid request')]];
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

            $data                       = $request->all();
            $mobile                     = remove_speacial_char($data['phone']);
            $complete_phone             = $mobile;
            $userName                   = $data['username'];

            $check_user_name = User::where('username',$userName)->first();
            if($check_user_name){
                $error = ['error'=>[__('Username already exist')]];
                return Helpers::validation($error);
            }
            $check_user_name_agent = Agent::where('username',$userName)->first();
            if($check_user_name_agent){
                $error = ['error'=>[__('Username already exist')]];
                return Helpers::validation($error);
            }
            $check_user_name_merchant = Merchant::where('username',$userName)->first();
            if($check_user_name_merchant){
                $error = ['error'=>[__('Username already exist')]];
                return Helpers::validation($error);
            }

            //check email
            $check_user_email = User::where('email',$data['email'])->first();
            if($check_user_email){
                $error = ['error'=>[__('Email already exist')]];
                return Helpers::validation($error);
            }
            $check_email_agent = Agent::where('email',$data['email'])->first();
            if($check_email_agent){
                $error = ['error'=>[__('Email already exist')]];
                return Helpers::validation($error);
            }
            $check_email_merchant = Merchant::where('email',$data['email'])->first();
            if($check_email_merchant){
                $error = ['error'=>[__('Email already exist')]];
                return Helpers::validation($error);
            }
            //check sms
            $check_user_sms = User::where('full_mobile',$data['phone'])->first();
            if($check_user_sms){
                $error = ['error'=>[__('Phone already exist')]];
                return Helpers::validation($error);
            }
            $check_sms_agent = Agent::where('full_mobile',$data['phone'])->first();
            if($check_sms_agent){
                $error = ['error'=>[__('Phone already exist')]];
                return Helpers::validation($error);
            }
            $check_sms_merchant = Merchant::where('full_mobile',$data['phone'])->first();
            if($check_sms_merchant){
                $error = ['error'=>[__('Phone already exist')]];
                return Helpers::validation($error);
            }
            //Agent Create
            $user = new Agent();
            $user->firstname = isset($data['firstname']) ? $data['firstname'] : null;
            $user->lastname = isset($data['lastname']) ? $data['lastname'] : null;
            $user->store_name = isset($data['store_name']) ? $data['store_name'] : null;
            $user->email = strtolower(trim($data['email']));
            $user->mobile =  $mobile;
            $user->full_mobile =    $complete_phone;
            $user->password = Hash::make($data['password']);
            $user->username = $userName;
            $user->address = [
                'address' => isset($data['address']) ? $data['address'] : '',
                'city' => isset($data['city']) ? $data['city'] : '',
                'zip' => isset($data['zip_code']) ? $data['zip_code'] : '',
                'country' =>isset($data['country']) ? $data['country'] : '',
                'state' => isset($data['state']) ? $data['state'] : '',
            ];
            $user->status = 1;
            $user->email_verified   = true;
            $user->sms_verified =  true;
            $user->kyc_verified =  ($basic_settings->agent_kyc_verification == true) ? false : true;
            $user->save();
            if( $user && $basic_settings->agent_kyc_verification == true){
                $create = [
                    'agent_id'       => $user->id,
                    'data'          => json_encode($get_values),
                    'created_at'    => now(),
                ];

                DB::beginTransaction();
                try{
                    DB::table('agent_kyc_data')->updateOrInsert(["agent_id" => $user->id],$create);
                    $user->update([
                        'kyc_verified'  => GlobalConst::PENDING,
                    ]);
                    DB::commit();
                }catch(Exception $e) {
                    DB::rollBack();
                    $user->update([
                        'kyc_verified'  => GlobalConst::DEFAULT,
                    ]);
                    $error = ['error'=>[__('Something went wrong! Please try again.')]];
                    return Helpers::validation($error);
                }

            }
            $token = $user->createToken('agent_token')->accessToken;
            $this->createUserWallets($user);
            $this->createQr($user);
        }catch(Exception $e) {
            $message = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($message);
        }
        $data = ['token' => $token, 'agent' => $user, ];
        $message =  ['success'=>[__('Registration Successful')]];
        return Helpers::success($data,$message);
    }
    /**
     * Method for resend email otp
     */
    public function resendEmailOtp(Request $request){
        $validator = Validator::make($request->all(), [
            'email'     => "required|email",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $resend = AgentAuthorization::where("email",$request->email)->first();
        if($resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                $message = ['error'=>[__("You can resend the verification code after").' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)). ' '.__('seconds')]];
                return Helpers::error($message);
            }
        }
        $code = generate_random_code();
        $data = [
            'agent_id'       =>  0,
            'email'         => $request->email,
            'code'          => $code,
            'token'         => generate_unique_string("agent_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            $oldToken = AgentAuthorization::where("email",$request->email)->get();
            if($oldToken){
                foreach($oldToken as $token){
                    $token->delete();
                }
            }
            DB::table("agent_authorizations")->insert($data);
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
     * Method for resend sms otp
     */
    public function resendSMSOtp(Request $request){
        $validator = Validator::make($request->all(), [
            'phone'     => "required",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $resend = AgentAuthorization::where("phone",$request->phone)->first();
        if($resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                $message = ['error'=>[__("You can resend the verification code after").' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)). ' '.__('seconds')]];
                return Helpers::error($message);
            }
        }
        $code = generate_random_code();
        $data = [
            'agent_id'      =>  0,
            'phone'         => $request->phone,
            'code'          => $code,
            'token'         => generate_unique_string("agent_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            $oldToken = AgentAuthorization::where("phone",$request->phone)->get();
            if($oldToken){
                foreach($oldToken as $token){
                    $token->delete();
                }
            }
            DB::table("agent_authorizations")->insert($data);
            $message = __("Your authorization resend code is :code",['code' => $code]);
               sendApiSMS($message,$validated['phone']); 
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
