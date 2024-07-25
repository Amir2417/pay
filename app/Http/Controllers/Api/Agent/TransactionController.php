<?php

namespace App\Http\Controllers\Api\Agent;

use App\Models\AgentProfit;
use App\Models\Transaction;
use App\Http\Helpers\Api\Helpers;
use App\Http\Controllers\Controller;
use App\Constants\PaymentGatewayConst;
use App\Http\Resources\Agent\BillPayLogs;
use App\Http\Resources\Agent\MoneyInLogs;
use App\Http\Resources\Agent\AddMoneyLogs;
use App\Http\Resources\Agent\RemittanceLogs;
use App\Http\Resources\Agent\AgentProfitLogs;
use App\Http\Resources\Agent\MobileTopupLogs;
use App\Http\Resources\Agent\AddSubBalanceLogs;
use App\Http\Resources\Agent\AgentMoneyOutLogs;
use App\Http\Resources\Agent\TransferMoneyLogs;
use App\Http\Resources\Agent\WithdrawMoneyLogs;
use App\Http\Resources\Agent\BulkMoneyTransferLogs;

class TransactionController extends Controller
{
    public function slugValue($slug) {
        $values =  [
            'add-money'             => PaymentGatewayConst::TYPEADDMONEY,
            'withdraw-money'        => PaymentGatewayConst::TYPEMONEYOUT,
            'transfer-money'        => PaymentGatewayConst::TYPETRANSFERMONEY,
            'agent-money-out'       => PaymentGatewayConst::AGENTMONEYOUT,
            'money-in'              => PaymentGatewayConst::MONEYIN,
            'bill-pay'              => PaymentGatewayConst::BILLPAY,
            'mobile-top-up'         => PaymentGatewayConst::MOBILETOPUP,
            'remittance'            => PaymentGatewayConst::SENDREMITTANCE,
            'add-sub-balance'       => PaymentGatewayConst::TYPEADDSUBTRACTBALANCE,
            'profit-logs'           => PaymentGatewayConst::PROFITABLELOGS,

        ];

        if(!array_key_exists($slug,$values)) return abort(404);
        return $values[$slug];
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($slug = null) {

        // start transaction now
        $addMoney           = Transaction::agentAuth()->addMoney()->orderByDesc("id")->latest()->get();
        $withdrawMoney      = Transaction::agentAuth()->moneyOut()->orderByDesc("id")->get();
        $transferMoney      = Transaction::agentAuth()->senMoney()->orderByDesc("id")->get();
        $agentMoneyOut      = Transaction::agentAuth()->agentMoneyOut()->orderByDesc("id")->get();
        $moneyIn            = Transaction::agentAuth()->moneyIn()->orderByDesc("id")->get();
        $bill_pay           = Transaction::agentAuth()->billPay()->orderByDesc("id")->get();
        $mobileTopUp        = Transaction::agentAuth()->mobileTopup()->orderByDesc("id")->get();
        $remittance         = Transaction::agentAuth()->remitance()->orderByDesc("id")->get();
        $addSubBalance      = Transaction::agentAuth()->addSubBalance()->orderByDesc("id")->get();
        $profitLogs         = AgentProfit::agentAuth()->latest()->get();
        $bulkMoneyTransfer      = Transaction::agentAuth()->bulkMoney()->orderByDesc("id")->get();

        $transactions = [
            'add_money'         => AddMoneyLogs::collection($addMoney),
            'withdraw_money'    => WithdrawMoneyLogs::collection($withdrawMoney),
            'transfer_money'    => TransferMoneyLogs::collection($transferMoney),
            'agent_money_out'   => AgentMoneyOutLogs::collection($agentMoneyOut),
            'money_in'          => MoneyInLogs::collection($moneyIn),
            'bill_pay'          => BillPayLogs::collection($bill_pay),
            'top_up'            => MobileTopupLogs::collection($mobileTopUp),
            'remittance'        => RemittanceLogs::collection($remittance),
            'profit_logs'       => AgentProfitLogs::collection($profitLogs),
            'add_sub_balance'   => AddSubBalanceLogs::collection($addSubBalance),
            'bulk_money_transfer'   => BulkMoneyTransferLogs::collection($bulkMoneyTransfer),
        ];
        $transactions = (object)$transactions;

        $transaction_types = [
            'add_money'             => PaymentGatewayConst::TYPEADDMONEY,
            'withdraw_money'        => PaymentGatewayConst::TYPEMONEYOUT,
            'transfer_money'        => PaymentGatewayConst::TYPETRANSFERMONEY,
            'agent_money_out'        => PaymentGatewayConst::AGENTMONEYOUT,
            'money_in'              => PaymentGatewayConst::MONEYIN,
            'bill_pay'              => PaymentGatewayConst::BILLPAY,
            'top_up'                => PaymentGatewayConst::MOBILETOPUP,
            'remittance'            => PaymentGatewayConst::SENDREMITTANCE,
            'profit_logs'           => PaymentGatewayConst::PROFITABLELOGS,
            'add_sub_balance'       => PaymentGatewayConst::TYPEADDSUBTRACTBALANCE,
            'bulk_money_transfer'   => PaymentGatewayConst::BULKMONEYTRANSFER
        ];
        $transaction_types = (object)$transaction_types;
        $data =[
            'transaction_types' => $transaction_types,
            'transactions'=> $transactions,
        ];
        $message =  ['success'=>[__('All Transactions')]];
        return Helpers::success($data,$message);
    }

}
