<?php

namespace App\Http\Controllers\Agent;

use App\Constants\GlobalConst;
use App\Http\Controllers\Controller;
use App\Models\Merchants\QrCodes;
use Illuminate\Http\Request;

class QRCodeController extends Controller
{
    /**
     * Method for view qrcodes page for make payment.
     * @return view
     */
    public function index(){
        $page_title         = "QRCodes";
        $qr_codes           = QrCodes::with(['merchant'])
                                ->where('receiver_type',GlobalConst::RECEIVER_TYPE_AGENT)
                                ->get();

        return view('agent.sections.qr-codes.index',compact(
            'page_title',
            'qr_codes'
        ));             
    }
}
