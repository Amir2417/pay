<?php

namespace App\Http\Controllers\Api\User;

use Exception;
use Carbon\Carbon;
use App\Models\User;
use App\Models\UserQrCode;
use App\Models\UserWallet;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Http\Helpers\Response;
use App\Models\Admin\Currency;
use App\Models\UserNotification;
use App\Http\Helpers\Api\Helpers;
use Illuminate\Support\Facades\DB;
use App\Models\Admin\BasicSettings;
use App\Constants\NotificationConst;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use App\Constants\PaymentGatewayConst;
use App\Models\Admin\AdminNotification;
use App\Models\Admin\TransactionSetting;
use App\Providers\Admin\CurrencyProvider;
use Illuminate\Support\Facades\Validator;
use App\Notifications\User\RequestMoney\SenderMail;
use App\Notifications\User\RequestMoney\ReceiverMail;

class RequestMoneyController extends Controller
{
    public function index()
    {

        $user = auth()->user();
        $requestMoneyCharge = TransactionSetting::where('slug','request-money')->where('status',1)->get()->map(function($data){
            return[
                'id' => $data->id,
                'slug' => $data->slug,
                'title' => $data->title,
                'fixed_charge' => getAmount($data->fixed_charge,2),
                'percent_charge' => getAmount($data->percent_charge,2),
                'min_limit' => getAmount($data->min_limit,2),
                'max_limit' => getAmount($data->max_limit,2),
                'monthly_limit' => getAmount($data->monthly_limit,2),
                'daily_limit' => getAmount($data->daily_limit,2),
            ];
        })->first();
        $transactions = Transaction::auth()->requestMoney()->latest()->take(10)->get()->map(function($item){
                if($item->attribute == payment_gateway_const()::SEND){
                    return[
                        'id' => $item->id,
                        'title' => "Request Money to @" . $item->details->receiver_username,
                        'trx' => $item->trx_id,
                        'attribute' => $item->attribute,
                        'type' => $item->type,
                        'request_amount' =>  get_amount($item->request_amount,$item->creator_wallet->currency->code),
                        'payable_amount' => '',
                        'total_charge' => "",
                        'will_get' =>  get_transaction_numeric_attribute_request_money($item->attribute).' '.get_amount($item->details->charges->receiver_amount,$item->details->charges->receiver_currency),
                        'status' => $item->stringStatus->value,
                        'remark' => $item->remark??'',
                        'created_at' => $item->created_at,
                    ];
                }elseif($item->attribute == payment_gateway_const()::RECEIVED){
                    return[
                        'id' => $item->id,
                        'title' => "Request Money from @" . $item->details->sender_username,
                        'trx' => $item->trx_id,
                        'attribute' => $item->attribute,
                        'type' => $item->type,
                        'request_amount' => get_amount($item->request_amount,$item->creator_wallet->currency->code),
                        'payable_amount' =>   get_transaction_numeric_attribute_request_money($item->attribute).' '. get_amount($item->payable,$item->creator_wallet->currency->code),
                        'total_charge' => get_amount($item->details->charges->total_charge,$item->creator_wallet->currency->code),
                        'will_get' => "",
                        'status' => $item->stringStatus->value,
                        'remark' => $item->remark??'',
                        'created_at' => $item->created_at,
                    ];

                }

        });
        $userWallet = UserWallet::where('user_id',$user->id)->get()->map(function($data){
            return[
                'balance' => getAmount($data->balance,2),
                'currency' => get_default_currency_code(),
            ];
        })->first();
        $data =[
            'base_curr' => get_default_currency_code(),
            'base_curr_rate' => get_default_currency_rate(),
            'requestMoneyCharge'=> (object)$requestMoneyCharge,
            'userWallet'=>  (object)$userWallet,
            'transactions'   => $transactions,
        ];
        $message =  ['success'=>[__('Request Money Information')]];
        return Helpers::success($data,$message);

    }

