<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Helpers\Api\Helpers;
use App\Http\Controllers\Controller;
use App\Models\AgentQrCode;

class ReceiveMoneyController extends Controller
{
    public function index() {
        $user = auth()->user();
        $agent_qr_code = AgentQrCode::where('agent_id',$user->id)->first();
        
        $uniqueCode = $agent_qr_code->qr_code ??'';
        
        $data = [
            'uniqueCode' => @$uniqueCode,
        ];
        $message = ['success' => [__('Receive Money')]];
        return Helpers::success($data, $message);

    }
}
