<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class ReceiveMoneyController extends Controller
{
    public function index() {
        $page_title = __("Receive Money");
        $user = auth()->user();
        $user->createQr();
        $userQrCode = $user->qrCode()->first();
        $uniqueCode = $userQrCode->qr_code??'';
        $qrCode = QrCode::size(300)->generate(json_encode($uniqueCode));
        return view('agent.sections.receive-money.index',compact("page_title","uniqueCode","qrCode",'user'));
    }

}
