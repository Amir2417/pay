<?php

namespace App\Http\Resources\Merchant;

use App\Constants\PaymentGatewayConst;
use Illuminate\Http\Resources\Json\JsonResource;

class BulkMoneyLogs extends JsonResource
{
    public function toArray($request)
    {
        if($this->status == PaymentGatewayConst::STATUSSUCCESS){
            $status     = "Success";
        }elseif($this->status == PaymentGatewayConst::STATUSPENDING){
            $status     = "Pending";
        }elseif($this->status == PaymentGatewayConst::STATUSWAITING){
            $status     = "Waiting";
        }elseif($this->status == PaymentGatewayConst::STATUSREJECTED){
            $status     = "Rejected";
        }elseif($this->status == PaymentGatewayConst::STATUSHOLD){
            $status     = "Hold";
        }else{
            $status     = "Failed";
        }
        return[
            'trx_id'            => $this->trx_id,
            'bmt_id'            => $this->bmt_id,
            'type'              => $this->type,
            'currency'          => get_default_currency_code(),
            'request_amount'    => $this->request_amount,
            'total_charge'      => $this->charge->total_charge,
            'payable_amount'    => $this->payable,
            'status'            => $status,
            'attribute'         => $this->attribute,
            'remark'            => $this->remark,
            'reject_reason'     => $this->reject_reason ?? '',
        ];
    }
}