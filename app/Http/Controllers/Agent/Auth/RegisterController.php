<?php

namespace App\Http\Controllers\Agent\Auth;

use App\Http\Controllers\Controller;
use App\Constants\GlobalConst;
use App\Models\Admin\SetupKyc;
use App\Models\Agent;
use App\Models\AgentAuthorization;
use App\Models\Merchants\Merchant;
use App\Models\User;
use App\Providers\Admin\BasicSettingsProvider;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Auth\Events\Registered;
use App\Notifications\User\Auth\SendVerifyCode;
use App\Traits\Agent\RegisteredUsers;
use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Traits\ControlDynamicInputFields;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers, RegisteredUsers, ControlDynamicInputFields;

    protected $basic_settings;

    public function __construct()
    {
        $this->basic_settings = BasicSettingsProvider::get();
    }

    /**
     * Show the application registration form.
     *
     * @return \Illuminate\View\View
     */
    public function showRegistrationForm() {
        $client_ip = request()->ip() ?? false;
        $user_country = geoip()->getLocation($client_ip)['country'] ?? "";

        $page_title = __("Agent Registration");
        return view('agent.auth.register',compact(
            'page_title',
            'user_country',
        ));
    }
    //========================before registration======================================
    /**
     * Method for send code to phone number
     */
    public function sendVerifyCode(Request $request){
        $basic_settings = $this->basic_settings;
        if($basic_settings->agent_agree_policy){
            $agree = 'required';
        }else{
            $agree = '';
        }
        
        $validator = Validator::make($request->all(),[
            'register_type' => 'required|in:'.global_const()::PHONE.','.global_const()::EMAIL,
            'credentials' => ['required', function ($attribute, $value, $fail) use ($request) {
                if ($request->register_type == global_const()::PHONE && !preg_match('/^[0-9]{10,15}$/', $value)) {
                    $fail('The ' . $attribute . ' must be a valid phone number.');
                }
                if ($request->register_type == global_const()::EMAIL && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $fail('The ' . $attribute . ' must be a valid email address.');
                }
            }],
            'agree'                 =>  $agree,

        ]);
        $validated = $validator->validate();
        if($validated['register_type'] == GlobalConst::PHONE){
            $exist              = User::where('full_mobile',$validated['credentials'])->first();
            $exists_agent       = Agent::where('full_mobile',$validated['credentials'])->first();
            $exists_merchant    = Merchant::where('full_mobile',$validated['credentials'])->first();
            if( $exist || $exists_agent || $exists_merchant) return back()->with(['error' => [__('User already  exists, please try with another phone number.')]]);
            $code = generate_random_code();
            $data = [
                'agent_id'       => 0,
                'phone'         => $validated['credentials'],
                'code'          => $code,
                'token'         => generate_unique_string("agent_authorizations","token",200),
                'created_at'    => now(),
            ];
            DB::beginTransaction();
            try{
                if($basic_settings->agent_sms_verification == false){
                    Session::put('register_data',[
                        'credentials'   => $validated['credentials'],
                        'register_type' => $validated['register_type'],
                        'sms_verified'  => false,
                        'email_verified'=> false,
                    ]);
                    return redirect()->route("agent.register.kyc");
                }
                DB::table("agent_authorizations")->insert($data);
                Session::put('register_data',[
                    'credentials'   => $validated['credentials'],
                    'register_type' => $validated['register_type'],
                ]);
                if($basic_settings->agent_sms_notification == true && $basic_settings->agent_sms_verification == true){
                    $message = __("Your verification code is :code",['code' => $code]);
                    sendApiSMS($message,$validated['credentials']);                
                }
                DB::commit();
            }catch(Exception $e) {
                DB::rollBack();
                return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
            };
            return redirect()->route('agent.sms.verify',$data['token'])->with(['success' => [__('Verification code sended to your phone number.')]]);
        }else{
            $exist              = User::where('email',$validated['credentials'])->first();
            $exists_agent       = Agent::where('email',$validated['credentials'])->first();
            $exists_merchant    = Merchant::where('email',$validated['credentials'])->first();
            if( $exist || $exists_agent || $exists_merchant) return back()->with(['error' => [__('User already  exists, please try with another email address.')]]);
            $code = generate_random_code();
            $data = [
                'agent_id'       => 0,
                'email'         => $validated['credentials'],
                'code'          => $code,
                'token'         => generate_unique_string("agent_authorizations","token",200),
                'created_at'    => now(),
            ];
           
            try{
                if($basic_settings->agent_email_verification == false){
                    Session::put('register_data',[
                        'credentials'   => $validated['credentials'],
                        'register_type' => $validated['register_type'],
                        'email_verified'  => false,
                        'sms_verified'    => false
                    ]);
                    return redirect()->route("agent.register.kyc");
                }
                DB::table("agent_authorizations")->insert($data);
                Session::put('register_data',[
                    'credentials'   => $validated['credentials'],
                    'register_type' => $validated['register_type'],
                ]);
                if($basic_settings->agent_email_notification == true && $basic_settings->agent_email_verification == true){
                    Notification::route("mail",$validated['credentials'])->notify(new SendVerifyCode($validated['credentials'], $code));             
                }
                DB::commit();
            }catch(Exception $e) {
                DB::rollBack();
                return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
            };
            return redirect()->route('agent.email.send.otp.code',$data['token'])->with(['success' => [__('Verification code sended to your email address.')]]);
        }       
        
    }
    /**
     * Method for sms verify code 
     * @param $token
     */
    public function verifyCode(Request $request,$token){
        $request->merge(['token' => $token]);
        $request->validate([
            'token'     => "required|string|exists:agent_authorizations,token",
            'code'      => "required|array",
            'code.*'    => "required|numeric",
        ]);
        $code = $request->code;
        $code = implode("",$code);
        $otp_exp_sec = BasicSettingsProvider::get()->otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = AgentAuthorization::where("token",$request->token)->where("code",$code)->first();
        if(!$auth_column){
            return back()->with(['error' => [__('The verification code does not match')]]);
        }
        if($auth_column->created_at->addSeconds($otp_exp_sec) < now()) {
            $auth_column->delete();
            return redirect()->route('agent.register')->with(['error' => [__('Session expired. Please try again')]]);
        }
        $register_type              = session()->get('register_data.register_type');
        Session::put('register_data',[
            'credentials'           => $auth_column->phone,
            'register_type'         => $register_type,
            'sms_verified'          => true,
            'email_verified'        => false,

        ]);
        try{
            $auth_column->delete();
        }catch(Exception $e) {
            return redirect()->route('agent.register')->with(['error' => [__('Something went wrong! Please try again.')]]);
        }

        return redirect()->route("agent.register.kyc")->with(['success' => [__('Otp successfully verified')]]);
    }
    /**
     * Method for email verify code
     * @param $token
     */
    public function EmailVerifyCode(Request $request,$token){
        $request->merge(['token' => $token]);
        $request->validate([
            'token'     => "required|string|exists:agent_authorizations,token",
            'code'      => "required|array",
            'code.*'    => "required|numeric",
        ]);
        $code = $request->code;
        $code = implode("",$code);
        
        $otp_exp_sec = BasicSettingsProvider::get()->otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = AgentAuthorization::where("token",$request->token)->where("code",$code)->first();
        if(!$auth_column){
            return back()->with(['error' => [__('The verification code does not match')]]);
        }
        if($auth_column->created_at->addSeconds($otp_exp_sec) < now()) {
            $auth_column->delete();
            return redirect()->route('agent.register.kyc')->with(['error' => [__('Session expired. Please try again')]]);
        }
        
        try{
            $auth_column->delete();
            $validated                      = session()->get('register_data.validated');
            $get_values                     = session()->get('register_data.get_values');
            $validated['email_verified']    = true;
            $basic_settings                 = $this->basic_settings;
            $data                           = event(new Registered($user = $this->create($validated)));
            
            if( $data && $basic_settings->agent_kyc_verification == true){
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

                    return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
                }

            }
            $request->session()->forget('register_data');
            
        }catch(Exception $e) {
            return redirect()->route('agent.register.kyc')->with(['error' => [__('Something went wrong! Please try again.')]]);
        }

        $this->guard()->login($user);

        return $this->registered($request, $user);
    }
    /**
     * Method for sms verify code
     * @param $token
     */
    public function smsOtpVerifyCode(Request $request,$token){
        $request->merge(['token' => $token]);
        $request->validate([
            'token'     => "required|string|exists:agent_authorizations,token",
            'code'      => "required|array",
            'code.*'    => "required|numeric",
        ]);
        $code = $request->code;
        $code = implode("",$code);
        
        $otp_exp_sec = BasicSettingsProvider::get()->otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = AgentAuthorization::where("token",$request->token)->where("code",$code)->first();
        if(!$auth_column){
            return back()->with(['error' => [__('The verification code does not match')]]);
        }
        if($auth_column->created_at->addSeconds($otp_exp_sec) < now()) {
            $auth_column->delete();
            return redirect()->route('agent.register.kyc')->with(['error' => [__('Session expired. Please try again')]]);
        }
        
        try{
            $auth_column->delete();
            $validated                      = session()->get('register_data.validated');
            $get_values                     = session()->get('register_data.get_values');
            $validated['sms_verified']      = true;
            $basic_settings                 = $this->basic_settings;
            $data                           = event(new Registered($user = $this->create($validated)));
            
            if( $data && $basic_settings->agent_kyc_verification == true){
                $create = [
                    'agent_id'      => $user->id,
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

                    return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
                }

            }
            $request->session()->forget('register_data');
            
        }catch(Exception $e) {
            return redirect()->route('agent.register.kyc')->with(['error' => [__('Something went wrong! Please try again.')]]);
        }

        $this->guard()->login($user);

        return $this->registered($request, $user);
    }
    /**
     * Method for email verify code
     * @param $token
     */
    public function EmailOtpVerifyCode(Request $request,$token){
        $request->merge(['token' => $token]);
        $request->validate([
            'token'     => "required|string|exists:agent_authorizations,token",
            'code'      => "required|array",
            'code.*'    => "required|numeric",
        ]);
        $code = $request->code;
        $code = implode("",$code);
        
        $otp_exp_sec = BasicSettingsProvider::get()->otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = AgentAuthorization::where("token",$request->token)->where("code",$code)->first();
       
        if(!$auth_column){
            return back()->with(['error' => [__('The verification code does not match')]]);
        }
        if($auth_column->created_at->addSeconds($otp_exp_sec) < now()) {
            
            $auth_column->delete();
            return redirect()->route('agent.register')->with(['error' => [__('Session expired. Please try again')]]);
        }
       
        $register_type              = session()->get('register_data.register_type');
        Session::put('register_data',[
            'credentials'           => $auth_column->email,
            'register_type'         => $register_type,
            'email_verified'        => true,
            'sms_verified'          => false
        ]);
        
        try{
           
            $auth_column->delete();
        }catch(Exception $e) {
            return redirect()->route('agent.register')->with(['error' => [__('Something went wrong! Please try again.')]]);
        }
        
        return redirect()->route("agent.register.kyc")->with(['success' => [__('Otp successfully verified')]]);
    }
    /**
     * Method for sms resend code
     */
    public function resendCode(){
        $phone = session()->get('register_data.credentials');
        $resend = AgentAuthorization::where("phone",$phone)->first();
        if( $resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                throw ValidationException::withMessages([
                    'code'      => __('You can resend the verification code after').' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_PASS_RESEND_TIME_MINUTE)). ' '. __('seconds'),
                ]);
            }
        }

        $code = generate_random_code();
        $data = [
            'agent_id'       =>  0,
            'phone'         => $phone,
            'code'          => $code,
            'token'         => generate_unique_string("agent_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            $oldToken = AgentAuthorization::where("phone",$phone)->get();
            if($oldToken){
                foreach($oldToken as $token){
                    $token->delete();
                }
            }
            DB::table("agent_authorizations")->insert($data);
            $message = __("Your verification resend code is :code",['code' => $code]);
            sendApiSMS($message,$phone);    
            DB::commit();
            
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
        }
        return redirect()->route('agent.sms.verify',$data['token'])->with(['success' => [__('Verification code resend success')]]);
    }
    /**
     * Method for email resend code
     */
    public function emailResendCode(){
        $validated_data = session()->get('register_data.validated');
        $email          = $validated_data['email'];
        $resend = AgentAuthorization::where("email",$email)->first();
        if( $resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                throw ValidationException::withMessages([
                    'code'      => __('You can resend the verification code after').' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_PASS_RESEND_TIME_MINUTE)). ' '. __('seconds'),
                ]);
            }
        }

        $code = generate_random_code();
        $data = [
            'agent_id'       =>  0,
            'email'         => $email,
            'code'          => $code,
            'token'         => generate_unique_string("agent_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            $oldToken = AgentAuthorization::where("email",$email)->get();
            if($oldToken){
                foreach($oldToken as $token){
                    $token->delete();
                }
            }
            DB::table("agent_authorizations")->insert($data);
            Notification::route("mail",$email)->notify(new SendVerifyCode($email, $code));
            DB::commit(); 
            
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
        }
        return redirect()->route('agent.email.verify',$data['token'])->with(['success' => [__('Verification code resend success')]]);
    }
    /**
     * Method for email resend code
     */
    public function smsOtpResendCode(){
        $validated_data = session()->get('register_data.validated');
        $phone          = $validated_data['phone'];
        $resend = AgentAuthorization::where("phone",$phone)->first();
        if( $resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                throw ValidationException::withMessages([
                    'code'      => __('You can resend the verification code after').' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_PASS_RESEND_TIME_MINUTE)). ' '. __('seconds'),
                ]);
            }
        }

        $code = generate_random_code();
        $data = [
            'agent_id'       =>  0,
            'phone'         => $phone,
            'code'          => $code,
            'token'         => generate_unique_string("agent_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            $oldToken = AgentAuthorization::where("phone",$phone)->get();
            if($oldToken){
                foreach($oldToken as $token){
                    $token->delete();
                }
            }
            DB::table("agent_authorizations")->insert($data);
            $message = __("Your verification resend code is :code",['code' => $code]);
            sendApiSMS($message,$phone); 
            DB::commit(); 
            
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
        }
        return redirect()->route('agent.sms.otp.send',$data['token'])->with(['success' => [__('Verification code resend success')]]);
    }
    /**
     * Method for email resend code
     */
    public function emailOtpResendCode(){
        $email          = session()->get('register_data.credentials');;
        $resend         = AgentAuthorization::where("email",$email)->first();
        if( $resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                throw ValidationException::withMessages([
                    'code'      => __('You can resend the verification code after').' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_PASS_RESEND_TIME_MINUTE)). ' '. __('seconds'),
                ]);
            }
        }

        $code = generate_random_code();
        $data = [
            'agent_id'       =>  0,
            'email'         => $email,
            'code'          => $code,
            'token'         => generate_unique_string("agent_authorizations","token",200),
            'created_at'    => now(),
        ];
        DB::beginTransaction();
        try{
            $oldToken = AgentAuthorization::where("email",$email)->get();
            if($oldToken){
                foreach($oldToken as $token){
                    $token->delete();
                }
            }
            DB::table("agent_authorizations")->insert($data);
            Notification::route("mail",$email)->notify(new SendVerifyCode($email, $code));
            DB::commit(); 
            
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
        }
        return redirect()->route('agent.email.send.otp.code',$data['token'])->with(['success' => [__('Verification code resend success')]]);
    }
    /**
     * Method for show agent register page
     */
    public function registerKyc(Request $request){
        $basic_settings   = $this->basic_settings;
        $credentials      = session()->get('register_data.credentials');
        $register_type    = session()->get('register_data.register_type');
        
        if($credentials == null){
            return redirect()->route('agent.register');
        }
        $kyc_fields =[];
        if($basic_settings->agent_kyc_verification == true){
            $user_kyc = SetupKyc::agentKyc()->first();
            if(!$user_kyc) return back();
            $kyc_data = $user_kyc->fields;
            $kyc_fields = [];
            if($kyc_data) {
                $kyc_fields = array_reverse($kyc_data);
            }
        }
        $page_title = __("Agent Registration KYC");
        return view('agent.auth.register-kyc',compact(
            'page_title',
            'credentials',
            'register_type',
            'kyc_fields'
        ));
    }
    //========================before registration======================================

    /**
     * Handle a registration request for the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function register(Request $request){
        $basic_settings             = $this->basic_settings;
        $validated = $this->validator($request->all())->validate();
        if($basic_settings->agent_kyc_verification == true){
            $user_kyc_fields = SetupKyc::agentKyc()->first()->fields ?? [];
            $validation_rules = $this->generateValidationRules($user_kyc_fields);
            $kyc_validated = Validator::make($request->all(),$validation_rules)->validate();
            $get_values = $this->registerPlaceValueWithFields($user_kyc_fields,$kyc_validated);
        }else{
            $get_values     = [];
        }
        if(User::where('username',$validated['username'])->exists()){
            throw ValidationException::withMessages([
                'username' => _("The username has already been taken.")
            ]);
        }
        if(Agent::where('username',$validated['username'])->exists()){
            throw ValidationException::withMessages([
                'username' => _("The username has already been taken.")
            ]);
        }
        if(Merchant::where('username',$validated['username'])->exists()){
            throw ValidationException::withMessages([
                'username' => _("The username has already been taken.")
            ]);
        }
        $register_data      = session()->get('register_data');

        if($register_data != null && $register_data['sms_verified'] == true && $register_data['email_verified'] == false){
            
    
            $validated['mobile']        = remove_speacial_char($validated['phone']);
            $complete_phone             = $validated['mobile'];
    
            if(User::where('full_mobile',$complete_phone)->exists()) {
                throw ValidationException::withMessages([
                    'phone'     => __('Phone number is already exists'),
                ]);
            }
            if(Agent::where('full_mobile',$complete_phone)->exists()) {
                throw ValidationException::withMessages([
                    'phone'     => __('Phone number is already exists in agent.'),
                ]);
            }
            if(Merchant::where('full_mobile',$complete_phone)->exists()) {
                throw ValidationException::withMessages([
                    'phone'     => __('Phone number is already exists in merchant.'),
                ]);
            }
            $userName = $validated['username'];
            $validated['full_mobile']       = $complete_phone;
            $validated = Arr::except($validated,['agree']);
            $sms_verified                   = session()->get('register_data.sms_verified');

            $validated['email_verified']    = $register_data['email_verified'];
            $validated['sms_verified']      = $sms_verified;
            $validated['kyc_verified']      = ($basic_settings->agent_kyc_verification == true) ? false : true;
            $validated['password']          = Hash::make($validated['password']);
            $validated['username']          = $userName;
            $validated['address']           = [
                                                'country' => $validated['country'],
                                                'city' => $validated['city'],
                                                'zip' => $validated['zip_code'],
                                                'state' => '',
                                                'address' => '',
                                            ];
    
    
            if($validated['email'] != '' || $validated['email'] != null){
                if(User::where('email',$request->email)->exists()) {
                    throw ValidationException::withMessages([
                        'email'     => __('Email is already exists'),
                    ]);
                }
                if(Agent::where('email',$request->email)->exists()) {
                    throw ValidationException::withMessages([
                        'email'     => __('Email is already exists in agent.'),
                    ]);
                }
                if(Merchant::where('email',$request->email)->exists()) {
                    throw ValidationException::withMessages([
                        'email'     => __('Email is already exists in merchant.'),
                    ]);
                }
    
                if($basic_settings->agent_email_verification == true){
                
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
                        
                        DB::table("agent_authorizations")->insert($data);
                        Session::put('register_data',[
                            'validated'     => $validated,
                            'get_values'     => $get_values
                        ]);
                        if($basic_settings->agent_email_notification == true && $basic_settings->agent_email_verification == true){
                            Notification::route("mail",$validated['email'])->notify(new SendVerifyCode($validated['email'], $code));
                        }
                        DB::commit();
                    }catch(Exception $e) {
                        DB::rollBack();
                        return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
                    };
                    return redirect()->route('agent.email.verify',$data['token'])->with(['success' => [__('Verification code sended to your email address.')]]);
                }
            }else{
                $data = event(new Registered($user = $this->create($validated)));
                
                if( $data && $basic_settings->agent_kyc_verification == true){
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
            
                        return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
                    }
         
                }
                $request->session()->forget('register_info');
                $this->guard()->login($user);
         
                return $this->registered($request, $user);
            }
             
        }else if($register_data != null && $register_data['sms_verified'] == false && $register_data['email_verified'] == true){
            
            
            $userName = $validated['username'];
            $validated = Arr::except($validated,['agree']);
            $email_verified                   = session()->get('register_data.email_verified');
            if($validated['phone'] == '' || $validated['phone'] == null){
                $validated['sms_verified']    = $register_data['sms_verified'];
            }else{
                
                $validated['sms_verified']    = false;
                $validated['mobile']        = remove_speacial_char($validated['phone']);
                $complete_phone             = $validated['mobile'];
                if(User::where('full_mobile',$complete_phone)->exists()) {
                    throw ValidationException::withMessages([
                        'phone'     => __('Phone number is already exists'),
                    ]);
                }
                if(Agent::where('full_mobile',$complete_phone)->exists()) {
                    throw ValidationException::withMessages([
                        'phone'     => __('Phone number is already exists in agent.'),
                    ]);
                }
                if(Merchant::where('full_mobile',$complete_phone)->exists()) {
                    throw ValidationException::withMessages([
                        'phone'     => __('Phone number is already exists in merchant.'),
                    ]);
                }
                
                $validated['full_mobile']       = $complete_phone;
            }
            
            $validated['email_verified']    = $email_verified;
            $validated['kyc_verified']      = ($basic_settings->agent_kyc_verification == true) ? false : true;
            $validated['password']          = Hash::make($validated['password']);
            $validated['username']          = $userName;
            $validated['address']           = [
                                                'country' => $validated['country'],
                                                'city' => $validated['city'],
                                                'zip' => $validated['zip_code'],
                                                'state' => '',
                                                'address' => '',
                                            ];
    
                                            
            if($validated['phone'] != '' || $validated['phone'] != null){
                if($basic_settings->agent_sms_verification == true){
                
                    $code = generate_random_code();
                    $data = [
                        'agent_id'       =>  0,
                        'phone'         => $validated['phone'],
                        'code'          => $code,
                        'token'         => generate_unique_string("agent_authorizations","token",200),
                        'created_at'    => now(),
                    ];
                    DB::beginTransaction();
                    try{
                        
                        DB::table("agent_authorizations")->insert($data);
                        Session::put('register_data',[
                            'validated'     => $validated,
                            'get_values'     => $get_values
                        ]);
                        if($basic_settings->agent_sms_notification == true && $basic_settings->agent_sms_verification == true){
                            $message = __("Your verification resend code is :code",['code' => $code]);
                            sendApiSMS($message,$validated['phone']); 
                        }
                        DB::commit();
                    }catch(Exception $e) {
                        
                        DB::rollBack();
                        return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
                    };
                    return redirect()->route('agent.sms.otp.send',$data['token'])->with(['success' => [__('Verification code sended to your phone number.')]]);
                }
            }else{
                $data = event(new Registered($user = $this->create($validated)));
                
                if( $data && $basic_settings->agent_kyc_verification == true){
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
            
                        return back()->with(['error' => [__('Something went wrong! Please try again.')]]);
                    }
         
                }
                $request->session()->forget('register_info');
                $this->guard()->login($user);
         
                return $this->registered($request, $user);
            } 
        }
         
       
    }
    protected function guard()
    {
        return Auth::guard('agent');
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public function validator(array $data) {
        $basic_settings = $this->basic_settings;
        $passowrd_rule = "required|string|min:6|confirmed";
        if($basic_settings->agent_secure_password) {
            $passowrd_rule = ["required","confirmed",Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()];
        }
        if($basic_settings->agent_agree_policy){
            $agree = 'required';
        }else{
            $agree = '';
        }

        $register_data      = session()->get('register_data');
        if($register_data['sms_verified'] == true){
            $phone_field    = 'required';
        }else{
            $phone_field    = 'nullable';
        }
        if($register_data['email_verified'] == true){
            $email_field    = 'required';
        }else{
            $email_field    = 'nullable';
        }

        return Validator::make($data,[
            'firstname'     => 'required|string|max:60',
            'lastname'      => 'required|string|max:60',
            'store_name'    => 'required|string|max:100',
            'email'         => $email_field,
            'username'      => 'required',
            'password'      =>  $passowrd_rule,
            'country'       => 'required|string|max:150',
            'city'          => 'required|string|max:150',
            'phone'         => $phone_field,
            'zip_code'      => 'required|string|max:8',
            'agree'         =>  $agree,
        ]);
    }


    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return \App\Models\User
     */
    protected function create(array $data)
    {
        return Agent::create($data);
    }


    /**
     * The user has been registered.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $user
     * @return mixed
     */
    protected function registered(Request $request, $user)
    {
        $user->createQr();
        $this->createUserWallets($user);
        return redirect()->intended(route('agent.dashboard'));
    }
}
