<?php

namespace App\Traits\User;
use App\Models\UserQrCode;
trait UserPartials{
	public function createQr(){
		$user = $this->user();
	    $qrCode = $user->qrCode()->first();
        $in['user_id'] = $user->id;
        $in['receiver_type'] = 'User';
        $in['sender_type'] = 'User';
        $data 				= [
			'receiver_type' => 'User',
			'sender_type' => 'User',
			'phone'			=> $user->full_mobile,
			'amount'		=> null,
		];
        $in['qr_code'] 		= $data;
	    if(!$qrCode){
            UserQrCode::create($in);
	    }else{
            $qrCode->fill($in)->save();
        }
	    return $qrCode;
	}

	protected function user(){
		return userGuard()['user'];
	}




}
