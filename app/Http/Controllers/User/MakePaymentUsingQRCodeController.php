<?php

namespace App\Http\Controllers\User;

use Exception;
use Carbon\Carbon;
use App\Models\UserWallet;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Models\UserNotification;
use App\Models\Merchants\QrCodes;
use App\Models\Merchants\Merchant;
use Illuminate\Support\Facades\DB;
use App\Models\Admin\BasicSettings;
use App\Constants\NotificationConst;
use App\Http\Controllers\Controller;
use App\Constants\PaymentGatewayConst;
use App\Models\Admin\AdminNotification;
use App\Models\Admin\TransactionSetting;
use App\Models\Merchants\MerchantWallet;
use Illuminate\Support\Facades\Validator;
use App\Models\Merchants\MerchantNotification;
use App\Events\User\NotificationEvent as UserNotificationEvent;
use App\Events\Merchant\NotificationEvent as MerchantNotificationEvent;

class MakePaymentUsingQRCodeController extends Controller
{
    
    /**
     * Method for view make payment using qrcode page
     * @return view
     */
    public function index(Request $request){
        $page_title             = "Make Payment Using QRCode";
        $data                   = QrCodes::with(['merchant'])
                                    ->where('slug',$request->slug)
                                    ->where('receiver_type',GlobalConst::RECEIVER_TYPE_USER)
                                    ->first();

        if(!$data) return redirect()->route('user.qrcode.index')->with(['error' => ['Sorry! Data not found.']]);

        $transaction_settings   = TransactionSetting::where('slug','make-payment')->first();
        if(!$transaction_settings) return back()->with(['error' => ['Transaction settings data not available!']]);
        $fixed_charge           = $transaction_settings->fixed_charge;
        $percent_charge         = ($data->amount * $transaction_settings->percent_charge) / 100;
        $total_charge           = $fixed_charge + $percent_charge;
        $payable_amount         = $data->amount + $total_charge;

        return view('user.sections.transfer-money.index',compact(
            'page_title',
            'data',
            'total_charge',
            'payable_amount'
        ));
    }
    /**
     * Method for confirm make payment using qrcode 
     * @param $slug
     * @param Illuminate\Http\Request $request
     */
    public function confirm(Request $request,$slug){
        $data           = QrCodes::with(['merchant'])
                            ->where('slug',$slug)
                            ->where('receiver_type',GlobalConst::SENDER_TYPE_USER)
                            ->first();
        if(!$data) return back()->with(['error' => [__('Sorry! Data not found.')]]);
        $validator      = Validator::make($request->all(),[
            'amount'    => 'required',
            'currency'  => 'required'
        ]);
        if($validator->fails()) return back()->withErrors($validator)->withInput($request->all());

        $validated              = $validator->validate();
        $amount                 = $validated['amount'];
        if($data->amount != $amount){
            return back()->with(['error' => ['Request amount and qrcode is not same.you can not change the amount.']]);
        }
        $user_wallet            = UserWallet::where('user_id',auth()->user()->id)->first();
        if(!$user_wallet) return back()->with(['error' => [__('Sorry! Wallet not found.')]]);
        if($user_wallet->balance < $amount) return back()->with([__('Insufficient Balance!')]);

        $receiver               = Merchant::where('username',$data->merchant->username)->first();
        if(!$receiver) return back()->with(['error' => [__('Receiver not found.')]]);
        $receiver_wallet        = MerchantWallet::where('merchant_id',$receiver->id)->first();
        if(!$receiver_wallet) return back()->with(['error' => ['Sorry! Receiver wallet not found.']]);
        
        $transaction_settings   = TransactionSetting::where('slug','make-payment')->first();
        if(!$transaction_settings) return back()->with(['error' => ['Transaction seetings data not available!']]);
        
        if($amount < $transaction_settings->min_limit || $amount > $transaction_settings->max_limit) {
            return back()->with(['error' => ['Please follow the transaction limit.']]);
        }
        
        
        $fixed_charge           = $transaction_settings->fixed_charge;
        $percent_charge         = ($amount * $transaction_settings->percent_charge) / 100;
        $total_charge           = $fixed_charge + $percent_charge;
        $payable_amount         = $amount + $total_charge;
        if($user_wallet->balance < $payable_amount) return back()->with(['error' => ['Insufficient Balance!']]);
        try{
            
            //save sender data
            $insert_sender_record       = $this->insertSenderRecord($amount,$payable_amount,$user_wallet,$receiver);
            if($insert_sender_record){
                $this->insertSenderTransactionCharges($insert_sender_record,$fixed_charge,$percent_charge,$total_charge,$receiver,$amount);
            }

            //save receiver data
            $insert_receiver_record_id      = $this->insertReceiverRecord($amount,$payable_amount,$receiver,$receiver_wallet);
            if($insert_receiver_record_id){
                $this->insertReceiverTransactionCharges($insert_receiver_record_id,$amount,$fixed_charge,$percent_charge,$total_charge,$receiver);
            }
        }catch(Exception $e){
           return back()->with(['error' => ['Something went wrong! Please try again.']]); 
        }
        return redirect()->route('user.qrcode.index')->with(['success' => ["Make Payment Using QRCode Amount  " . get_amount($data->amount,get_default_currency_code()) ." Successfully."]]);

    }
    /**
     * Method for insert transaction record for sender
     */
    function insertSenderRecord($amount,$payable_amount,$user_wallet,$receiver){
        $basic_settings         = BasicSettings::first();
        $trx_id                 = "MP" . getTrxNum();
        $user                   = auth()->user();
        $available_balance      = $user_wallet->balance - $payable_amount;
        $details =[
            'recipient_amount' => $amount,
            'receiver' => $receiver,
        ];
        DB::beginTransaction();
        try{
            $id     = DB::table('transactions')->insertGetId([
                'user_id'                       => auth()->user()->id,
                'user_wallet_id'                => $user_wallet->id,
                'payment_gateway_currency_id'   => null,
                'type'                          => PaymentGatewayConst::TYPEMAKEPAYMENT,
                'trx_id'                        => $trx_id,
                'request_amount'                => $amount,
                'payable'                       => $payable_amount,
                'available_balance'             => $available_balance,
                'remark'                        => ucwords(remove_speacial_char(PaymentGatewayConst::TYPEMAKEPAYMENT," ")) . " To " .$receiver->fullname,
                'details'                       => json_encode($details),
                'attribute'                     => PaymentGatewayConst::SEND,
                'status'                        => PaymentGatewayConst::STATUSSUCCESS,
                'created_at'                    => now(),
            ]);
            $this->updateUserWalletBalance($user_wallet,$available_balance);
            if($basic_settings->sms_notification == true){
                $message = __("Make Payment" . " "  . getAmount($amount,4) . ' ' . get_default_currency_code() . " " . "Transaction ID: " . $trx_id . ' ' . "Date : " . Carbon::now()->format('Y-m-d')) . ' request sent.';
               sendApiSMS($message,@$user->full_mobile);
            }

            DB::commit();
        }catch(Exception $e){
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
        return $id;
    }
    /**
     * Function for update user wallet balance 
     * @param $user_wallet,$available_balance
     */
    function updateUserWalletBalance($user_wallet,$available_balance){
        $user_wallet->update([
            'balance'   => $available_balance,
        ]);
    }
    /**
     * Function for insert sender charges information
     * @param $insert_sender_record_id,$fixed_charge,$percent_charge,$total_charge
     */
    function insertSenderTransactionCharges($transaction_id,$fixed_charge,$percent_charge,$total_charge,$receiver,$amount){
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'        => $transaction_id,
                'fixed_charge'          => $fixed_charge,
                'percent_charge'        => $percent_charge,
                'total_charge'          => $total_charge,
                'created_at'            => now()
            ]);
            DB::commit();
            $user       = auth()->user();
            //notification
            $notification_content = [
                'title'         =>__("Make Payment"),
                'message'       => __("Payment To ")." ".$receiver->fullname.' ' .$amount.' '.get_default_currency_code()." ".__("Successful"),
                'image'         => get_image($user->image,'user-profile'),
            ];

