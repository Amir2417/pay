<?php

namespace App\Http\Controllers\Api\Agent\Auth;

use App\Constants\GlobalConst;
use App\Http\Controllers\Controller;
use App\Http\Helpers\Api\Helpers as ApiHelpers;
use App\Models\Admin\SetupKyc;
use App\Models\Agent;
use App\Models\AgentQrCode;
use App\Models\Merchants\Merchant;
use App\Models\User;
use App\Providers\Admin\BasicSettingsProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Traits\Agent\LoggedInUsers;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use App\Traits\Agent\RegisteredUsers;
use App\Traits\ControlDynamicInputFields;
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
        $validator = Validator::make($request->all(), [
            'phone' => 'required|max:50',
            'password' => 'required|min:6',
        ]);

        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return ApiHelpers::validation($error);
        }
        $user = Agent::where('full_mobile',$request->phone)->first();
        if(!$user){
            $error = ['error'=>[__("Agent doesn't exists.")]];
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
            $token = $user->createToken('agent_token')->accessToken;
            $data = ['token' => $token, 'agent' => $user, ];
            $message =  ['success'=>[__('Login Successful')]];
            return ApiHelpers::success($data,$message);

        } else {
            $error = ['error'=>[__('Incorrect Password')]];
            return ApiHelpers::error($error);
        }

    }

    public function register(Request $request){
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

        if($basic_settings->email_verification == true){
            $email_field = "required|string|email|max:150|unique:agents,email";
        }else{
            $email_field = "nullable";
        }

        $validator = Validator::make($request->all(), [
            'firstname'     => 'required|string|max:60',
            'lastname'      => 'required|string|max:60',
            'store_name'    => 'required|string|max:100',
            'email'         => $email_field ,
            'password'      => $passowrd_rule,
            'country'       => 'required|string|max:150',
            'city'          => 'required|string|max:150',
            'phone'         => 'required|string|max:20',
            'zip_code'      => 'required|string|max:8',
            'agree'         =>  $agree,

        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return ApiHelpers::validation($error);
        }
        if($basic_settings->agent_kyc_verification == true){
            $user_kyc_fields = SetupKyc::agentKyc()->first()->fields ?? [];
            $validation_rules = $this->generateValidationRules($user_kyc_fields);
            $validated = Validator::make($request->all(), $validation_rules);

            if ($validated->fails()) {
                $message =  ['error' => $validated->errors()->all()];
                return ApiHelpers::error($message);
            }
            $validated = $validated->validate();
            $get_values = $this->registerPlaceValueWithFields($user_kyc_fields, $validated);
        }
        $data = $request->all();
        $mobile        = remove_speacial_char($data['phone']);
        
        $complete_phone             =  $mobile;

        if($validated['email'] != '' || $validated['email'] != null){  
            $email = Agent::where('email',$data['email'])->first();
            if($email){
                $error = ['error'=>[__('Email address already exist')]];
                return ApiHelpers::validation($error);
            }
        }
    

        $mobile_validate = Agent::where('full_mobile',$complete_phone)->first();
        if($mobile_validate){
            $error = ['error'=>[__('Mobile number already exist')]];
            return ApiHelpers::validation($error);
        }
        $mobile_validate_user = User::where('full_mobile',$complete_phone)->first();
        if($mobile_validate_user){
            $error = ['error'=>[__('Mobile number already exist in user.')]];
            return ApiHelpers::validation($error);
        }
        $mobile_validate_merchant = Merchant::where('full_mobile',$complete_phone)->first();
        if($mobile_validate_merchant){
            $error = ['error'=>[__('Mobile number already exist in merchant.')]];
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
        if($basic_settings->agent_email_verification == false && ($data['email'] == '' || $data['email'] == null) ){
            $email_verified     = true;
        }elseif($basic_settings->agent_email_verification == false && ($data['email'] != '' || $data['email'] != null)){
            $email_verified     = false;
        }else{
            $email_verified     = true;
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
        $user->email_verified   = $email_verified;
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
                return ApiHelpers::validation($error);
            }

           }
        $token = $user->createToken('agent_token')->accessToken;
        $this->createUserWallets($user);
        $this->createQr($user);

        $data = ['token' => $token, 'agent' => $user, ];
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
    protected function guard()
    {
        return Auth::guard("agent_api");
    }

}
