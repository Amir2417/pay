<?php

namespace App\Http\Controllers\Agent;

use Exception;
use Carbon\Carbon;
use App\Models\UserWallet;
use App\Models\AgentWallet;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Models\Admin\Currency;
use App\Models\AgentRecipient;
use App\Models\UserNotification;
use App\Models\AgentNotification;
use Illuminate\Support\Facades\DB;
use App\Models\Admin\BasicSettings;
use App\Constants\NotificationConst;
use App\Http\Controllers\Controller;
use App\Models\Admin\ReceiverCounty;
use App\Constants\PaymentGatewayConst;
use App\Models\Admin\AdminNotification;
use Illuminate\Support\Facades\Session;
use App\Models\Admin\TransactionSetting;
use Illuminate\Support\Facades\Validator;
use App\Notifications\Agent\Remittance\SenderEmail;

class RemitanceController extends Controller
{
    protected  $trx_id;
    public function __construct()
    {
        $this->trx_id = 'RT'.getTrxNum();
    }

    public function index() {
        $page_title = "Remittance";
        $exchangeCharge = TransactionSetting::where('slug','remittance')->where('status',1)->first();
        $receiverCountries = ReceiverCounty::active()->get();
        $transactions = Transaction::agentAuth()->remitance()->latest()->take(5)->get();
        return view('agent.sections.remittance.index',compact(
            "page_title",
            'exchangeCharge',
            'receiverCountries',
            'transactions'
        ));
    }
    //confirmed remittance
    public function confirmed(Request $request){
        $validated = Validator::make($request->all(),[
            'form_country'               =>'required',
            'to_country'                 =>'required',
            'transaction_type'           =>'required|string',
            'sender_recipient'                  =>'required',
            'receiver_recipient'           =>'required',
            'send_amount'                =>"required|numeric",
        ])->validate();
        $exchangeCharge = TransactionSetting::where('slug','remittance')->where('status',1)->first();
        $user = authGuardApi()['user'];
        $transaction_type = $validated['transaction_type'];
        $basic_setting = BasicSettings::first();

        $userWallet = AgentWallet::where('agent_id',$user->id)->first();
        if(!$userWallet){
            return back()->with(['error' => [__("Agent doesn't exists.")]]);
        }
        $baseCurrency = Currency::default();
        if(!$baseCurrency){
            return back()->with(['error' => [__('Default currency not found')]]);
        }
        if($baseCurrency->code != $request->form_country){;
            return back()->with(['error' => [__('From country is not a valid country')]]);

        }
        $form_country =  $baseCurrency->country;
        $to_country = ReceiverCounty::where('id',$request->to_country)->first();
        if(!$to_country){
            return back()->with(['error' => [__('Receiver country not found')]]);
        }
        $receipient = AgentRecipient::auth()->sender()->where("id",$request->sender_recipient)->first();
        if(!$receipient){
            return back()->with(['error' => [__('Recipient is invalid/mismatch transaction type or country')]]);
        }
        $receiver_recipient = AgentRecipient::auth()->receiver()->where("id",$request->receiver_recipient)->first();
        if(!$receiver_recipient){
            return back()->with(['error' => [__('Receiver Recipient is invalid')]]);
        }

        $charges = $this->chargeCalculate($userWallet->currency,$receiver_recipient->receiver_country, $validated['send_amount'],$exchangeCharge);
        $sender_currency_rate = $userWallet->currency->rate;
        $min_amount = $exchangeCharge->min_limit * $sender_currency_rate;
        $max_amount = $exchangeCharge->max_limit * $sender_currency_rate;

        if($charges->sender_amount < $min_amount || $charges->sender_amount > $max_amount) {
            return back()->with(['error' => [__('Please follow the transaction limit')]]);
        }

        if($charges->payable > $userWallet->balance) {
            return back()->with(['error' => [__('Sorry, insufficient balance')]]);
        }
        $trx_id = $this->trx_id;
            
        try{
            if($transaction_type === Str::slug(GlobalConst::TRX_WALLET_TO_WALLET_TRANSFER)){
                $receiver_user =  json_decode($receiver_recipient->details);
                $receiver_user_info =  $receiver_user;
                $receiver_user =  $receiver_user->id;
                $receiver_wallet = UserWallet::where('user_id',$receiver_user)->first();
                if(!$receiver_wallet){
                    return back()->with(['error' => [__('Sorry, Receiver wallet not found')]]);
                }

                $sender = $this->insertSender( $trx_id,$userWallet,$receipient,$form_country,$to_country,$transaction_type, $receiver_recipient,$charges);
                if($sender){
                    $this->insertSenderCharges( $sender,$charges,$user,$receiver_recipient);
                    if( $basic_setting->agent_sms_notification == true){
                        $message = __("Send Remittance" . " "  . getAmount($charges->sender_amount,4) . ' ' . $charges->sender_cur_code .  ", to " . @$receiver_recipient->fullname . " " . "Transaction ID: " . $trx_id . ' ' . "Date : " . Carbon::now()->format('Y-m-d'));
                        
                       sendApiSMS($message,@$user->full_mobile);
                    }
                }
                $receiverTrans = $this->insertReceiver($trx_id,$userWallet,$receipient,$form_country,$to_country,$transaction_type,$receiver_user,$receiver_wallet,$receiver_recipient,$charges);
                if($receiverTrans){
                        $this->insertReceiverCharges( $receiverTrans,$charges,$user,$receipient,$receiver_recipient,$receiver_user_info);
                }
                session()->forget('sender_remittance_token');
                session()->forget('receiver_remittance_token');

            }else{
                $trx_id = $this->trx_id;
                $sender = $this->insertSender($trx_id,$userWallet,$receipient,$form_country,$to_country,$transaction_type, $receiver_recipient,$charges);
                if($sender){
                        $this->insertSenderCharges($sender,$charges,$user,$receiver_recipient);
                    if( $basic_setting->agent_sms_notification == true){
                        $message = __("Send Remittance" . " "  . getAmount($charges->sender_amount,4) . ' ' . get_default_currency_code() .  ", to " . $receipient->firstname.' '.@$receipient->lastname . " " . "Transaction ID: " . $trx_id . ' ' . "Date : " . Carbon::now()->format('Y-m-d'));
                        
                       sendApiSMS($message,@$user->full_mobile);
                    }
                    session()->forget('sender_remittance_token');
                    session()->forget('receiver_remittance_token');
                }
            }
            return back()->with(['success' => [__("Remittance Money send successfully")]]);
        }catch(Exception $e) {

            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }

    }
    //sender transaction
    public function insertSender($trx_id,$userWallet,$receipient,$form_country,$to_country,$transaction_type, $receiver_recipient,$charges) {
        $trx_id = $trx_id;
        $authWallet = $userWallet;

        if($transaction_type == Str::slug(GlobalConst::TRX_WALLET_TO_WALLET_TRANSFER)){
            $status = 1;
            $afterCharge = ($authWallet->balance - $charges->payable) + $charges->agent_total_commission;

        }else{
            $status = 2;
            $afterCharge = ($authWallet->balance - $charges->payable);
        }

        $details =[
            'recipient_amount' => $charges->will_get,
            'sender_recipient' => $receipient,
            'receiver_recipient' => $receiver_recipient,
            'form_country' => $form_country,
            'to_country' => $to_country,
            'remitance_type' => $transaction_type,
            'sender' => $userWallet->agent,
            'charges' => $charges,
        ];
        DB::beginTransaction();
        try{
            $id = DB::table("transactions")->insertGetId([
                'agent_id'                      => $userWallet->agent->id,
                'agent_wallet_id'               => $authWallet->id,
                'payment_gateway_currency_id'   => null,
                'type'                          => PaymentGatewayConst::SENDREMITTANCE,
                'trx_id'                        => $trx_id,
                'request_amount'                => $charges->sender_amount,
                'payable'                       => $charges->payable,
                'available_balance'             => $afterCharge,
                'remark'                        => ucwords(remove_speacial_char(PaymentGatewayConst::SENDREMITTANCE," ")) . " To " .$receiver_recipient->fullname,
                'details'                       => json_encode($details),
                'attribute'                      =>PaymentGatewayConst::SEND,
                'status'                        => $status,
                'created_at'                    => now(),
            ]);
            $this->updateSenderWalletBalance($authWallet,$afterCharge);
            if($transaction_type == Str::slug(GlobalConst::TRX_WALLET_TO_WALLET_TRANSFER)){
                $this->agentProfitInsert($id,$authWallet,$charges);
            }

            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }
        return $id;
    }
    public function updateSenderWalletBalance($authWalle,$afterCharge) {
        $authWalle->update([
            'balance'   => $afterCharge,
        ]);
    }
    public function insertSenderCharges($id,$charges,$user,$receiver_recipient) {
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'    => $id,
                'percent_charge'    => $charges->percent_charge,
                'fixed_charge'      =>$charges->fixed_charge,
                'total_charge'      =>$charges->total_charge,
                'created_at'        => now(),
            ]);
            DB::commit();


            //notification
            $notification_content = [
                'title'         =>__("Send Remittance"),
                'message'       => "Send Remittance Request to ".$receiver_recipient->fullname.' ' .$charges->sender_amount.' '.$charges->sender_cur_code." successful",
                'image'         =>  get_image($user->image,'agent-profile'),
            ];


            AgentNotification::create([
                'type'      => NotificationConst::SEND_REMITTANCE,
                'agent_id'  => $user->id,
                'message'   => $notification_content,
            ]);
            //admin notification
                $notification_content['title'] = __('Send Remittance To').' ('.$receiver_recipient->email.')'.' ' .$charges->sender_amount.' '.$charges->sender_cur_code;

                AdminNotification::create([
                    'type'      => NotificationConst::SEND_REMITTANCE,
                    'admin_id'  => 1,
                    'message'   => $notification_content,
                ]);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }
    }
    //Receiver Transaction
    public function insertReceiver($trx_id,$userWallet,$receipient,$form_country,$to_country,$transaction_type,$receiver_user,$receiver_wallet,$receiver_recipient,$charges) {
        $trx_id = $trx_id;
        $receiverWallet = $receiver_wallet;
        $recipient_amount = ($receiverWallet->balance + $charges->will_get);
        $details =[
            'recipient_amount' => $charges->will_get,
            'receiver' => $receiver_recipient,
            'form_country' => $form_country,
            'to_country' => $to_country,
            'remitance_type' => $transaction_type,
            'sender' => $userWallet->agent,
            'charges' => $charges,
        ];
        DB::beginTransaction();
        try{
            $id = DB::table("transactions")->insertGetId([
                'user_id'                       => $receiver_user,
                'user_wallet_id'                => $receiverWallet->id,
                'payment_gateway_currency_id'   => null,
                'type'                          => PaymentGatewayConst::SENDREMITTANCE,
                'trx_id'                        => $trx_id,
                'request_amount'                => $charges->sender_amount,
                'payable'                       => $charges->payable,
                'available_balance'             => $recipient_amount,
                'remark'                        =>  ucwords(remove_speacial_char(PaymentGatewayConst::RECEIVEREMITTANCE," ")) . " From " . $userWallet->agent->username,
                'details'                       => json_encode($details),
                'attribute'                      => PaymentGatewayConst::RECEIVED,
                'status'                        => true,
                'created_at'                    => now(),
            ]);
            $this->updateReceiverWalletBalance($receiverWallet,$recipient_amount);

            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }
        return $id;
    }
    public function updateReceiverWalletBalance($receiverWallet,$recipient_amount) {

        $receiverWallet->update([
            'balance'   => $recipient_amount,
        ]);
    }
    public function insertReceiverCharges( $id,$charges,$user,$receipient,$receiver_recipient,$receiver_user_info) {

        DB::beginTransaction();

        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'    => $id,
                'percent_charge'    => $charges->percent_charge,
                'fixed_charge'      =>$charges->fixed_charge,
                'total_charge'      =>$charges->total_charge,
                'created_at'        => now(),
            ]);
            DB::commit();

            //notification
            $notification_content = [
                'title'         =>__("Send Remittance"),
                'message'       => "Send Remittance from ".$user->fullname.' ' .$charges->will_get.' '.$charges->receiver_cur_code." successful",
                'image'         =>  get_image($receiver_user_info->image,'user-profile'),
            ];

            UserNotification::create([
                'type'      => NotificationConst::SEND_REMITTANCE,
                'user_id'  => $receiver_user_info->id,
                'message'   => $notification_content,
            ]);

                //admin notification
                $notification_content['title'] = __('Send Remittance From').' ('.$user->email.')'.' ' .$charges->will_get.' '.$charges->receiver_cur_code;
                AdminNotification::create([
                    'type'      => NotificationConst::SEND_REMITTANCE,
                    'admin_id'  => 1,
                    'message'   => $notification_content,
                ]);
            DB::commit();
        }catch(Exception $e) {

            DB::rollBack();
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }
    }
    //end transaction helpers

    public function agentProfitInsert($id,$authWallet,$charges) {
        DB::beginTransaction();
        try{
            DB::table('agent_profits')->insert([
                'agent_id'          => $authWallet->agent->id,
                'transaction_id'    => $id,
                'percent_charge'    => $charges->agent_percent_commission,
                'fixed_charge'      => $charges->agent_fixed_commission,
                'total_charge'      => $charges->agent_total_commission,
                'created_at'        => now(),
            ]);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }
    }
    public function chargeCalculate($sender_currency,$receiver_currency,$amount,$exchangeCharge) {
        $amount = $amount;
        $sender_currency_rate = $sender_currency->rate;
        ($sender_currency_rate == "" || $sender_currency_rate == null) ? $sender_currency_rate = 0 : $sender_currency_rate;
        ($amount == "" || $amount == null) ? $amount : $amount;

        if($sender_currency != null) {
            $fixed_charges = $exchangeCharge->fixed_charge;
            $percent_charges = $exchangeCharge->percent_charge;
        }else {
            $fixed_charges = 0;
            $percent_charges = 0;
        }

        $fixed_charge_calc =  $fixed_charges * $sender_currency_rate;
        $percent_charge_calc = ($amount / 100 ) * $percent_charges;
        $total_charge = $fixed_charge_calc + $percent_charge_calc;

        $receiver_currency_rate = $receiver_currency->rate;

        ($receiver_currency_rate == "" || $receiver_currency_rate == null) ? $receiver_currency_rate = 0 : $receiver_currency_rate;
        $exchange_rate = ($receiver_currency_rate / $sender_currency_rate );
        $conversion_amount =  $amount * $exchange_rate;
        $will_get = $conversion_amount;
        $payable =  $amount + $total_charge;

        $agent_percent_commission  = ($amount / 100) * $exchangeCharge->agent_percent_commissions ?? 0;
        $agent_fixed_commission    = $sender_currency_rate * $exchangeCharge->agent_fixed_commissions ?? 0;


        $data = [
            'sender_amount'               => $amount,
            'sender_cur_code'           => $sender_currency->code,
            'sender_cur_rate'           => $sender_currency_rate ?? 0,
            'receiver_cur_code'         => $receiver_currency->code,
            'receiver_cur_rate'         => $receiver_currency->rate ?? 0,
            'fixed_charge'              => $fixed_charge_calc,
            'percent_charge'            => $percent_charge_calc,
            'total_charge'              => $total_charge,
            'conversion_amount'         => $conversion_amount,
            'payable'                   => $payable,
            'exchange_rate'             => $exchange_rate,
            'will_get'                  => $will_get,
            'agent_percent_commission'  => $agent_percent_commission,
            'agent_fixed_commission'    => $agent_fixed_commission,
            'agent_total_commission'    => $agent_percent_commission + $agent_fixed_commission,
            'default_currency'          => get_default_currency_code(),
        ];
        return (object) $data;
    }
    //end transaction helpers




    public function getTokenForSender() {
        $data = request()->all();
        $in['receiver_country'] = $data['receiver_country'];
        $in['transacion_type'] = $data['transacion_type'];
        $in['sender_recipient'] = $data['sender_recipient'];
        $in['receiver_recipient'] = $data['receiver_recipient'];
        $in['sender_amount'] = $data['sender_amount'];
        $in['receive_amount'] = $data['receive_amount'];
        Session::put('sender_remittance_token',$in);
        return response()->json($data);

    }
    public function getTokenForReceiver() {
        $data = request()->all();
        $in['receiver_country'] = $data['receiver_country'];
        $in['transacion_type'] = $data['transacion_type'];
        $in['sender_recipient'] = $data['sender_recipient'];
        $in['receiver_recipient'] = $data['receiver_recipient'];
        $in['sender_amount'] = $data['sender_amount'];
        $in['receive_amount'] = $data['receive_amount'];
        Session::put('receiver_remittance_token',$in);
        return response()->json($data);

    }
    //sender filters
    public function getRecipientByCountry(Request $request){
        $receiver_country = $request->receiver_country;
        $transacion_type = $request->transacion_type;
        if( $transacion_type != null || $transacion_type != ''){
            $data['recipient'] =  AgentRecipient::auth()->sender()->where('type',$transacion_type)->get();

        }else{
            $data['recipient'] =  AgentRecipient::auth()->sender()->get();
        }
        return response()->json($data);
    }
    public function getRecipientByTransType(Request $request){
        $receiver_country = $request->receiver_country;
        $transacion_type = $request->transacion_type;
          $data['recipient'] =  AgentRecipient::auth()->sender()->where('type',$transacion_type)->get();
        return response()->json($data);
    }
    //Receiver filters
    public function getRecipientByCountryReceiver(Request $request){
        $receiver_country = $request->receiver_country;
        $transacion_type = $request->transacion_type;
        if( $transacion_type != null || $transacion_type != ''){
            $data['recipient'] =  AgentRecipient::auth()->receiver()->where('country', $receiver_country)->where('type',$transacion_type)->get();

        }else{
            $data['recipient'] =  AgentRecipient::auth()->receiver()->where('country', $receiver_country)->get();
        }
        return response()->json($data);
    }
    public function getRecipientByTransTypeReceiver(Request $request){
        $receiver_country = $request->receiver_country;
        $transacion_type = $request->transacion_type;
          $data['recipient'] =  AgentRecipient::auth()->receiver()->where('country', $receiver_country)->where('type',$transacion_type)->get();
        return response()->json($data);
    }
}