    //start submit
    public function submit(Request $request) {
        $validator = Validator::make(request()->all(), [
            'request_amount'    => "required|numeric|gt:0",
            'currency'          => "required|string|exists:currencies,code",
            'phone'             => "required",
            'remark'            => "nullable|string|max:300"
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated =  $validator->validate();
        $basic_setting = BasicSettings::first();
        $sender_wallet = UserWallet::auth()->whereHas("currency",function($q) use ($validated) {
            $q->where("code",$validated['currency'])->active();
        })->active()->first();

        if(!$sender_wallet){
            $error = ['error'=>[__("Your wallet isn't available with currency").' ('.$validated['currency'].')']];
            return Helpers::error($error);
        }
        $receiver_currency = Currency::receiver()->active()->where('code',$validated['currency'])->first();
        $trx_charges = TransactionSetting::where('slug','request-money')->where('status',1)->first();
        $charges = $this->requestMoneyCharge($validated['request_amount'],$trx_charges,$sender_wallet,$receiver_currency);

        // Check transaction limit
        $sender_currency_rate = $sender_wallet->currency->rate;
        $min_amount = $trx_charges->min_limit * $sender_currency_rate;
        $max_amount = $trx_charges->max_limit * $sender_currency_rate;
        if($charges['request_amount'] < $min_amount || $charges['request_amount'] > $max_amount) {
            $error = ['error'=>[__('Please follow the transaction limit').' (Min '.$min_amount . ' ' . $sender_wallet->currency->code .' - Max '.$max_amount. ' ' . $sender_wallet->currency->code . ')']];
            return Helpers::error($error);
        }

        $field_name = "full_mobile";

        $receiver = User::where($field_name,$validated['phone'])->active()->first();
        if(!$receiver){
            $error = ['error'=>[__("Receiver doesn't exists or Receiver is temporary banned")]];
            return Helpers::error($error);
        }
        if($receiver->full_mobile == $sender_wallet->user->full_mobile){
            $error = ['error'=>[__("Can't Request Money To Your Own")]];
            return Helpers::error($error);
        }

        $receiver_wallet = UserWallet::where("user_id",$receiver->id)->whereHas("currency",function($q) use ($receiver_currency){
            $q->receiver()->where("code",$receiver_currency->code);
        })->first();

        if(!$receiver_wallet){
            $error = ['error'=>[__('Receiver wallet not found')]];
            return Helpers::error($error);
        }

        // if($charges['payable'] > $sender_wallet->balance){
        //     $error = ['error'=>[__('Your Wallet Balance Is Insufficient')]];
        //     return Helpers::error($error);
        // }

        DB::beginTransaction();
        try{

            $trx_details = [
                'receiver_username'     => $receiver_wallet->user->username,
                'receiver_email'        => $receiver_wallet->user->email,
                'receiver_fullname'     => $receiver_wallet->user->fullname,
                'sender_username'       => $sender_wallet->user->username,
                'sender_email'          => $sender_wallet->user->email,
                'sender_fullname'       => $sender_wallet->user->fullname,
                'charges'               => $charges,
            ];

            $trx_id = 'RM'.getTrxNum();
            // Sender TRX
            $sender = DB::table("transactions")->insertGetId([
                'user_id'           => $sender_wallet->user->id,
                'user_wallet_id'    => $sender_wallet->id,
                'type'              => PaymentGatewayConst::REQUESTMONEY,
                'trx_id'            => $trx_id,
                'request_amount'    => $charges['request_amount'],
                'payable'           => $charges['request_amount'],
                'available_balance' => $sender_wallet->balance,
                'attribute'         => PaymentGatewayConst::SEND,
                'details'           => json_encode($trx_details),
                'status'            => GlobalConst::PENDING,
                'remark'            => $validated['remark'],
                'created_at'        => now(),
            ]);
            if($sender){
                $this->insertSenderCharges($sender, (object)$charges,$sender_wallet->user,$receiver);
            }

            // Receiver TRX
            $receiverTrans = DB::table("transactions")->insertGetId([
                'user_id'          => $receiver_wallet->user->id,
                'user_wallet_id'   => $receiver_wallet->id,
                'type'              => PaymentGatewayConst::REQUESTMONEY,
                'trx_id'            => $trx_id,
                'request_amount'    => $charges['receiver_amount'],
                'payable'           => $charges['payable'],
                'available_balance' => $receiver_wallet->balance,
                'attribute'         => PaymentGatewayConst::RECEIVED,
                'details'           => json_encode($trx_details),
                'status'            => GlobalConst::PENDING,
                'remark'            => $validated['remark'],
                'created_at'        => now(),
            ]);
            if($receiverTrans){
                $this->insertReceiverCharges($receiverTrans,(object)$charges,$sender_wallet->user,$receiver);
            }
            if( $basic_setting->sms_notification == true){

                //sender notifications
                $message = __("Request Money" . " "  . getAmount($charges['request_amount']) . ' ' . $charges['sender_currency'] .  ", to " . $receiver->fullname . " " . "Transaction ID: " . $trx_id . ' ' . "Date : " . Carbon::now()->format('Y-m-d') . ' request sent to admin.');
               sendApiSMS($message,@$sender_wallet->user->full_mobile);

                //receiver notifications
                $message = __("Request Money" . " "  . getAmount($charges['request_amount']) . ' ' . $charges['sender_currency'] .  ", From " . @$sender_wallet->user->fullname . " " . "Transaction ID: " . $trx_id . ' ' . "Date : " . Carbon::now()->format('Y-m-d') . ' request sent to admin.');
               sendApiSMS($message,@$receiver->full_mobile);
                
            }

            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            $error = ['error'=>[__("Transaction Failed. The record didn't save properly. Please try again")]];
            return Helpers::error($error);

        }
        $message =  ['success'=>[__('request Money Success')]];
        return Helpers::onlysuccess($message);
    }
    //sender charges
    public function insertSenderCharges($id,$charges,$sender,$receiver) {
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'    => $id,
                'percent_charge'    => 0,
                'fixed_charge'      => 0,
                'total_charge'      => 0,
                'created_at'        => now(),
            ]);
            DB::commit();

            //store notification
            $notification_content = [
                'title'         =>__("request Money"),
                'message'       => __('Request Money to')." ".$receiver->fullname.' ' .$charges->request_amount.' '.$charges->sender_currency." ".__("Successful"),
                'image'         =>  get_image($sender->image,'user-profile'),
            ];
            UserNotification::create([
                'type'      => NotificationConst::REQUEST_MONEY,
                'user_id'  => $sender->id,
                'message'   => $notification_content,
            ]);

            //admin create notifications
            $notification_content['title'] = __('Request Money Send To').' ('.$receiver->username.')';
            AdminNotification::create([
                'type'      => NotificationConst::REQUEST_MONEY,
                'admin_id'  => 1,
                'message'   => $notification_content,
            ]);
            DB::commit();

        }catch(Exception $e) {
            DB::rollBack();
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
    }
    //receiver Charge
    public function insertReceiverCharges($id,$charges,$sender,$receiver) {
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'    => $id,
                'percent_charge'    => $charges->percent_charge,
                'fixed_charge'      => $charges->fixed_charge,
                'total_charge'      => $charges->total_charge,
                'created_at'        => now(),
            ]);
            DB::commit();

            //store notification
            $notification_content = [
                'title'         =>__("request Money"),
                'message'       => __("Request Money from")." ".$sender->fullname.' ' .$charges->receiver_amount.' '.$charges->receiver_currency." ".__("Successful"),
                'image'         => get_image($receiver->image,'user-profile'),
            ];
            UserNotification::create([
                'type'      => NotificationConst::REQUEST_MONEY,
                'user_id'  => $receiver->id,
                'message'   => $notification_content,
            ]);
            DB::commit();

            //admin notification
            $notification_content['title'] = __('Request Money from').' ('.$sender->username.')';
            AdminNotification::create([
                'type'      => NotificationConst::REQUEST_MONEY,
                'admin_id'  => 1,
                'message'   => $notification_content,
            ]);

        }catch(Exception $e) {
            DB::rollBack();
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
    }
    public function requestMoneyCharge($request_amount,$charges,$sender_wallet,$receiver_currency) {

        $data['request_amount']          = $request_amount;
        $data['sender_currency']        = $sender_wallet->currency->code;
        $data['receiver_amount']        = $request_amount;
        $data['receiver_currency']      = $receiver_currency->code;
        $data['percent_charge']         = ($request_amount / 100) * $charges->percent_charge ?? 0;
        $data['fixed_charge']           = $sender_wallet->currency->rate * $charges->fixed_charge ?? 0;
        $data['total_charge']           = $data['percent_charge'] + $data['fixed_charge'];
        $data['sender_wallet_balance']  = $sender_wallet->balance;
        $data['payable']                = $request_amount + $data['total_charge'];
        return $data;
    }
    public function checkUser(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'phone'     => "required"
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated = $validator->validate();
        
        $field_name = "full_mobile";
        
        $user = User::where($field_name,$validated['phone'])->active()->first();
        if(!$user){
            $error = ['error'=>[__('Invalid User')]];
            return Helpers::error($error);
        }
        if(auth()->user()->full_mobile == $user->full_mobile){
            $error = ['error'=>[__("Can't Request Money To Your Own")]];
            return Helpers::error($error);
        }
        $data =[
            'user_phone'   => $user->full_mobile,
        ];
        $message =  ['success'=>[__('Valid User For Request Money.')]];
        return Helpers::success($data,$message);
    }
    public function qrScan(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'qr_code'     => "required"
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated = $validator->validate();
        $qrCode = UserQrCode::where('qr_code',$request->qr_code)->where(function ($query) {
            $query->whereNull('sender_type')
                  ->orWhere('sender_type', GlobalConst::SENDER_TYPE_USER);
        })
        ->first();
        if(!$qrCode){
            $error = ['error'=>[__('Invalid request')]];
            return Helpers::error($error);
        }
        $user = User::where('id',$qrCode->user_id)->first();
        if(!$user){
            $error = ['error'=>[__('Invalid User')]];
            return Helpers::error($error);
        }
        if(auth()->user()->full_mobile == $user->full_mobile){
            $error = ['error'=>[__("Can't Request Money To Your Own")]];
            return Helpers::error($error);
        }
        $data   = [
            'phone'         => $user->full_mobile,
            'amount'        => $qrCode->amount,
        ];
        $message =  ['success'=>[__('Valid User For Request Money.')]];
        return Helpers::success($data,$message);
    }

     //manage transactions list
    public function logLists()
    {
        $transactions = Transaction::auth()->requestMoney()->orderByDesc("id")->get()->map(function($item){
        if($item->attribute  == PaymentGatewayConst::SEND){
            $title = "Request Money to @" . $item->details->receiver_username;
            $charge = "N/A";
            $payable = "N/A";
            $action = false;
        }else{
            $title = "Request Money from @" . $item->details->sender_username;
            $charge = get_amount($item->details->charges->total_charge,$item->creator_wallet->currency->code);
            $payable = get_transaction_numeric_attribute_request_money($item->attribute).' '.get_amount($item->payable,$item->creator_wallet->currency->code);
            $action = true;
        }
        $status_info = [
            '0' => 'Default',
            '1' =>'Success',
            '2' => 'Pending',
            '4' => 'Rejected'
        ];
        return [
            'id' => $item->id,
            'trx' => $item->trx_id,
            'request_type' => $item->attribute,
            'title' => $title,
            'request_amount' => get_transaction_numeric_attribute_request_money($item->attribute).' '.get_amount($item->request_amount,$item->creator_wallet->currency->code),
            'charge' => $charge,
            'payable' => $payable,
            'status' => $item->status,
            'status_info' => (object)$status_info,
            'action' => $action,
            'created_at' => $item->created_at,

        ];
        });
        $data =[
            'transactions'   => $transactions,
        ];
        $message =  ['success'=>[__('Request Money Transactions')]];
        return Helpers::success($data,$message);
    }
     public function approved(Request $request) {
        $validator = Validator::make($request->all(),[
            'target'     => "required|numeric",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated = $validator->validate();
        $transaction = Transaction::where('id', $validated['target'])->requestMoney()->pending()->where('attribute',PaymentGatewayConst::RECEIVED)->first();
        if(!$transaction){
            $error = ['error'=>['Invalid Transaction Id']];
            return Helpers::error($error);
        }
        //request sender
        $sender = User::where('email',$transaction->details->sender_email)->first();
        $sender_currency =  $transaction->details->charges->sender_currency;
        $sender_wallet = UserWallet::where("user_id",$sender->id)->whereHas("currency",function($q) use ($sender_currency) {
            $q->where("code",$sender_currency)->active();
        })->active()->first();
        if(!$sender_wallet){
            $error = ['error'=>[__("Sender wallet isn't available with currency").' ('.$sender_currency.')']];
            return Helpers::error($error);
        }
        //request receiver
        $receiver = User::where('email',$transaction->details->receiver_email)->first();
        $receiver_currency =  $transaction->details->charges->receiver_currency;
        $receiver_wallet = UserWallet::where("user_id",$receiver->id)->whereHas("currency",function($q) use ($receiver_currency){
            $q->receiver()->where("code",$receiver_currency);
        })->first();
        if(!$receiver_wallet){
            $error = ['error'=>[__("Receiver wallet isn't available with currency").' ('.$receiver_currency.')']];
            return Helpers::error($error);
        }

        //receiver wallet balance check
        if( $transaction->payable > $receiver_wallet->balance){
            $error = ['error'=>[__("Your wallet balance is insufficient")]];
            return Helpers::error($error);
        }
        DB::table($sender_wallet->getTable())->where("id",$sender_wallet->id)->update([
            'balance'           => ($sender_wallet->balance + $transaction->request_amount),
        ]);
        $receiver_wallet->refresh();
        DB::table($receiver_wallet->getTable())->where("id",$receiver_wallet->id)->update([
            'balance'           => ($receiver_wallet->balance - $transaction->payable),
        ]);
        //make rejected now both transactions
        $data =  Transaction::where('trx_id', $transaction->trx_id)->requestMoney()->pending()->get();
        try{
           foreach( $data as $val){
             $val->status = PaymentGatewayConst::STATUSSUCCESS;
             $val->save();
           }
        }catch(Exception $e) {
            $error = ['error'=>[__("Something went wrong! Please try again.")]];
            return Helpers::error($error);
        }
        $message =  ['success'=>[__('Request approved Successfully!')]];
        return Helpers::onlysuccess($message);
    }
    public function rejected(Request $request) {
        $validator = Validator::make($request->all(),[
            'target'     => "required|numeric",
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated = $validator->validate();
        $transaction = Transaction::where('id', $validated['target'])->requestMoney()->pending()->where('attribute',PaymentGatewayConst::RECEIVED)->first();
        if(!$transaction){
            $error = ['error'=>[__('Invalid Transaction Id')]];
            return Helpers::error($error);
        }
        //make rejected now both transactions
        $data =  Transaction::where('trx_id', $transaction->trx_id)->requestMoney()->pending()->get();
        try{
           foreach($data as $val){
             $val->status = PaymentGatewayConst::STATUSREJECTED;
             $val->save();
           }
        }catch(Exception $e) {
            return Response::error([__("Something went wrong! Please try again.")]);
        }
        return Response::success([__('Money Request Rejected Successfully!')]);
    }

}
