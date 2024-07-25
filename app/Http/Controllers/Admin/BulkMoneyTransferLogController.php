<?php

namespace App\Http\Controllers\Admin;

use App\Constants\PaymentGatewayConst;
use App\Http\Controllers\Controller;
use App\Models\Transaction;

class BulkMoneyTransferLogController extends Controller
{
    /**
     * Method for view bulk money transfer logs page 
     */
    public function index(){
        $page_title     = "Bulk Money Transfer Logs";
        $transactions   = Transaction::where('type',PaymentGatewayConst::BULKMONEYTRANSFER)
                            ->where('attribute',PaymentGatewayConst::SEND)
                            ->orderBy('id','desc')
                            ->paginate(10);
        
        return view('admin.sections.bulk-money-transfer-logs.index',compact(
            'page_title',
            'transactions'
        ));
    }
    /**
     * Method for view the bulk money transfer details page
     * @param $trx_id
     * @param Illuminate\Http\Request $request
     */
    public function details($trx_id){
        $page_title         = "Bulk Money Transfer Logs Details";
        $transaction        = Transaction::with(['merchant','charge'])->where('trx_id',$trx_id)->first();
        if(!$transaction) return back()->with(['error' => ['Sorry! Data is not found.']]);

        return view('admin.sections.bulk-money-transfer-logs.details',compact(
            'page_title',
            'transaction'
        ));
    }
}
