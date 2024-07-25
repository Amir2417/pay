<?php

namespace App\Http\Controllers\Agent;

use Exception;
use Carbon\Carbon;
use App\Models\Agent;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Models\Admin\SetupKyc;
use App\Models\AgentAuthorization;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use App\Providers\Admin\BasicSettingsProvider;
use Illuminate\Validation\ValidationException;
use App\Notifications\Merchant\Auth\SendAuthorizationCode as AuthSendAuthorizationCode;
use App\Notifications\Agent\Auth\SendAuthorizationCode as AgentAuthSendAuthorizationCode;

class ProfileController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $page_title = __("Agent Profile");
        $kyc_data = SetupKyc::agentKyc()->first();
        return view('agent.sections.profile.index',compact("page_title","kyc_data"));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $validated = Validator::make($request->all(),[
            'firstname'     => "required|string|max:60",
            'lastname'      => "required|string|max:60",
            'store_name'    => "required|string|max:100",
            'email'         => "required|string|max:60",
            'country'       => "required|string|max:50",
            'state'         => "nullable|string|max:50",
            'city'          => "nullable|string|max:50",
            'zip_code'      => "nullable|string",
            'address'       => "nullable|string|max:250",
            'image'         => "nullable|image|mimes:jpg,png,svg,webp|max:10240",
        ])->validate();

       
        $validated                  = Arr::except($validated,['agree']);
        $validated['address']       = [
            'country'   =>$validated['country'],
            'state'     => $validated['state'] ?? "",
            'city'      => $validated['city'] ?? "",
            'zip'       => $validated['zip_code'] ?? "",
            'address'   => $validated['address'] ?? "",
        ];

        if($request->hasFile("image")) {
            $image = upload_file($validated['image'],'agent-profile',auth()->user()->image);
            $upload_image = upload_files_from_path_dynamic([$image['dev_path']],'agent-profile');
            delete_file($image['dev_path']);
            $validated['image']     = $upload_image;
        }

        if(Agent::whereNot('id',auth()->user()->id)->where('email',$validated['email'])->exists()){
            throw ValidationException::withMessages([
                'email'     => __('The email has already been taken.'),
            ]);
        }
        if($validated['email']  != auth()->user()->email) {
            Session::put('profile_update',[
                'data'          => $validated,
                'user'          => auth()->user(),
            ]);
            $basic_settings = BasicSettingsProvider::get();
            $agent              = auth()->user();
            $data = [
                'agent_id'       => $agent->id,
                'code'          => generate_random_code(),
                'token'         => generate_unique_string("agent_authorizations","token",200),
                'created_at'    => now(),
            ];

            DB::beginTransaction();
            try{
                if( $basic_settings->agent_email_verification == true){
                    $agent->notify(new AgentAuthSendAuthorizationCode((object) $data));
                }
                AgentAuthorization::where("agent_id",$agent->id)->delete();
                DB::table("agent_authorizations")->insert($data);

                DB::commit();
            }catch(Exception $e) {
                DB::rollBack();
                return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
            }

            return redirect()->route('agent.profile.email',$data['token'])->with(['warning' => [__("Please verify your mail address. Check your mail inbox to get verification code")]]);
        }
        try{
            auth()->user()->update($validated);
        }catch(Exception $e) {
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }

        return back()->with(['success' => [__('Profile successfully updated!')]]);
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function showEmailFrom($token)
    {
        $page_title = __("Email Authorization");
        return view('agent.sections.profile.email-verify',compact("page_title","token"));
    }
    public function resendCode()
    {
        $user = auth()->user();
        $resend = AgentAuthorization::where("agent_id",$user->id)->first();
        if( $resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                throw ValidationException::withMessages([
                    'code'      => __("You can resend the verification code after").' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)). ' '.__('seconds'),
                ]);
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
            AgentAuthorization::where("agent_id",$user->id)->delete();
            DB::table("agent_authorizations")->insert($data);
            $user->notify(new AgentAuthSendAuthorizationCode((object) $data));
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }

        return redirect()->route('agent.profile.email',$data['token'])->with(['success' => [__('Verification code resend success')]]);

    }
    /**
     * Verify authorizaation code.
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function mailVerify(Request $request,$token)
    {
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
            $this->authLogout($request);
            return redirect()->route('index')->with(['error' => [__('Session expired. Please try again')]]);
        }
        try{
            $validated    = session()->get('profile_update.data');
            auth()->user()->update($validated);
            $auth_column->delete();
        }catch(Exception $e) {
            $this->authLogout($request);
            return redirect()->route('agent.login')->with(['error' => [__("Something went wrong! Please try again.")]]);
        }

        return redirect()->intended(route("agent.dashboard"))->with(['success' => [__('Account successfully verified')]]);
    }
    public function passwordUpdate(Request $request) {

        $basic_settings = BasicSettingsProvider::get();
        $passowrd_rule = "required|string|min:6|confirmed";
        if($basic_settings->agent_secure_password) {
            $passowrd_rule = ["required",Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised(),"confirmed"];
        }

        $request->validate([
            'current_password'      => "required|string",
            'password'              => $passowrd_rule,
        ]);

        if(!Hash::check($request->current_password,auth()->user()->password)) {
            throw ValidationException::withMessages([
                'current_password'      => __("Current password didn't match"),
            ]);
        }
        if(Hash::check($request->password,auth()->user()->password)) {
            throw ValidationException::withMessages([
                'password'      => __("You Selected One Of Your Old Password, Please Selected New Password"),
            ]);
        }

        try{
            auth()->user()->update([
                'password'  => Hash::make($request->password),
            ]);
        }catch(Exception $e) {
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }

        return back()->with(['success' => [__('Password successfully updated!')]]);

    }
}
