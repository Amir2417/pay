<?php

namespace App\Http\Controllers\Api\User\Auth;

use Exception;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Agent;
use App\Models\UserQrCode;
use Illuminate\Http\Request;
use App\Http\Helpers\Helpers;
use App\Constants\GlobalConst;
use App\Models\Admin\SetupKyc;
use App\Models\GeneralSetting;
use App\Models\UserAuthorization;
use App\Models\Merchants\Merchant;
use App\Traits\User\LoggedInUsers;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Traits\User\RegisteredUsers;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Traits\ControlDynamicInputFields;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Notification;
use App\Providers\Admin\BasicSettingsProvider;
use App\Http\Helpers\Api\Helpers as ApiHelpers;
use App\Notifications\User\Auth\SendVerifyCode;
use App\Notifications\User\Auth\SendAuthorizationCode;

class LoginController extends Controller
{
    use  LoggedInUsers ,RegisteredUsers,ControlDynamicInputFields;
    protected $basic_settings;

    public function __construct()
    {
        $this->basic_settings = BasicSettingsProvider::get();
    }
    public function login(Request $request){
        $validator      = Validator::make($request->all(), [
            'phone'     => 'required|max:50',
            'password'  => 'required|min:6',
        ]);

        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return ApiHelpers::validation($error);
        }

