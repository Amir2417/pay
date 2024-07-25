<?php

namespace App\Traits\Merchant;

use Illuminate\Support\Str;
use App\Models\Merchants\MerchantQrCode;

trait UserPartials{
	public function createQr(){
		$user 				= $this->user();
	    $qrCode 			= $user->qrCode()->first();
        $in['slug'] 		= Str::uuid();
        $in['merchant_id'] 	= $user->id;
		$data 				= [
			'receiver_type' => 'Merchant',
			'username'		=> $user->username,
			'amount'		=> null,
		];
        $in['qr_code'] 		= $data;
	    if(!$qrCode){
            MerchantQrCode::create($in);
	    }else{
            $qrCode->fill($in)->save();
        }
	    return $qrCode;
	}

	protected function user(){
		return userGuard()['user'];
	}

}
