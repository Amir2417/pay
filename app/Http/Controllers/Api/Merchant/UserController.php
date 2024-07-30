<?php

namespace App\Http\Controllers\Api\Merchant;

use Exception;
use Carbon\Carbon;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Http\Helpers\Api\Helpers;
use App\Models\Merchants\Merchant;
use Illuminate\Support\Facades\DB;
use App\Models\Admin\ModuleSetting;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use App\Constants\PaymentGatewayConst;
use App\Models\Merchants\MerchantWallet;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Notification;
use App\Models\Merchants\MerchantNotification;
use App\Providers\Admin\BasicSettingsProvider;
use App\Models\Merchants\MerchantAuthorization;
use App\Notifications\User\Auth\ProfileUpdateCode;
use App\Notifications\Merchant\Auth\SendAuthorizationCode;


class UserController extends Controller
{

    public function home(){
        $user = auth()->user();
        $money_out_amount = Transaction::merchantAuth()->where('type', PaymentGatewayConst::TYPEMONEYOUT)->where('status', 1)->sum('request_amount');
        $receive_money = Transaction::merchantAuth()->where('type', PaymentGatewayConst::TYPEMAKEPAYMENT)->where('status', 1)->where('attribute','RECEIVED')->sum('request_amount');
        $gateway_amount = Transaction::merchantAuth()->where('type', PaymentGatewayConst::MERCHANTPAYMENT)->where('status', 1)->where('attribute','RECEIVED')->sum('request_amount');
        $total_transaction = Transaction::merchantAuth()->where('status', 1)->count();
        $userWallet = MerchantWallet::where('merchant_id',$user->id)->get()->map(function($data){
            return[
                'balance' => getAmount($data->balance,2),
                'currency' => get_default_currency_code(),
            ];
        })->first();
        $transactions = Transaction::merchantAuth()->latest()->take(5)->get()->map(function($item){
            $statusInfo = [
                "success" =>      1,
                "pending" =>      2,
                "rejected" =>     3,
            ];
            if($item->type == payment_gateway_const()::TYPEMONEYOUT){
                return[
                    'id' => $item->id,
                    'type' =>$item->attribute,
                    'trx' => $item->trx_id,
                    'transaction_type' => $item->type,
                    'request_amount' => getAmount($item->request_amount,2).' '.get_default_currency_code() ,
                    'payable' => getAmount($item->payable,2).' '.@$item->currency->currency_code,
                    'status' => $item->stringStatus->value ,
                    'remark' => $item->remark??"",
                    'date_time' => $item->created_at ,
                ];

            }elseif($item->type == payment_gateway_const()::MERCHANTPAYMENT){
                return[
                    'id' => $item->id,
                    'type' =>$item->attribute,
                    'trx' => $item->trx_id,
                    'transaction_type' => $item->type,
                    'request_amount' => getAmount($item->request_amount,2).' '.get_default_currency_code() ,
                    'payable' => getAmount($item->payable,2).' '.get_default_currency_code(),
                    'status' => $item->stringStatus->value ,
                    'remark' => $item->remark??"",
                    'date_time' => $item->created_at ,

                ];

            }elseif($item->type == payment_gateway_const()::TYPEADDSUBTRACTBALANCE){
                return[
                    'id' => $item->id,
                    'type' =>$item->attribute,
                    'trx' => $item->trx_id,
                    'transaction_type' => $item->type,
                    'request_amount' => get_transaction_numeric_attribute($item->attribute).getAmount($item->request_amount,2).' '.get_default_currency_code(),
                    'payable' => getAmount($item->payable,2).' '.get_default_currency_code(),
                    'status' => $item->stringStatus->value ,
                    'remark' => $item->remark,
                    'date_time' => $item->created_at ,

                ];

            }elseif($item->type == payment_gateway_const()::TYPEMAKEPAYMENT){
                if($item->attribute == payment_gateway_const()::SEND){
                    return[
                        'id' => @$item->id,
                        'type' =>$item->attribute,
                        'trx' => @$item->trx_id,
                        'transaction_type' => $item->type,
                        'request_amount' => getAmount(@$item->request_amount,2).' '.get_default_currency_code() ,
                        'payable' => getAmount(@$item->payable,2).' '.get_default_currency_code(),
                        'status' => @$item->stringStatus->value ,
                        'remark' => $item->remark??"",
                        'date_time' => @$item->created_at ,

                    ];
                }elseif($item->attribute == payment_gateway_const()::RECEIVED){
                    return[
                        'id' => @$item->id,
                        'type' =>$item->attribute,
                        'trx' => @$item->trx_id,
                        'transaction_type' => $item->type,
                        'request_amount' => getAmount(@$item->request_amount,2).' '.get_default_currency_code() ,
                        'payable' => getAmount(@$item->payable,2).' '.get_default_currency_code(),
                        'status' => @$item->stringStatus->value ,
                        'remark' => $item->remark??"",
                        'date_time' => @$item->created_at ,

                    ];

                }

            }elseif($item->type == payment_gateway_const()::TYPEPAYLINK){
                return[
                    'id' => $item->id,
                    'type' =>$item->attribute,
                    'trx' => $item->trx_id,
                    'transaction_type' => $item->type,
                    'request_amount' => getAmount($item->request_amount,2).' '.@$item->details->charge_calculation->sender_cur_code,
                    'payable' => getAmount(@$item->details->charge_calculation->conversion_payable,2).' '.@$item->details->charge_calculation->receiver_currency_code,
                    'remark' => $item->remark??"",
                    'status' => $item->stringStatus->value ,
                    'date_time' => $item->created_at ,
                ];
            }elseif($item->type == payment_gateway_const()::BULKMONEYTRANSFER){
                return[
                    'id' => $item->id,
                    'type' =>$item->attribute,
                    'trx' => $item->trx_id,
                    'transaction_type' => $item->type,
                    'request_amount' => getAmount($item->request_amount,2).' '. get_default_currency_code(),
                    'payable' => getAmount(@$item->details->charge_calculation->conversion_payable,2).' '.get_default_currency_code(),
                    'remark' => $item->remark??"",
                    'status' => $item->stringStatus->value ,
                    'date_time' => $item->created_at ,
                ];
            }


        });

        $module_access =[
            'receive_money' => module_access_merchant_api('merchant-receive-money'),
            'withdraw_money' => module_access_merchant_api('merchant-withdraw-money'),
            'developer_api_key' => module_access_merchant_api('merchant-api-key'),
            'gateway_setting' => module_access_merchant_api('merchant-gateway-settings'),
            'pay_link' => module_access_merchant_api('merchant-pay-link')
        ];

        $data =[
        'base_curr'    => get_default_currency_code(),
        'module_access'    => (object)$module_access,
        'userWallet'   =>   (object)$userWallet,
        'default_image'    => "public/backend/images/default/profile-default.webp",
        "image_path"  =>  "public/frontend/merchant",
        'merchant'         =>   $user,
        'totalMoneyOut'   =>  getAmount($money_out_amount,2).' '.get_default_currency_code(),
        'receiveMoney'   =>   getAmount($receive_money,2).' '.get_default_currency_code(),
        'gateway_amount'   =>   getAmount($gateway_amount,2).' '.get_default_currency_code(),
        'total_transaction'   =>  $total_transaction,
        'transactions'   =>   $transactions,
        ];
        $message =  ['success'=>[__('Merchant Dashboard')]];
        return Helpers::success($data,$message);
    }
    public function profile(){
        $user = auth()->user();
        $data =[
            'default_image'    => "public/backend/images/default/profile-default.webp",
            "image_path"  =>  "public/frontend/merchant",
            'merchant'         =>   $user,
        ];
        $message =  ['success'=>[__('Merchant Profile')]];
        return Helpers::success($data,$message);
    }
    public function profileUpdate(Request $request){
        $user = auth()->user();
        $validator = Validator::make($request->all(), [
            'firstname'     => "required|string|max:60",
            'lastname'      => "required|string|max:60",
            'business_name' => "required|string|max:60",
            'email'         => "required|string|max:60",
            'country'       => "required|string|max:50",
            'state'         => "nullable|string|max:50",
            'city'          => "nullable|string|max:50",
            'zip_code'      => "nullable|string",
            'address'       => "nullable|string|max:250",
            'image'         => "nullable|image|mimes:jpg,png,svg,webp|max:10240",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated          = $validator->validate();
        $data = $request->all();
        

        $validated['firstname']         = $data['firstname'];
        $validated['lastname']          = $data['lastname'];
        $validated['business_name']     = $data['business_name'];
        

        $validated['address']       = [
            'country'   =>$data['country']??"",
            'state'     => $data['state'] ?? "",
            'city'      => $data['city'] ?? "",
            'zip'       => $data['zip_code'] ?? "",
            'address'   => $data['address'] ?? "",
        ];


        if($request->hasFile("image")) {
            if($user->image == 'default.png'){
                $oldImage = null;
            }else{
                $oldImage = $user->image;
            }
            $image = upload_file($data['image'],'merchant-profile', $oldImage);
            $upload_image = upload_files_from_path_dynamic([$image['dev_path']],'merchant-profile');
            delete_file($image['dev_path']);
            $validated['image']     = $upload_image;
        }
        if(Merchant::whereNot('id',auth()->user()->id)->where('email',$validated['email'])->exists()) {
            $error = ['error'=>[__("The email address has already been taken.")]];
            return Helpers::error($error);
        }

        if($validated['email'] != auth()->user()->email){
            $user = auth()->user();
            $code = generate_random_code();
            $validated['email_status'] = true;
            $data = [
                'merchant_id'       =>  $user->id,
                'code'          => $code,
                'token'         => generate_unique_string("merchant_authorizations","token",200),
                'created_at'    => now(),
            ];
            DB::beginTransaction();
            try{
                
                DB::table("merchant_authorizations")->insert($data);
                Notification::route("mail",$validated['email'])->notify(new ProfileUpdateCode($validated['email'], $code));
                DB::commit();
                $message =  ['success'=>[__('Verification code sended to your email address.')]];
                return Helpers::success($validated,$message);
            }catch(Exception $e) {
                DB::rollBack();
                $error = ['error'=>[__("Something went wrong! Please try again.")]];
                return Helpers::error($error);
            }
        }


        try{
            $user->update($validated);
        }catch(Exception $e) {
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
        $message =  ['success'=>[__('Profile successfully updated!')]];
        return Helpers::onlysuccess($message);
    }

    public function mailVerify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code'          => 'required|numeric',
            'firstname'     => "required|string|max:60",
            'lastname'      => "required|string|max:60",
            'email'         => "required|string|max:60",
            'country'       => "required|string|max:50",
            'state'         => "nullable|string|max:50",
            'city'          => "nullable|string|max:50",
            'zip_code'      => "nullable|string",
            'address'       => "nullable|string|max:250",
            'image'         => "nullable|image|mimes:jpg,png,svg,webp|max:10240",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated      = $validator->validate();
        $user = auth()->user();
        $code = $request->code;
        $otp_exp_sec = BasicSettingsProvider::get()->otp_exp_seconds ?? GlobalConst::DEFAULT_TOKEN_EXP_SEC;
        $auth_column = MerchantAuthorization::where("merchant_id",$user->id)->where("code",$code)->first();

        if(!$auth_column){
             $error = ['error'=>[__('The verification code does not match')]];
            return Helpers::error($error);
        }
        if($auth_column->created_at->addSeconds($otp_exp_sec) < now()) {
            $error = ['error'=>[__('Time expired. Please try again')]];
            return Helpers::error($error);
        }
        $data = $request->all();
        $validated['address']       = [
            'country'   =>$data['country']??"",
            'state'     => $data['state'] ?? "",
            'city'      => $data['city'] ?? "",
            'zip'       => $data['zip_code'] ?? "",
            'address'   => $data['address'] ?? "",
        ];
        try{
            auth()->user()->update($validated);
            $auth_column->delete();
        }catch(Exception $e) {
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
        $message =  ['success'=>[__('Account successfully verified')]];
        return Helpers::onlysuccess($message);
    }
    public function passwordUpdate(Request $request) {

        $basic_settings = BasicSettingsProvider::get();
        $password_rule = "required|string|min:6|confirmed";
        if($basic_settings->merchant_secure_password) {
            $password_rule = ["required",Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised(),"confirmed"];
        }
        $validator = Validator::make($request->all(), [
            'current_password'      => "required|string",
            'password'              => $password_rule,
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        if(!Hash::check($request->current_password,auth()->user()->password)) {
            $error = ['error'=>[__("Current password didn't match")]];
            return Helpers::error($error);
        }

        try{
            auth()->user()->update([
                'password'  => Hash::make($request->password),
            ]);
        }catch(Exception $e) {
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
        $message =  ['success'=>[__('Password successfully updated!')]];
        return Helpers::onlysuccess($message);

    }
    public function deleteAccount(Request $request) {
        $user = auth()->user();
        $user->status = false;
        $user->email_verified = false;
        $user->kyc_verified = false;
        $user->deleted_at = now();
        $user->save();

        try{
            $user->token()->revoke();
            $message =  ['success'=>[__('Your profile deleted successfully!')]];
            return Helpers::onlysuccess($message);
        }catch(Exception $e) {
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
    }
    public function transactions(){
        $transactions = Transaction::auth()->latest()->get()->map(function($item){
            $statusInfo = [
                "success" =>      1,
                "pending" =>      2,
                "rejected" =>     3,
                ];
                if($item->type == payment_gateway_const()::TYPEADDMONEY){
                return[
                    'id' => $item->id,
                    'trx' => $item->trx_id,
                    'gateway_name' => @$item->currency->name,
                    'transaction_type' => $item->type,
                    'request_amount' => getAmount($item->request_amount,2).' '.get_default_currency_code() ,
                    'payable' => getAmount($item->payable,2).' '.$item->creator_wallet->currency->code,
                    'exchange_rate' => '1 ' .get_default_currency_code().' = '.getAmount($item->currency->rate,2).' '.@$item->currency->currency_code,
                    'total_charge' => getAmount($item->charge->total_charge,2).' '.$item->creator_wallet->currency->code,
                    'current_balance' => getAmount($item->available_balance,2).' '.get_default_currency_code(),
                    'status' => $item->stringStatus->value ,
                    'date_time' => $item->created_at ,
                    'status_info' =>(object)$statusInfo ,
                    'rejection_reason' =>$item->reject_reason??"" ,

                ];
                }elseif($item->type == payment_gateway_const()::VIRTUALCARD){
                return[
                    'id' => $item->id,
                    'trx' => $item->trx_id,
                    'transaction_type' => "Virtual Card".'('. @$item->remark.')',
                    'request_amount' => getAmount($item->request_amount,2).' '.get_default_currency_code() ,
                    'payable' => getAmount($item->payable,2).' '.get_default_currency_code(),
                    'total_charge' => getAmount($item->charge->total_charge,2).' '.get_default_currency_code(),
                    'card_amount' => getAmount(@$item->details->card_info->amount,2).' '.get_default_currency_code(),
                    'card_number' => $item->details->card_info->card_pan,
                    'current_balance' => getAmount($item->available_balance,2).' '.get_default_currency_code(),
                    'status' => $item->stringStatus->value ,
                    'date_time' => $item->created_at ,
                    'status_info' =>(object)$statusInfo ,

                ];
                }

        });
        $data =[
            'base_curr' => get_default_currency_code(),
            'transactions'   => (object)$transactions,
            ];
            $message =  ['success'=>['Fess & Charges']];
            return Helpers::success($data,$message);
    }
    public function notifications(){
        $user = auth()->user();
        $notifications = MerchantNotification::auth()->latest()->get()->map(function($item){
            return[
                'id' => $item->id,
                'type' => $item->type,
                'title' => $item->message->title??"",
                'message' => $item->message->message??"",
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,

            ];
        });
        $data =[
            'notifications'  => $notifications
        ];
        $message =  ['success'=>[__('Merchant Notifications')]];
        return Helpers::success($data,$message);
    }
}