        $user = User::orWhere('full_mobile',$request->phone)->orWhere('email',$request->phone)->first();
        if(!$user){
            $error = ['error'=>[__("User doesn't exists.")]];
            return ApiHelpers::validation($error);
        }
        if (Hash::check($request->password, $user->password)) {
            if($user->status == 0){
                $error = ['error'=>[__('Account Has been Suspended')]];
                return ApiHelpers::validation($error);
            }
            $user->two_factor_verified = false;
            $user->save();
            $this->refreshUserWallets($user);
            $this->createLoginLog($user);
            $this->createQr($user);
            $token = $user->createToken('user_token')->accessToken;

            $data = ['token' => $token, 'user' => $user, ];
            $message =  ['success'=>[__('Login Successful')]];
            return ApiHelpers::success($data,$message);

        } else {
            $error = ['error'=>[__('Incorrect Password')]];
            return ApiHelpers::error($error);
        }

    }

    public function register(Request $request){
        $basic_settings     = $this->basic_settings;
        $passowrd_rule      = "required|string|min:6|confirmed";
        if($basic_settings->secure_password) {
            $passowrd_rule = ["required","confirmed",Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()];
        }
        if( $basic_settings->agree_policy){
            $agree ='required';
        }else{
            $agree ='';
        }
        if($request->type == 'phone'){
            $phone_field    = 'required';
        }else{
            $phone_field    = 'nullable';
        }
        if($request->type == 'email'){
            $email_field    = 'required';
        }else{
            $email_field    = 'nullable';
        }

        $validator = Validator::make($request->all(), [
            'firstname'     => 'required|string|max:60',
            'lastname'      => 'required|string|max:60',
            'email'         => $email_field,
            'password'      => $passowrd_rule,
            'country'       => 'required|string|max:150',
            'username'      => 'required|string|max:150',
            'city'          => 'required|string|max:150',
            'phone'         => $phone_field,
            'zip_code'      => 'required|string|max:8',
            'agree'         =>  $agree,
            'type'          => 'required|string|max:150',

        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return ApiHelpers::validation($error);
        }
        if($basic_settings->kyc_verification == true){
            $user_kyc_fields = SetupKyc::userKyc()->first()->fields ?? [];
            $validation_rules = $this->generateValidationRules($user_kyc_fields);
            $validated = Validator::make($request->all(), $validation_rules);

            if ($validated->fails()) {
                $message =  ['error' => $validated->errors()->all()];
                return ApiHelpers::error($message);
            }
            $validated = $validated->validate();
            $get_values = $this->registerPlaceValueWithFields($user_kyc_fields, $validated);
        }else{
            $get_values = []; 
        }
        $data                       = $request->all();
        $userName = $data['username'];
        $check_user_name = User::where('username',$userName)->first();
        if($check_user_name){
            $error = ['error'=>[__('Username already exist')]];
            return ApiHelpers::validation($error);
        }
        $check_user_name_agent = Agent::where('username',$userName)->first();
        if($check_user_name_agent){
            $error = ['error'=>[__('Username already exist')]];
            return ApiHelpers::validation($error);
        }
        $check_user_name_merchant = Merchant::where('username',$userName)->first();
        if($check_user_name_merchant){
            $error = ['error'=>[__('Username already exist')]];
            return ApiHelpers::validation($error);
        }

        if($request->phone != '' || $request->phone != null){
            $data['mobile']        = remove_speacial_char($data['phone']);
            $complete_phone             = $data['mobile'];
            $mobile_validate            = User::where('full_mobile',$complete_phone)->first();
            $mobile_validate_agent      = Agent::where('full_mobile',$complete_phone)->first();
            $mobile_validate_merchant   = Merchant::where('full_mobile',$complete_phone)->first();
            if($mobile_validate){
                $error = ['error'=>[__('Mobile number already exist')]];
                return ApiHelpers::validation($error);
            }
            if($mobile_validate_agent){
                $error = ['error'=>[__('Mobile number already exist in agent.')]];
                return ApiHelpers::validation($error);
            }
            if($mobile_validate_merchant){
                $error = ['error'=>[__('Mobile number already exist in merchant.')]];
                return ApiHelpers::validation($error);
            }
            $data['full_mobile']       = $complete_phone;
        }else{
            $data['full_mobile']       = '';
        }
        
        if($data['email'] != '' || $data['email'] != null){
            $email = User::where('email',$data['email'])->first();
            $agent_email = Agent::where('email',$data['email'])->first();
            $merchant_email = Merchant::where('email',$data['email'])->first();
            if($email){
                $error = ['error'=>[__('Email address already exist in user.')]];
                return ApiHelpers::validation($error);
            }
            if($agent_email){
                $error = ['error'=>[__('Email address already exist in agent.')]];
                return ApiHelpers::validation($error);
            }
            if($merchant_email){
                $error = ['error'=>[__('Email address already exist in merchant.')]];
                return ApiHelpers::validation($error);
            }
            $data['email']         = $request->email;
        }else{
            $data['email']         = '';
        }
        if($data['type'] == 'phone'){
            $sms_verified      = true;
            $email_verified    = false;
        }else if($data['type'] == 'email'){
            $sms_verified     = false;
            $email_verified    = true;
        }else{
            $sms_verified     = false;
            $email_verified    = false; 
        }
        
        //User Create
        $user                   = new User();
        $user->firstname        = isset($data['firstname']) ? $data['firstname'] : null;
        $user->lastname         = isset($data['lastname']) ? $data['lastname'] : null;
        $user->email            = strtolower(trim($data['email']));
        $user->mobile           = $complete_phone ?? '';
        $user->full_mobile      = $complete_phone ?? '';
        $user->password         = Hash::make($data['password']);
        $user->username         = $userName;
        $user->address          = [
            'address'           => isset($data['address']) ? $data['address'] : '',
            'city'              => isset($data['city']) ? $data['city'] : '',
            'zip'               => isset($data['zip_code']) ? $data['zip_code'] : '',
            'country'           => isset($data['country']) ? $data['country'] : '',
            'state'             => isset($data['state']) ? $data['state'] : '',
        ];
        $user->status           = 1;
        $user->sms_verified     = $sms_verified;
        $user->email_verified   = $email_verified;
        $user->kyc_verified     = ($basic_settings->kyc_verification == true) ? false : true;
        $user->save();
        if( $user && $basic_settings->kyc_verification == true){
            $create = [
                'user_id'       => $user->id,
                'data'          => json_encode($get_values),
                'created_at'    => now(),
            ];

            DB::beginTransaction();
            try{
                DB::table('user_kyc_data')->updateOrInsert(["user_id" => $user->id],$create);
                $user->update([
                    'kyc_verified'  => GlobalConst::PENDING,
                ]);
                DB::commit();
            }catch(Exception $e) {
                DB::rollBack();
                $user->update([
                    'kyc_verified'  => GlobalConst::DEFAULT,
                ]);
                $error = ['error'=>[_('Something went wrong! Please try again.')]];
                return ApiHelpers::validation($error);
            }
        }
        $token = $user->createToken('user_token')->accessToken;
        $this->createUserWallets($user);
        $this->createQr($user);
        $data = ['token' => $token, 'user' => $user, ];
        $message = ['success'=>[__('Registration Successfull.')]];
        return ApiHelpers::success($data,$message);
        
    }

    public function logout(){
        Auth::user()->token()->revoke();
        $message = ['success'=>[__('Logout Successfully!')]];
        return ApiHelpers::onlysuccess($message);

    }
    public function createQr($user){
		$user = $user;
	    $qrCode = $user->qrCode()->first();
        $in['user_id'] = $user->id;;
        $in['receiver_type'] = 'User';
        $in['sender_type'] = 'User';
        $data 				= [
			'receiver_type' => 'User',
			'sender_type' => 'User',
			'username'			=> $user->username,
			'amount'		=> null,
		];
        $in['qr_code'] 		= $data;
	    if(!$qrCode){
            UserQrCode::create($in);
	    }else{
            $qrCode->fill($in)->save();
        }
	    return $qrCode;
	}

}
