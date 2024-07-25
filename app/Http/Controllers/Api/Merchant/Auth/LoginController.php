<?php

namespace App\Http\Controllers\Api\Merchant\Auth;

use Exception;
use App\Models\User;
use App\Models\Agent;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Models\Admin\SetupKyc;
use App\Models\Merchants\Merchant;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Traits\Merchant\LoggedInUsers;
use App\Models\Merchants\MerchantQrCode;
use App\Traits\Merchant\RegisteredUsers;
use App\Traits\ControlDynamicInputFields;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use App\Providers\Admin\BasicSettingsProvider;
use App\Http\Helpers\Api\Helpers as ApiHelpers;
use Illuminate\Foundation\Auth\AuthenticatesUsers;

class LoginController extends Controller
{
    use  AuthenticatesUsers, LoggedInUsers ,RegisteredUsers,ControlDynamicInputFields;
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
        $user = Merchant::orWhere('full_mobile',$request->phone)
                ->orWhere('email',$request->phone)->first();
        if(!$user){
            $error = ['error'=>[__('Merchant does not exist')]];
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
            $this->createDeveloperApi($user);
            $this->refreshSandboxWallets($user);
            $this->createGatewaySetting($user);
            $this->createLoginLog($user);
            $this->createQr($user);
            $token = $user->createToken('Merchant Token')->accessToken;
            $data = ['token' => $token, 'merchant' => $user, ];
            $message =  ['success'=>[__('Login Successful')]];
            return ApiHelpers::success($data,$message);

        } else {
            $error = ['error'=>[__('Incorrect Password')]];
            return ApiHelpers::error($error);
        }

    }
    /**
     * Method for registering a new merchant
     */
    public function register(Request $request){
        $basic_settings = $this->basic_settings;
        $passowrd_rule = "required|string|min:6|confirmed";
        if($basic_settings->merchant_secure_password) {
            $passowrd_rule = ["required","confirmed",Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()];
        }
        if( $basic_settings->merchant_agree_policy){
            $agree ='required';
        }else{
            $agree ='';
        }

        if($basic_settings->merchant_email_verification == true){
            $email_field = "required|string|email|max:150|unique:merchants,email";
        }else{
            $email_field = "nullable";
        }

        $validator = Validator::make($request->all(), [
            'firstname'             => 'required|string|max:60',
            'lastname'              => 'required|string|max:60',
            'business_name'         => 'required|string|max:60',
            'email'                 => $email_field,
            'password'              => $passowrd_rule,
            'country'               => 'required|string|max:150',
            'city'                  => 'required|string|max:150',
            'phone'                 => 'required|string|max:20',
            'zip_code'              => 'required|string|max:8',
            'agree'                 =>  $agree,
            'username'              => 'required'
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return ApiHelpers::validation($error);
        }
        if($basic_settings->merchant_kyc_verification == true){
            $user_kyc_fields = SetupKyc::merchantKyc()->first()->fields ?? [];
            $validation_rules = $this->generateValidationRules($user_kyc_fields);
            $validated = Validator::make($request->all(), $validation_rules);

            if ($validated->fails()) {
                $message =  ['error' => $validated->errors()->all()];
                return ApiHelpers::error($message);
            }
            $validated = $validated->validate();
            $get_values = $this->registerPlaceValueWithFields($user_kyc_fields, $validated);
        }
        $data                       = $request->all();
        $mobile                     = remove_speacial_char($data['phone']);
        $complete_phone             = $mobile;
        if($validated['email'] != '' || $validated['email'] != null){
            $email = Merchant::where('email',$data['email'])->first();
            if($email){
                $error = ['error'=>[__('Email address already exist')]];
                return ApiHelpers::validation($error);
            }
        }
        

        $user_merchant = Merchant::where('full_mobile',$complete_phone)->first();
        if($user_merchant){
            $error = ['error'=>[__('Mobile number already exist in merchant.')]];
            return ApiHelpers::validation($error);
        }
        $user_agent = Agent::where('full_mobile',$complete_phone)->first();
        if($user_agent){
            $error = ['error'=>[__('Mobile number already exist in agent.')]];
            return ApiHelpers::validation($error);
        }
        $user = User::where('full_mobile',$complete_phone)->first();
        if($user){
            $error = ['error'=>[__('Mobile number already exist in user. ')]];
            return ApiHelpers::validation($error);
        }

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

        if($basic_settings->merchant_email_verification == false && ($data['email'] == '' || $data['email'] == null) ){
            $email_verified     = true;
        }elseif($basic_settings->merchant_email_verification == false && ($data['email'] != '' || $data['email'] != null)){
            $email_verified     = false;
        }else{
            $email_verified     = true;
        }

        //Merchant Create
        $user = new Merchant();
        $user->firstname = isset($data['firstname']) ? $data['firstname'] : null;
        $user->lastname = isset($data['lastname']) ? $data['lastname'] : null;
        $user->business_name = isset($data['business_name']) ? $data['business_name'] : null;
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
        $user->email_verified   = $email_verified;
        $user->sms_verified =  true;
        $user->kyc_verified =  ($basic_settings->merchant_kyc_verification == true) ? false : true;
        $user->save();
        if( $user && $basic_settings->merchant_kyc_verification == true){
            $create = [
                'merchant_id'       => $user->id,
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
                $error = ['error'=>[__('Something went wrong! Please try again.')]];
                return ApiHelpers::validation($error);
            }

           }
        $token = $user->createToken('merchant_token')->accessToken;
        $this->createUserWallets($user);
        $this->createDeveloperApiReg($user);
        $this->createQr($user);

        $data = ['token' => $token, 'merchant' => $user, ];
        $message =  ['success'=>[__('Registration Successful')]];
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
        $in['merchant_id'] = $user->id;
        $in['slug'] 		= Str::uuid();
        $data 				= [
			'receiver_type' => 'Merchant',
			'username'		=> $user->username,
			'amount'		=> null,
		];
        $in['qr_code'] =  $data;
	    if(!$qrCode){
            MerchantQrCode::create($in);
	    }else{
            $qrCode->fill($in)->save();
        }
	    return $qrCode;
	}
    protected function guard()
    {
        return Auth::guard("merchant_api");
    }

}
