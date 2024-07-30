<?php

namespace App\Http\Controllers\Merchant;

use Exception;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Constants\GlobalConst;
use App\Http\Controllers\Controller;
use App\Models\Merchants\MerchantQrCode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QrCodeGenerateController extends Controller
{
    /**
     * Method for view qr code generate page
     */
    public function index(){
        $page_title     = "QRCode Generates";
        $qr_codes       = MerchantQrCode::auth()->with(['merchant'])->whereNot('sender_type',null)->orderBy('id','desc')->take(3)->get();
        return view('merchant.sections.qrcode.index',compact(
            'page_title',
            'qr_codes',
        ));
    }
    /**
     * Method for store information
     */
    public function store(Request $request){
        $validator              = Validator::make($request->all(),[
            'sender_type'       => 'required|string',
            'amount'            => 'required'
        ]);
        if($validator->fails()) return back()->withErrors($validator)->withInput($request->all());
        
        $validated                  = $validator->validate();
        $validated['merchant_id']   = auth()->user()->id;
        $slug                       = Str::uuid();
        if(MerchantQrCode::auth()->where('sender_type',$validated['sender_type'])->where('amount',$validated['amount'])->exists()){
            throw ValidationException::withMessages([
                'name' => __('The combination of Sender Type and Amount has already been taken.'),
            ]);
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
                'receiver_type'     =>  'Merchant',
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
            MerchantQrCode::create($form_data);
        }catch(Exception $e){
            return back()->with(['error' => ['Something went wrong! Please try again.']]);
        }
        return back()->with(['success' => ['QRCode generates successfully.']]);
    }
    /**
     * Method for view all the qrcodes 
     */
    public function all(){
        $page_title     = "All QRCodes";
        $qr_codes       = MerchantQrCode::auth()->with(['merchant'])->whereNot('sender_type',null)->orderBy('id','desc')->get();
        
        return view('merchant.sections.qrcode.all',compact(
            'page_title',
            'qr_codes'
        ));

    }
}
