<?php

namespace App\Http\Controllers\Agent;

use Exception;
use Carbon\Carbon;
use App\Models\User;
use App\Models\UserWallet;
use App\Models\AgentWallet;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Models\UserNotification;
use App\Models\AgentNotification;
use Illuminate\Support\Facades\DB;
use App\Models\Admin\BasicSettings;
use App\Constants\NotificationConst;
use App\Http\Controllers\Controller;
use App\Constants\PaymentGatewayConst;
use App\Models\Admin\AdminNotification;
use App\Models\Admin\TransactionSetting;
use Illuminate\Support\Facades\Validator;
use App\Notifications\Agent\MoneyIn\SenderMail;
use App\Notifications\Agent\MoneyIn\ReceiverMail;

class MoneyInController extends Controller
{
    protected  $trx_id;
    public function __construct()
    {
        $this->trx_id = 'MI'.getTrxNum();
    }

    public function index() {
        $page_title = __("Money In");
        $moneyInCharge = TransactionSetting::where('slug','money-in')->where('status',1)->first();
        $transactions = Transaction::agentAuth()->moneyIn()->latest()->take(10)->get();
        return view('agent.sections.money-in.index',compact("page_title",'moneyInCharge','transactions'));
    }
    public function checkUser(Request $request){
        $email = $request->email;
        $exist['data'] = User::where('email',$email)->first();

        $user = auth()->user();
        if(@$exist['data'] && $user->email == @$exist['data']->email){
            return response()->json(['own'=>__("Can't Money-In to your own")]);
        }
        return response($exist);
    }
    public function confirmedMoneyIn(Request $request){
        $validated = Validator::make($request->all(),[
            'amount' => 'required|numeric|gt:0',
            'email' => 'required|email'
        ])->validate();
        $basic_setting = BasicSettings::first();

        $sender_wallet = AgentWallet::auth()->active()->first();
        if(!$sender_wallet){
            return back()->with(['error' => [__('Agent wallet not found')]]);
        }
        if( $sender_wallet->agent->email == $validated['email']){
            return back()->with(['error' => [__("Can't Money-In to your own")]]);
        }
        $field_name = "username";
        if(check_email($validated['email'])) {
            $field_name = "email";
        }
        $receiver = User::where($field_name,$validated['email'])->active()->first();
        if(!$receiver){
            return back()->with(['error' => [__("Receiver doesn't exists or Receiver is temporary banned")]]);
        }
        $receiver_wallet = UserWallet::where("user_id",$receiver->id)->first();

        if(!$receiver_wallet){
            return back()->with(['error' => [__("Receiver wallet not found")]]);
        }

        $trx_charges =  TransactionSetting::where('slug','money-in')->where('status',1)->first();
        $charges = $this->moneyInCharge($validated['amount'],$trx_charges,$sender_wallet,$receiver->wallet->currency);

        $sender_currency_rate = $sender_wallet->currency->rate;
        $min_amount = $trx_charges->min_limit * $sender_currency_rate;
        $max_amount = $trx_charges->max_limit * $sender_currency_rate;

        if($charges['sender_amount'] < $min_amount || $charges['sender_amount'] > $max_amount) {
            return back()->with(['error' => [__("Please follow the transaction limit")]]);
        }
        if($charges['payable'] > $sender_wallet->balance) {
            return back()->with(['error' => [__("Sorry, insufficient balance")]]);
         }
        try{
            $trx_id = $this->trx_id;
            $sender = $this->insertSender($trx_id,$sender_wallet,$charges,$receiver_wallet);
            if($sender){
                 $this->insertSenderCharges($sender,$charges,$sender_wallet,$receiver_wallet);

                try{
                    if( $basic_setting->agent_sms_notification == true){
                        
                        $message = __("Money In" . " "  . get_amount($charges['sender_amount'],4) . ' ' . $charges['sender_currency'] .  ", to " . @$receiver_wallet->user->username . " " . "Transaction ID: " . $trx_id . ' ' . "Date : " . Carbon::now()->format('Y-m-d'));
                       sendApiSMS($message,@$sender_wallet->agent->full_mobile);
                        
                    }
                }catch(Exception $e){
                    //Error Handle
                }
            }

            $receiverTrans = $this->insertReceiver($trx_id, $sender_wallet,$charges,$receiver_wallet);
            if($receiverTrans){
                 $this->insertReceiverCharges($receiverTrans,$charges,$sender_wallet,$receiver_wallet);
                 //Receiver notifications
                try{
                    if( $basic_setting->agent_sms_notification == true){
                        
                        $message = __("Money In" . " "  . get_amount($charges['receiver_amount']) . ' ' . $charges['receiver_currency'] .  ", From " . @$sender_wallet->agent->username . " " . "Transaction ID: " . $trx_id . ' ' . "Date : " . Carbon::now()->format('Y-m-d'));
                       sendApiSMS($message,@$receiver_wallet->user->full_mobile);
                        
                    }
                }catch(Exception $e){
                //Error Handle
                }
            }
            return back()->with(['success' => [__("Money In Request Successful")]]);
        }catch(Exception $e) {
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }

    }
    //sender transaction
    public function insertSender($trx_id,$sender_wallet,$charges,$receiver_wallet) {
        $trx_id = $trx_id;
        $authWallet = $sender_wallet;
        $afterCharge = ($authWallet->balance - $charges['payable']) + $charges['agent_total_commission'];

        DB::beginTransaction();
        try{
            $id = DB::table("transactions")->insertGetId([
                'agent_id'                      => $sender_wallet->agent->id,
                'agent_wallet_id'               => $sender_wallet->id,
                'payment_gateway_currency_id'   => null,
                'type'                          => PaymentGatewayConst::MONEYIN,
                'trx_id'                        => $trx_id,
                'request_amount'                => $charges['sender_amount'],
                'payable'                       => $charges['payable'],
                'available_balance'             => $afterCharge,
                'remark'                        => ucwords(remove_speacial_char(PaymentGatewayConst::MONEYIN," ")) . " To " .$receiver_wallet->user->fullname,
                'details'                       => json_encode([
                                                        'receiver_username'=> $receiver_wallet->user->username,
                                                        'receiver_email'=> $receiver_wallet->user->email,
                                                        'sender_username'=> $sender_wallet->agent->username,
                                                        'sender_email'=> $sender_wallet->agent->email,
                                                        'charges' => $charges
                                                    ]),
                'attribute'                      =>PaymentGatewayConst::SEND,
                'status'                        => GlobalConst::SUCCESS,
                'created_at'                    => now(),
            ]);
            $this->updateSenderWalletBalance($authWallet,$afterCharge);
            $this->agentProfitInsert($id,$authWallet,$charges);

            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }
        return $id;
    }
    public function agentProfitInsert($id,$authWallet,$charges) {
        DB::beginTransaction();
        try{
            DB::table('agent_profits')->insert([
                'agent_id'          => $authWallet->agent->id,
                'transaction_id'    => $id,
                'percent_charge'    => $charges['agent_percent_commission'],
                'fixed_charge'      => $charges['agent_fixed_commission'],
                'total_charge'      => $charges['agent_total_commission'],
                'created_at'        => now(),
            ]);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }
    }
    public function updateSenderWalletBalance($authWallet,$afterCharge) {
        $authWallet->update([
            'balance'   => $afterCharge,
        ]);
    }
    public function insertSenderCharges($id,$charges,$sender_wallet,$receiver_wallet) {
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'    => $id,
                'percent_charge'    => $charges['percent_charge'],
                'fixed_charge'      => $charges['fixed_charge'],
                'total_charge'      => $charges['total_charge'],
                'created_at'        => now(),
            ]);
            DB::commit();

            //store notification
            $notification_content = [
                'title'         =>__("Money IN"),
                'message'       => "Money In To  ".$receiver_wallet->user->fullname.' ' .$charges['sender_amount'].' '.$charges['sender_currency']." Successful",
                'image'         =>  get_image($sender_wallet->agent->image,'agent-profile'),
            ];
            AgentNotification::create([
                'type'      => NotificationConst::MONEYIN,
                'agent_id'  => $sender_wallet->agent->id,
                'message'   => $notification_content,
            ]);

            //admin create notifications
            $notification_content['title'] = __('Money In To').' ('.$receiver_wallet->user->username.')';
            AdminNotification::create([
                'type'      => NotificationConst::MONEYIN,
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
    public function insertReceiver($trx_id,$sender_wallet,$charges,$receiver_wallet) {
        $trx_id = $trx_id;
        $receiverWallet = $receiver_wallet;
        $recipient_amount = ($receiverWallet->balance + $charges['receiver_amount']);

        DB::beginTransaction();
        try{
            $id = DB::table("transactions")->insertGetId([
                'user_id'                       => $receiver_wallet->user->id,
                'user_wallet_id'                => $receiver_wallet->id,
                'payment_gateway_currency_id'   => null,
                'type'                          => PaymentGatewayConst::MONEYIN,
                'trx_id'                        => $trx_id,
                'request_amount'                => $charges['receiver_amount'],
                'payable'                       => $charges['receiver_amount'],
                'available_balance'             => $receiver_wallet->balance + $charges['receiver_amount'],
                'remark'                        => ucwords(remove_speacial_char(PaymentGatewayConst::MONEYIN," ")) . " From " .$sender_wallet->agent->fullname,
                'details'                       => json_encode([
                                                            'receiver_username'=> $receiver_wallet->user->username,
                                                            'receiver_email'=> $receiver_wallet->user->email,
                                                            'sender_username'=> $sender_wallet->agent->username,
                                                            'sender_email'=> $sender_wallet->agent->email,
                                                            'charges' => $charges
                                                        ]),
                'attribute'                     =>PaymentGatewayConst::RECEIVED,
                'status'                        => GlobalConst::SUCCESS,
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
    public function insertReceiverCharges($id,$charges,$sender_wallet,$receiver_wallet) {
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
                'title'         =>__("Money In"),
                'message'       => "Money In From  ".$sender_wallet->agent->fullname.' ' .$charges['receiver_amount'].' '.$charges['receiver_currency']." Successful",
                'image'         => get_image($receiver_wallet->user->image,'user-profile'),
            ];
            UserNotification::create([
                'type'      => NotificationConst::MONEYIN,
                'user_id'   => $receiver_wallet->user->id,
                'message'   => $notification_content,
            ]);
            DB::commit();

            //admin notification
            $notification_content['title'] = __('Money In From').' ('.$sender_wallet->agent->username.')';
            $data = AdminNotification::create([
                'type'      => NotificationConst::MONEYIN,
                'admin_id'  => 1,
                'message'   => $notification_content,
            ]);


        }catch(Exception $e) {
            DB::rollBack();
            return back()->with(['error' => [__("Something went wrong! Please try again.")]]);
        }
    }
    public function moneyInCharge($sender_amount,$charges,$sender_wallet,$receiver_currency) {
        $exchange_rate = $receiver_currency->rate / $sender_wallet->currency->rate;

        $data['exchange_rate']                      = $exchange_rate;
        $data['sender_amount']                      = $sender_amount;
        $data['sender_currency']                    = $sender_wallet->currency->code;
        $data['receiver_amount']                    = $sender_amount * $exchange_rate;
        $data['receiver_currency']                  = $receiver_currency->code;
        $data['percent_charge']                     = ($sender_amount / 100) * $charges->percent_charge ?? 0;
        $data['fixed_charge']                       = $sender_wallet->currency->rate * $charges->fixed_charge ?? 0;
        $data['total_charge']                       = $data['percent_charge'] + $data['fixed_charge'];
        $data['sender_wallet_balance']              = $sender_wallet->balance;
        $data['payable']                            = $sender_amount + $data['total_charge'];
        $data['agent_percent_commission']           = ($sender_amount / 100) * $charges->agent_percent_commissions ?? 0;
        $data['agent_fixed_commission']             = $sender_wallet->currency->rate * $charges->agent_fixed_commissions ?? 0;
        $data['agent_total_commission']             = $data['agent_percent_commission'] + $data['agent_fixed_commission'];
        return $data;
    }
}
