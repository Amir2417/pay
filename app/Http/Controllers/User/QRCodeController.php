<?php

namespace App\Http\Controllers\User;

use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Models\Merchants\QrCodes;
use App\Models\Merchants\Merchant;
use App\Http\Controllers\Controller;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QRCodeController extends Controller
{
    /**
     * Method for view qrcode page
     * @return view
     */
    public function index(){
        $page_title     = "QRCodes";
        $qr_codes       = QrCodes::with(['merchant'])
                            ->where('receiver_type',GlobalConst::RECEIVER_TYPE_USER)
                            ->orderBy('id','desc')
                            ->get();        

        return view('user.sections.qr-codes.index',compact(
            'page_title',
            'qr_codes'
        ));
    }
}
