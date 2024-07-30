<?php

namespace App\Http\Controllers\Api\Merchant;

use Exception;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Http\Helpers\Api\Helpers;
use App\Models\Merchants\QrCodes;
use App\Http\Controllers\Controller;
use App\Models\Merchants\MerchantQrCode;
use Illuminate\Support\Facades\Validator;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Validation\ValidationException;

class QRCodeGenerateController extends Controller
{
    /**
     * Method for store qrcode information
     */
    public function store(Request $request){
        $validator              = Validator::make($request->all(),[
            'sender_type'     => 'required|string',
            'amount'            => 'required'
        ]);
        if($validator->fails()){
            $error =  ['error'=>$validator->errors()->all()];
            return Helpers::validation($error);
        }
        
        $validated                  = $validator->validate();
        $validated['merchant_id']   = auth()->user()->id;
        $slug                       = Str::uuid();
        if(MerchantQrCode::auth()->where('sender_type',$validated['sender_type'])->where('amount',$validated['amount'])->exists()){
            
            $error = ['error'=>[__('The combination of Sender Type and Amount has already been taken.')]];
            return Helpers::error($error);
        }
        if($validated['sender_type'] == GlobalConst::SENDER_TYPE_USER){
            $data                   = [
                'sender_type'       => GlobalConst::SENDER_TYPE_USER,
                'receiver_type'     => 'Merchant',
                'username'          => auth()->user()->username,
                'amount'            => $validated['amount'],
            ];
        }else{
            $data                   = [
                'sender_type'       =>  GlobalConst::SENDER_TYPE_AGENT,
                'receiver_type'     => 'Merchant',
                'username'          => auth()->user()->username,
                'amount'            =>  $validated['amount'],
            ];
        }

        $form_data          = [
            'slug'          => $slug,
            'merchant_id'   => auth()->user()->id,
            'sender_type'   => $validated['sender_type'],
            'receiver_type' => 'Merchant',
            'amount'        => $validated['amount'],
            'qr_code'       => $data,
        ];

        try{
            $qrcode = MerchantQrCode::create($form_data);
        }catch(Exception $e){
            $error = ['error'=>[__('Something went wrong! Please try again.')]];
            return Helpers::error($error);
        }
        $data       = [
            'currency'          => get_default_currency_code(),
            'qrcode'            => $qrcode->qr_code,
        ];
        $message =  ['success'=>[__('QRCode generate successfully.')]];
        return Helpers::success($data,$message);
    }
    /**
     * Method for show all the qrcodes
     */
    public function allQrCodes(){
        $qrcodes                    = MerchantQrCode::auth()->with(['merchant'])->orderBy('id','desc')->get()->map(function($data){
            return [
                'id'                => $data->id,
                'currency'          => get_default_currency_code(),
                'qrcode'            => $data->qr_code,
                'created_at'        => $data->created_at
            ];
        });

        $message =  ['success'=>[__('QRCodes data.')]];
        return Helpers::success($qrcodes,$message);

    }
}