            UserNotification::create([
                'type'      => NotificationConst::MAKE_PAYMENT,
                'user_id'   => $user->id,
                'message'   => $notification_content,
            ]);
            
            //Push Notifications
            event(new UserNotificationEvent($notification_content,$user));
            send_push_notification(["user-".$user->id],[
                'title'     => $notification_content['title'],
                'body'      => $notification_content['message'],
                'icon'      => $notification_content['image'],
            ]);

            //admin notification
            $notification_content['title'] = __("Make Payment to ")." ".$receiver->fullname.' ' .$amount.' '.get_default_currency_code().' '.__("Successful").' ('.$user->username.')';
            AdminNotification::create([
                'type'      => NotificationConst::MAKE_PAYMENT,
                'admin_id'  => 1,
                'message'   => $notification_content,
            ]);
            DB::commit();
        }catch(Exception $e){
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
    }
    /**
     * Function for save receiver transaction information
     * @param $trx_id,$amount,$payable_amount,$receiver,$receiver_wallet
     */
    function insertReceiverRecord($amount,$payable_amount,$receiver,$receiver_wallet){
        $basic_settings         = BasicSettings::first();
        $trx_id                 = "MP" . getTrxNum();
        $available_balance      = $receiver_wallet->balance + $amount;
        $details =[
            'sender_amount' => $amount,
            'sender' => auth()->user(),
        ]; 

        DB::beginTransaction();
        try{
            $id     = DB::table('transactions')->insertGetId([
                'merchant_id'                   => $receiver->id,
                'merchant_wallet_id'            => $receiver_wallet->id,
                'payment_gateway_currency_id'   => null,
                'type'                          => PaymentGatewayConst::TYPEMAKEPAYMENT,
                'trx_id'                        => $trx_id,
                'request_amount'                => $amount,
                'payable'                       => $payable_amount,
                'available_balance'             => $available_balance,
                'remark'                        => ucwords(remove_speacial_char(PaymentGatewayConst::TYPEMAKEPAYMENT," ")) . " From " . auth()->user()->fullname,
                'details'                       => json_encode($details),
                'attribute'                     => PaymentGatewayConst::RECEIVED,
                'status'                        => PaymentGatewayConst::STATUSSUCCESS,
                'created_at'                    => now(),
            ]);
            $this->updateMerchantWalletBalance($receiver_wallet,$available_balance);
            if($basic_settings->merchant_sms_notification == true){
                $message = __("Money received" . " "  . getAmount($amount,4) . ' ' . get_default_currency_code() . " " . "Transaction ID: " . $trx_id . ' ' . "Date : " . Carbon::now()->format('Y-m-d')) . ' request sent.';
               sendApiSMS($message,@$receiver->full_mobile);
            }
            DB::commit();
        }catch(Exception $e){
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
        return $id;
    }
    /**
     * Method for update merchant wallet balance
     * @param $receiver_wallet,$available_balance
     */
    function updateMerchantWalletBalance($receiver_wallet,$available_balance){
        $receiver_wallet->update([
            'balance'       => $available_balance
        ]);
    }
    /**
     * Function for insert transaction charges for receiver
     * @param $insert_receiver_record_id,$amount,$fixed_charge,$percent_charge,$total_charge,$receiver
     */
    function insertReceiverTransactionCharges($transaction_id,$amount,$fixed_charge,$percent_charge,$total_charge,$receiver){
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'    => $transaction_id,
                'percent_charge'    => $percent_charge,
                'fixed_charge'      => $fixed_charge,
                'total_charge'      => $total_charge,
                'created_at'        => now(),
            ]);
            DB::commit();
            $user   = auth()->user();
            //notification
            $notification_content = [
                'title'         =>__("Make Payment"),
                'message'       => __("Payment From")." ".$user->fullname.' ' .$amount.' '.get_default_currency_code()." ".__("Successful"),
                'image'         => get_image($receiver->image,'merchant-profile'),
            ];

            MerchantNotification::create([
                'type'          => NotificationConst::MAKE_PAYMENT,
                'merchant_id'   => $receiver->id,
                'message'       => $notification_content,
            ]);

             //Push Notifications
             event(new MerchantNotificationEvent($notification_content,$receiver));
             send_push_notification(["merchant-".$receiver->id],[
                 'title'     => $notification_content['title'],
                 'body'      => $notification_content['message'],
                 'icon'      => $notification_content['image'],
             ]);

            //admin notification
            $notification_content['title'] = __("Make Payment From")." ".$user->fullname.' ' .$amount.' '.get_default_currency_code().' '.__("Successful").' ('.$receiver->username.')';
            AdminNotification::create([
                'type'      => NotificationConst::MAKE_PAYMENT,
                'admin_id'  => 1,
                'message'   => $notification_content,
            ]);
            DB::commit();
        }catch(Exception $e){
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
    }

}
