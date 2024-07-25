<?php

namespace App\Http\Controllers\Api\User;

use DB;
use Illuminate\Http\Request;
use App\Http\Helpers\Api\Helpers;
use App\Http\Controllers\Controller;
use App\Models\UserQrCode;
use Illuminate\Support\Facades\DB as FacadesDB;

class ReceiveMoneyController extends Controller
{
    public function index() {
        $user = auth()->user();
        $user_qr_code = UserQrCode::where('user_id',$user->id)->first();
        
        $uniqueCode = $user_qr_code->qr_code ??'';
        
        $data = [
            'uniqueCode' => @$uniqueCode,
        ];
        $message = ['success' => [__('Receive Money')]];
        return Helpers::success($data, $message);


    }
}
