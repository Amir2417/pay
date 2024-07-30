<?php

namespace App\Http\Controllers\User;

use Exception;
use Carbon\Carbon;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Models\Admin\SetupKyc;
use App\Models\UserAuthorization;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Notification;
use App\Providers\Admin\BasicSettingsProvider;
use Illuminate\Validation\ValidationException;
use App\Notifications\User\Auth\SendVerifyCode;
use App\Notifications\User\Auth\ProfileUpdateCode;
use App\Notifications\User\Auth\SendAuthorizationCode;

class ProfileController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $page_title = __("User Profile");
        $kyc_data = SetupKyc::userKyc()->first();
        return view('user.sections.profile.index',compact("page_title","kyc_data"));
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
            $image = upload_file($validated['image'],'user-profile',auth()->user()->image);
            $upload_image = upload_files_from_path_dynamic([$image['dev_path']],'user-profile');
            delete_file($image['dev_path']);
            $validated['image']     = $upload_image;
        }
        if(User::whereNot('id',auth()->user()->id)->where('email',$validated['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => __('The email has already been taken.'),
            ]);
        }
        if($validated['email']  != auth()->user()->email) {
            Session::put('profile_update',[
                'data'          => $validated,
                'user'          => auth()->user(),
            ]);
            $user               = auth()->user();
            $basic_settings = BasicSettingsProvider::get();
            $code = generate_random_code();
            $data = [
                'user_id'       => $user->id,
                'email'         => $validated['email'],
                'code'          => $code,
                'token'         => generate_unique_string("user_authorizations","token",200),
                'created_at'    => now(),
            ];

            DB::beginTransaction();
            try{

                Notification::route("mail",$validated['email'])->notify(new ProfileUpdateCode($validated['email'], $code));
                DB::table("user_authorizations")->insert($data);
                DB::commit();
            }catch(Exception $e) {
                DB::rollBack();
                return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
            }
            return redirect()->route('user.profile.email',$data['token'])->with(['warning' => [__("Please verify your mail address. Check your mail inbox to get verification code")]]);
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
        return view('user.sections.profile.email-verify',compact("page_title","token"));
    }
    public function resendCode()
    {
        $validated    = session()->get('profile_update.data');
        $user = auth()->user();
        $resend = UserAuthorization::where("email",$validated['email'])->first();
        if( $resend){
            if(Carbon::now() <= $resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)) {
                throw ValidationException::withMessages([
                    'code'      => __("You can resend the verification code after").' '.Carbon::now()->diffInSeconds($resend->created_at->addMinutes(GlobalConst::USER_VERIFY_RESEND_TIME_MINUTE)). ' '.__('seconds'),
                ]);
            }
        }
        $code = generate_random_code();
        $data = [
            'user_id'       => $user->id,
            'email'         => $validated['email'],
            'code'          => $code,
            'token'         => generate_unique_string("user_authorizations","token",200),
            'created_at'    => now(),
        ];

        DB::beginTransaction();
        try{
            UserAuthorization::where("email",$validated['email'])->delete();
            DB::table("user_authorizations")->insert($data);
            Notification::route("mail",$validated['email'])->notify(new ProfileUpdateCode($validated['email'], $code));     
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }

        return redirect()->route('user.profile.email',$data['token'])->with(['success' => [__('Verification code resend success')]]);

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
            'token'     => "required|string|exists:user_authorizations,token",
            'code'      => "required|array",
            'code.*'    => "required|numeric",
        ]);
        $code = $request->code;
        $code = implode("",$code);
        $otp_exp_sec = BasicSettingsProvider::get()->otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = UserAuthorization::where("token",$request->token)->where("code",$code)->first();
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
            return redirect()->route('user.login')->with(['error' => [__("Something went wrong! Please try again.")]]);
        }

        return redirect()->intended(route("user.dashboard"))->with(['success' => [__('Account successfully verified')]]);
    }
    public function passwordUpdate(Request $request) {

        $basic_settings = BasicSettingsProvider::get();
        $passowrd_rule = "required|string|min:6|confirmed";
        if($basic_settings->secure_password) {
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
