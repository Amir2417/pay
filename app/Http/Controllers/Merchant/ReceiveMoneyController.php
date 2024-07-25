<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class ReceiveMoneyController extends Controller
{
    public function index() {
        $page_title     = __("Receive Money");
        $merchant       = auth()->user();
        $merchant->createQr();
        $merchantQrCode     = $merchant->qrCode()->first();
        $uniqueCode         = $merchantQrCode->qr_code??'';
        $qrCode             = QrCode::size(300)->generate(json_encode($uniqueCode));
        

        return view('merchant.sections.receive-money.index',compact("page_title","uniqueCode","qrCode",'merchant'));
    }

}
