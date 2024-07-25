<?php

namespace App\Http\Controllers\Api\Merchant;

use DB;
use Illuminate\Http\Request;
use App\Http\Helpers\Api\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Merchants\MerchantQrCode;
use Illuminate\Support\Facades\DB as FacadesDB;

class ReceiveMoneyController extends Controller
{
    public function index() {
        $user = auth()->user();

        $merchant_qr_code = MerchantQrCode::where('merchant_id',$user->id)->first();
        
        $uniqueCode = $merchant_qr_code->qr_code ??'';
        
        $data = [
            'uniqueCode' => @$uniqueCode,
        ];
        $message = ['success' => [__('Receive Money')]];
        return Helpers::success($data, $message);

    }
}
