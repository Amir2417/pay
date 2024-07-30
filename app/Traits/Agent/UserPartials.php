<?php

namespace App\Traits\Agent;

use App\Models\AgentQrCode;

trait UserPartials{
	public function createQr(){
		$user = $this->user();
	    $qrCode = $user->qrCode()->first();
        $in['agent_id'] = $user->id;;
        $in['receiver_type'] = 'Agent';
        $in['sender_type'] = 'Agent';
        $data 				= [
			'receiver_type' => 'Agent',
			'sender_type' 	=> 'Agent',
			'username'			=> $user->username,
			'amount'		=> null,
		];
        $in['qr_code'] 		= $data;
	    if(!$qrCode){
            AgentQrCode::create($in);
	    }else{
            $qrCode->fill($in)->save();
        }
	    return $qrCode;
	}

	protected function user(){
		return userGuard()['user'];
	}




}
