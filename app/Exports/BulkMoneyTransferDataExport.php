<?php

namespace App\Exports;

use App\Models\Transaction;
use App\Constants\PaymentGatewayConst;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;

class BulkMoneyTransferDataExport implements FromCollection, WithHeadings
{
    protected $bmt_id;

    public function __construct($bmt_id)
    {
        $this->bmt_id = $bmt_id;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $data = Transaction::with(['user','agent','charge'])->where('bmt_id', $this->bmt_id)
            ->where('attribute',PaymentGatewayConst::RECEIVED)
            ->get();
        
        
        $dataWithSerialNumbers = $data->map(function($item, $key) {
            
            if(isset($item->user)){
                $receiver_username = $item->user->username;
                $receiver_type = 'User';
            }else{
                $receiver_username = $item->agent->username;
                $receiver_type = 'Agent';
            }
            if($item->status == PaymentGatewayConst::STATUSSUCCESS){
                $status = 'Success';
            }elseif($item->status == PaymentGatewayConst::STATUSPENDING){
                $status = "Pending";
            }elseif($item->status == PaymentGatewayConst::STATUSHOLD){
                $status = "Hold";
            }elseif($item->status == PaymentGatewayConst::STATUSREJECTED){
                $status = "Rejected";
            }elseif($item->status == PaymentGatewayConst::STATUSWAITING){
                $status = "Waiting";
            }else{
                $status = "Failed";
            }
            return [
                'no'                => $key + 1,
                'trx_id'            => $item->trx_id,
                'created_at'        => $item->created_at->format('Y-m-d'),
                'username'          => $receiver_username,
                'receiver_type'     => $receiver_type,
                'request_amount'    => $item->request_amount,
                'fees'              => $item->charge->total_charge,
                'total_amount'      => $item->request_amount,
                'status'            => $status,
                'available_balance' => $item->available_balance,
                'remark'            => $item->remark
            ];
        });

        return $dataWithSerialNumbers;
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return ['NO','Transaction ID','Transaction Date','Receiver(username)','Receiver Type','Amount','Fee','Total Amount','Status','Balance','Remark'];
    }
}
