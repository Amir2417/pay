<?php

namespace App\Http\Controllers\Api\Agent;

use App\Constants\GlobalConst;
use App\Http\Helpers\Api\Helpers;
use App\Models\Merchants\QrCodes;
use App\Http\Controllers\Controller;

class QRCodesController extends Controller
{
    /**
     * Method for view qrcode page
     */
    public function index(){
        $qr_codes = QrCodes::with(['merchant'])
                            ->where('receiver_type',GlobalConst::RECEIVER_TYPE_AGENT)
                            ->orderBy('id','desc')
                            ->get()->map(function($data){
            return [
                'id'                => $data->id,
                'merchant_username' => $data->merchant->username,
                'receiver_type'     => $data->receiver_type,
                'amount'            => floatval($data->amount),
                'currency'          => get_default_currency_code(),
                'qrcode'            => $data->qrcodes,
                'url'               => $data->url,
                'created_at'        => $data->created_at
            ];
        });

        
        $message =  ['success'=>[__('QRCodes data.')]];
        return Helpers::success($qr_codes,$message);
    }
}
