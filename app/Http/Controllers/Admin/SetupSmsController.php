<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Lib\sensSMS;
use Illuminate\Http\Request;
use App\Models\Admin\BasicSettings;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class SetupSmsController extends Controller
{
    /**
     * Method for view the sms config page
     * @return view
     */
    public function configuration() {
        $page_title = "Sms Method";
        $general    = BasicSettings::first();
        return view('admin.sections.sms-method.config',compact(
            'page_title',
            'general',
        ));
    }
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request){
        $validator              = Validator::make($request->all(),[
            'api_key'           => 'required|string',
            'sub_account_id'    => 'required|string',
            'base_url'          => 'required|string',
            'source'            => 'required|string',
        ]);

        if($validator->fails()){
            return back()->withErrors($validator)->withInput($request->all());
        }

        $validated      = $validator->validate();
        $basic_settings = BasicSettings::first();
        
        if(!$basic_settings) {
            return back()->with(['error' => [__("Basic settings not found!")]]);
        }
        // Make object of email template
        $data = [
            'api_key'            => $validated['api_key'] ?? '',
            'sub_account_id'     => $validated['sub_account_id'] ?? '',
            'base_url'           => $validated['base_url'] ?? '',
            'source'             => $validated['source'] ?? '',
        ];

        try{
            $basic_settings->update([
                'sms_config'    => $data
            ]);
        }catch(Exception $e){
            return back()->with(['error' => ['Something went wrong! Please try again.']]);
        }
        return back()->with(['success'  => ['Setup email updated successfully.']]);
    }
    /**
     * Send Test SMS
     */
    public function sendTestSMS(Request $request)
    {
        $request->validate(['mobile' => 'required']);
        $general = BasicSettings::first(['sms_verification', 'sms_config','sms_api','site_name']);
        
        try{
            if ($general->sms_verification == 1) {
                $message    = "This is your test sms";
                $test_sms    =sendApiSMS($message,$request->mobile);
                if($test_sms['status'] == true){
                    return back()->with(['success' => ['You should receive a test sms at ' . $request->mobile . ' shortly.']]);
                }else{
                    return back()->with(['error' => [$test_sms['description']]]);
                }                
            }else{
                return back()->with(['error' => ['Sms notification system is off!.']]);
            }
        }catch(Exception $e){
            return back()->with(['error' => [$e->getMessage()]]);
        }

    }
}
