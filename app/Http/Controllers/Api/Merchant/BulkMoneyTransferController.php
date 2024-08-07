<?php

namespace App\Http\Controllers\Api\Merchant;

use Exception;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Agent;
use App\Models\UserWallet;
use App\Models\AgentWallet;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\TemporaryData;
use App\Constants\GlobalConst;
use App\Models\Admin\Currency;
use App\Models\UserNotification;
use App\Http\Helpers\Api\Helpers;
use App\Models\AgentNotification;
use Illuminate\Support\Facades\DB;
use App\Models\Admin\BasicSettings;
use App\Constants\NotificationConst;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Facades\Excel;
use App\Constants\PaymentGatewayConst;
use App\Models\Admin\AdminNotification;
use App\Imports\BulkMoneyTransferImport;
use App\Models\Admin\TransactionSetting;
use App\Models\Merchants\MerchantWallet;
use Illuminate\Support\Facades\Validator;
use App\Exports\BulkMoneyTransferDataExport;
use Illuminate\Support\Facades\Notification;
use App\Models\Merchants\MerchantNotification;
use App\Notifications\Merchant\BulkMoneyNotification;
use App\Notifications\Merchant\BulkMoneyReceiveNotification;

class BulkMoneyTransferController extends Controller
{
    /**
     * Method for get index data
     * @return response
     */
    public function index(){
        $transactions       = Transaction::with(['merchant','charge'])->where('merchant_id',auth()->user()->id)->where('type',PaymentGatewayConst::BULKMONEYTRANSFER)
                                ->where('attribute',PaymentGatewayConst::SEND)
                                ->orderBy('id','desc')
                                ->get()
                                ->map(function($item){
                                    if($item->status == PaymentGatewayConst::STATUSSUCCESS){
                                        $status     = "Success";
                                    }elseif($item->status == PaymentGatewayConst::STATUSPENDING){
                                        $status     = "Pending";
                                    }elseif($item->status == PaymentGatewayConst::STATUSWAITING){
                                        $status     = "Waiting";
                                    }elseif($item->status == PaymentGatewayConst::STATUSREJECTED){
                                        $status     = "Rejected";
                                    }elseif($item->status == PaymentGatewayConst::STATUSHOLD){
                                        $status     = "Hold";
                                    }else{
                                        $status     = "Failed";
                                    }

                                    return [
                                        'trx_id'            => $item->trx_id,
                                        'bmt_id'            => $item->bmt_id,
                                        'type'              => $item->type,
                                        'currency'          => get_default_currency_code(),
                                        'request_amount'    => $item->request_amount,
                                        'total_charge'      => $item->charge->total_charge,
                                        'payable_amount'    => $item->payable,
                                        'status'            => $status,
                                        'attribute'         => $item->attribute,
                                        'remark'            => $item->remark,
                                        'reject_reason'     => $item->reject_reason ?? '',

                                    ];
                                });

        $data =[
            'base_curr'    => get_default_currency_code(),
            'base_curr_rate'    => getAmount(1,2),
            
            'transactions'   =>   $transactions,
        ];
        $message =  ['success'=>[__('Bulk money transfer.')]];
        return Helpers::success($data,$message);

    }
    /**
     * Method for upload bulk money transfer data
     */
    public function upload(Request $request){
        $validator      = Validator::make($request->all(),[
            'file'      => 'required|file|mimes:csv,xlsx'
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated      = $validator->validate();
        $file           = $validated['file'];

        $import         = new BulkMoneyTransferImport();
        $data           = Excel::toArray($import, $file);
        
        $collect_data   = $this->collectData($data);
        $status         = '';
        foreach($collect_data as $item){
            if(isset($item['status'])){
               $status  = 'invalid';
               break;
            }else{
                $status = 'valid';
            }
        }
        if($request->hasFile('file')) {
            $unique_id = Str::uuid();
            $file_name = 'bulk-money-' . Carbon::now()->format("Y-m-d_H-i-s") . '-' . $unique_id . "." . $request->file('file')->getClientOriginalExtension();
            $file_link = get_files_path('bulk-money-file') . '/' . $file_name;
            if (!File::exists(get_files_path('bulk-money-file'))) {
                File::makeDirectory(get_files_path('bulk-money-file'), 0755, true);
            }
            
            $request->file('file')->move(get_files_path('bulk-money-file'), $file_name);
        }

        $form_data              = [
            'type'              => GlobalConst::BULK_MONEY_TRANSFER,
            'identifier'        => Str::uuid(),
            'data'              => [
                'status'        => $status,
                'record_data'   => $collect_data,
                'file'          => $file_link
            ]
        ];

        try{
            $temporary_data     = TemporaryData::create($form_data);
        }catch(Exception $e){
            $error = ['error'=>[__('Something went wrong! Please try again.')]];
            return Helpers::error($error);
        }
        if($temporary_data->data->status != GlobalConst::INVALID){
            $amount     = 0;
            foreach($temporary_data->data->record_data as $item){
                $amount += $item->amount;
            }
            $transaction_settings           = TransactionSetting::where('slug',GlobalConst::TRANSACTION_TYPE_BULK_MONEY)->first();
            $fixed_charge                   = $transaction_settings->fixed_charge;
            $percent_charge                 =($amount * $transaction_settings->percent_charge) / 100 ;
            $total_charge                   = $fixed_charge + $percent_charge;
            $payable_amount                 = $amount + $total_charge;
            
        }else{
            $amount         = 0;
            $payable_amount = 0;
            $total_charge   = 0;
        }    
        $merchant_wallet        = MerchantWallet::auth()->first();
        $data =[            
            'temporary_data'   => $temporary_data,
            'amount'           => $amount,
            'total_charge'     => $total_charge,
            'payable_amount'   => $payable_amount,
            'available_balance'   => $merchant_wallet->balance,
            'currency'          => get_default_currency_code()
        ];
        $message =  ['success'=>[__('Bulk money transfer.')]];
        return Helpers::success($data,$message);
    }
    /**
     * Method for collect data
     * @param $data
     */
    function collectData($data) {
        foreach($data as $sheet) {
            $user_data      = [];
            $agent_data     = [];
            $invalid        = [];
            foreach($sheet as $row) {
                if($row['receiver_type'] == GlobalConst::SENDER_TYPE_USER){
                    array_push($user_data,$row);
                }else if($row['receiver_type'] == GlobalConst::SENDER_TYPE_AGENT){
                    array_push($agent_data,$row);
                }else{
                    $row['status'] = 'Invalid Receiver Type';
                    array_push($invalid,$row);
                }
            }
        }
        $user_confirm_data  = [];
        $agent_confirm_data = [];
        foreach($user_data as $user){
            $validation_check   = User::where('username',$user['username'])->where('status',1)->first();
            if(!$validation_check){
                $user['status'] = 'Invalid username or banned user';
                array_push($user_confirm_data,$user);
            }else{
                array_push($user_confirm_data,$user);
            }
            
        }
        foreach($agent_data as $agent){
            $validation_check       = Agent::where('username',$agent['username'])->where('status',1)->first();
            if(!$validation_check){
                $agent['status']    = 'Invalid username or banned user';
                array_push($agent_confirm_data,$agent);
            }else{
                array_push($agent_confirm_data,$agent);
            }
            
        }
        $merged_array = array_merge($user_confirm_data,$agent_confirm_data,$invalid);
        
        return $merged_array;
    }
    /**
     * Method for submit the information
     */
    public function submit(Request $request){
        $validator      = Validator::make($request->all(),[
            'identifier'      => 'required'
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        $validated      = $validator->validate();
        $data       = TemporaryData::where('identifier',$validated['identifier'])->first();
        if(!$data) {
            $error = ['error'=>[__('Data not found!')]];
            return Helpers::error($error);
        }
        if($data->data->status == GlobalConst::INVALID){
            $error = ['error'=>[__('You can not transfer bulk money in invalid account.')]];
            return Helpers::error($error);
        }
        $amount = 0;
        foreach($data->data->record_data as $item){
            $amount += $item->amount;
        }
        $merchant_wallet                = MerchantWallet::where('merchant_id',auth()->user()->id)->first();
        if($amount > $merchant_wallet->balance){
            $error = ['error'=>[__('Sorry! Insufficient balance.')]];
            return Helpers::error($error);
        }
        $transaction_settings           = TransactionSetting::where('slug',GlobalConst::TRANSACTION_TYPE_BULK_MONEY)->first();
        if(!$transaction_settings){
            $error = ['error'=>[__('Sorry! Transaction settings not found.')]];
            return Helpers::error($error);
        }
        $currency                       = Currency::default();
        if(!$currency){
            $error = ['error'=>[__('Default currency not found.')]];
            return Helpers::error($error);
        }
        $fixed_charge                   = $transaction_settings->fixed_charge;
        $percent_charge                 =($amount * $transaction_settings->percent_charge) / 100 ;
        $total_charge                   = $fixed_charge + $percent_charge;
        $payable_amount                 = $amount + $total_charge;
        if($payable_amount > $merchant_wallet->balance){
            $error = ['error'=>[__('Sorry! Insufficient balance.')]];
            return Helpers::error($error);
        }
        $bmt_id                         = Str::uuid();
        try{
            $insert_sender_data         = $this->insertSenderData($bmt_id,$data->data,$merchant_wallet,$amount,$payable_amount);
            $this->insertTransactionCharge($insert_sender_data,$fixed_charge,$percent_charge,$total_charge);
            $this->insertReceiverData($bmt_id,$data->data);
            $this->deleteTempData($data);
        }catch(Exception $e){
            $error = ['error'=>[__('Something went wrong! Please try again.')]];
            return Helpers::error($error);
        }
        $message =  ['success'=>[__('Bulk money transfer successfully.')]];
        return Helpers::success([],$message);
    }
    /**
     * Method for insert sender data
     */
    function insertSenderData($bmt_id,$data,$merchant_wallet,$amount,$payable_amount){
        $basic_settings         = BasicSettings::first();
        $user                   = auth()->user();
        $trx_id                 = 'BMT'.getTrxNum();
        $available_balance      = $merchant_wallet->balance - $payable_amount;
        $details                = [
            'receiver_data'     => $data,
        ];
        DB::beginTransaction();
        try{
            $id         = DB::table('transactions')->insertGetId([
                'merchant_id'               => auth()->user()->id,
                'merchant_wallet_id'        => $merchant_wallet->id,
                'type'                      => PaymentGatewayConst::BULKMONEYTRANSFER,
                'trx_id'                    => $trx_id,
                'bmt_id'                    => $bmt_id,
                'request_amount'            => $amount,
                'payable'                   => $payable_amount,
                'available_balance'         => $available_balance,
                'remark'                    => "Bulk money transfer to all accounts.",
                'details'                   => json_encode($details),
                'status'                    => 1,
                'attribute'                 => PaymentGatewayConst::SEND,
                'created_at'                => now(),
            ]);
            $this->updateMerchantWalletBalance($merchant_wallet,$available_balance);
            $auth_user  = auth()->user();
            if(($auth_user->email_verified == true && $auth_user->sms_verified == true) || ($auth_user->email_verified == true && $auth_user->sms_verified == false)){
                Notification::route("mail",$auth_user->email)->notify(new BulkMoneyNotification($auth_user,$amount,$trx_id));
            }
            if($auth_user->email_verified == false && $auth_user->sms_verified == true){
                $message = __("Bulk Money Transfer" . " "  . get_amount($amount) . ' ' . get_default_currency_code() . " " . "Transaction ID: " . $trx_id . ' ' . "Date : " . Carbon::now()->format('Y-m-d')) . ' successfully sended to all accounts.';
                sendApiSMS($message,@$user->full_mobile);
            }
            
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }
        return $id;
    }
    /**
     * function for update merchant wallet balance
     */
    function updateMerchantWalletBalance($merchant_wallet,$available_balance){
        $merchant_wallet->update([
            'balance'       => $available_balance,
        ]);
    }
    /**
     * Method for insert transaction charge
     */
    function insertTransactionCharge($transaction_id,$fixed_charge,$percent_charge,$total_charge){
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'            => $transaction_id,
                'percent_charge'            => $percent_charge,
                'fixed_charge'              => $fixed_charge,
                'total_charge'              => $total_charge,
                'created_at'                => now()
            ]);
            DB::commit();
    
            $notification_content       = [
                'title'                 => 'Bulk Money Transfer',
                'message'               => 'Bulk money transfer to all accounts.',
                'image'                 => get_image(auth()->user()->image,'merchant-profile'),
            ];
            //save the data in merchant notification
            MerchantNotification::create([
                'merchant_id'           => auth()->user()->id,
                'type'                  => NotificationConst::BULKMONEYTRANSFER,
                'message'               => $notification_content,
            ]);
            //admin create notifications
            $notification_content['title'] = __('Bulk money transfer to all accounts.');
            AdminNotification::create([
                'type'      => NotificationConst::BULKMONEYTRANSFER,
                'admin_id'  => 1,
                'message'   => $notification_content,
            ]);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        }        
    }
    /**
     * Method for insert receiver transaction data
     */
    function insertReceiverData($bmt_id,$data){
        $trx_id         = 'BMT'.getTrxNum();
        $basic_settings = BasicSettings::first();
        $user_data      = [];
        $agent_data     = [];
        foreach($data->record_data as $item){
            if($item->receiver_type == GlobalConst::SENDER_TYPE_USER){
                array_push($user_data,$item);
            }else{
                array_push($agent_data,$item);
            }
        }

        // for user
        foreach($user_data as $data){
            $trx_id                 = 'BMT'.getTrxNum();
            $user                   = User::where('username',$data->username)->first();
            $user_wallet            = UserWallet::where('user_id',$user->id)->first();
            $available_balance      = $user_wallet->balance + $data->amount;

            $transaction_settings   = TransactionSetting::where('slug',GlobalConst::TRANSACTION_TYPE_BULK_MONEY)->first();
            if(!$transaction_settings) return back()->with(['error' => ['Sorry! Transaction settings not found.']]);
           
            $fixed_charge           = $transaction_settings->fixed_charge;
            $percent_charge         =($data->amount * $transaction_settings->percent_charge) / 100 ;
            $total_charge           = $fixed_charge + $percent_charge;
            $payable_amount         = $data->amount + $total_charge;

            DB::beginTransaction();
            try{
                $id = DB::table('transactions')->insertGetId([
                    'user_id'           => $user->id,
                    'user_wallet_id'    => $user_wallet->id,
                    'merchant_id'       => auth()->user()->id,
                    'type'              => PaymentGatewayConst::BULKMONEYTRANSFER,
                    'trx_id'            => $trx_id,
                    'bmt_id'            => $bmt_id,
                    'request_amount'    => $data->amount,
                    'payable'           => $payable_amount,
                    'available_balance' => $available_balance,
                    'remark'            => "Bulk money receive from " .auth()->user()->fullname,
                    'status'            => 1,
                    'attribute'         => PaymentGatewayConst::RECEIVED,
                    'created_at'        => now(),
                ]);
                
                $this->updateUserWalletBalance($available_balance,$user_wallet);
                $this->userNotification($user,$data->amount);
                $this->insertFeesAndCharges($id,$fixed_charge,$percent_charge,$total_charge);
                $auth_user  = auth()->user();
                if(($auth_user->email_verified == true && $auth_user->sms_verified == true) || ($auth_user->email_verified == true && $auth_user->sms_verified == false)){
                    Notification::route("mail",$user->email)->notify(new BulkMoneyReceiveNotification($user,$data->amount,$trx_id));
                }
                if($auth_user->email_verified == false && $auth_user->sms_verified == true){
                    $message = __("Bulk Money" . " "  . get_amount($data->amount) . ' ' . get_default_currency_code() . " " . "Transaction ID: " . $trx_id . ' ' . "Date : " . Carbon::now()->format('Y-m-d')) . ' Received.';
                    sendApiSMS($message,@$user->full_mobile);
                }
                
                DB::commit();
            }catch(Exception $e) {
                DB::rollBack();
                throw new Exception(__("Something went wrong! Please try again."));
            }
        }
       
        //for agent
        foreach($agent_data as $data){
            $trx_id                 = 'BMT'.getTrxNum();
            $agent                  = Agent::where('username',$data->username)->first();
            $agent_wallet           = AgentWallet::where('agent_id',$agent->id)->first();
            $available_balance      = $agent_wallet->balance + $data->amount;
            
            $transaction_settings   = TransactionSetting::where('slug',GlobalConst::TRANSACTION_TYPE_BULK_MONEY)->first();
            if(!$transaction_settings) return back()->with(['error' => ['Sorry! Transaction settings not found.']]);
           
            $fixed_charge           = $transaction_settings->fixed_charge;
            $percent_charge         =($data->amount * $transaction_settings->percent_charge) / 100 ;
            $total_charge           = $fixed_charge + $percent_charge;
            $payable_amount         = $data->amount + $total_charge;

            DB::beginTransaction();
            try{
                $id = DB::table('transactions')->insertGetId([
                    'agent_id'          => $agent->id,
                    'agent_wallet_id'   => $agent_wallet->id,
                    'merchant_id'       => auth()->user()->id,
                    'type'              => PaymentGatewayConst::BULKMONEYTRANSFER,
                    'trx_id'            => $trx_id,
                    'bmt_id'            => $bmt_id,
                    'request_amount'    => $data->amount,
                    'payable'           => $payable_amount,
                    'available_balance' => $available_balance,
                    'remark'            => "Bulk money receive from " . auth()->user()->fullname,
                    'status'            => 1,
                    'attribute'         => PaymentGatewayConst::RECEIVED,
                    'created_at'        => now(),
                ]);
                $this->updateAgentWalletBalance($available_balance,$agent_wallet);
                $this->agentNotification($agent,$data->amount);
                $this->insertFeesAndCharges($id,$fixed_charge,$percent_charge,$total_charge);
                $auth_user  = auth()->user();
                if(($auth_user->email_verified == true && $auth_user->sms_verified == true) || ($auth_user->email_verified == true && $auth_user->sms_verified == false)){
                    Notification::route("mail",$agent->email)->notify(new BulkMoneyReceiveNotification($agent,$data->amount,$trx_id));
                }
                if($auth_user->email_verified == false && $auth_user->sms_verified == true){
                    $message = __("Bulk Money" . " "  . get_amount($data->amount) . ' ' . get_default_currency_code() . " " . "Transaction ID: " . $trx_id . ' ' . "Date : " . Carbon::now()->format('Y-m-d')) . ' Received.';
                    sendApiSMS($message,@$agent->full_mobile);
                }
                DB::commit();
            }catch(Exception $e) {
                DB::rollBack();
                throw new Exception(__("Something went wrong! Please try again."));
            }
        }
    }
    /**
     * Method for update user wallet balance 
     */
    function updateUserWalletBalance($available_balance,$user_wallet){
        $user_wallet->update([
            'balance'   => $available_balance
        ]);
    }
    /**
     * Method for insert user notification
     */
    function userNotification($user,$amount){
        $notification_content   = [
            'title'             => 'Bulk Money Received',
            'message'           => 'Bulk money received from ' . auth()->user()->fullname,
            'image'             => get_image($user->image,'user-profile'),
            'amount'            => $amount
        ];
        //save data in user notification
        UserNotification::create([
            'user_id'           => $user->id,
            'type'              => NotificationConst::BULKMONEYTRANSFER,
            'message'           => $notification_content,
        ]);
    }
    /**
     * Method for update agent wallet balance
     */
    function updateAgentWalletBalance($available_balance,$agent_wallet){
        $agent_wallet->update([
            'balance'   => $available_balance
        ]);
    }
    /**
     * Method for insert agent notification
     */
    function agentNotification($agent,$amount){
        $notification_content       = [
            'title'                 => "Bulk Money Received",
            'message'               => "Bulk money received from " . auth()->user()->fullname,
            'image'                 => get_image($agent->image,'agent-profile'),
            'amount'                => $amount
        ];

        //save data in agent notification
        AgentNotification::create([
            'agent_id'              => $agent->id,
            'type'                  => NotificationConst::BULKMONEYTRANSFER,
            'message'               => $notification_content
        ]);

    }
    /**
     * Method for insert transaction fees and charges 
     */
    function insertFeesAndCharges($id,$fixed_charge,$percent_charge,$total_charge){
        DB::beginTransaction();
        try{
            DB::table('transaction_charges')->insert([
                'transaction_id'            => $id,
                'percent_charge'            => $percent_charge,
                'fixed_charge'              => $fixed_charge,
                'total_charge'              => $total_charge
            ]);
            DB::commit();
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception(__("Something went wrong! Please try again."));
        } 
    }
    /**
     * Function for delete tempdata 
     */
    function deleteTempData($data){
        $data->delete();
    }
    /**
     * Method for export transaction log summary.
     * @param $bmt_id
     */
    public function download(Request $request){
        $bmt_id     = $request->bmt_id;
        return Excel::download(new BulkMoneyTransferDataExport($bmt_id), 'bulk_money_transfer_data'.$bmt_id.'.xlsx');


    }
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               
}
