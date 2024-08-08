@extends('user.layouts.master')
@php
    $token = (object)session()->get('remittance_token');

@endphp
@php
$siteWallet = str_replace(' ','_',$basic_settings->site_name)."_Wallet";
@endphp

@section('breadcrumb')
    @include('user.components.breadcrumb',['breadcrumbs' => [
        [
            'name'  => __("Dashboard"),
            'url'   => setRoute("user.dashboard"),
        ]
    ], 'active' => __(@$page_title)])
@endsection

@section('content')
<div class="body-wrapper">
    <div class="row mb-30-none">
        <div class="col-xl-6 mb-30">
            <div class="dash-payment-item-wrapper">
                <div class="dash-payment-item active">
                    <div class="dash-payment-title-area">
                        <span class="dash-payment-badge">!</span>
                        <h5 class="title">{{ __(@$page_title) }} {{ __("Form") }}</h5>
                    </div>
                    <div class="dash-payment-body">
                        <form class="card-form onload-from" action="{{ setRoute('user.remittance.confirmed') }}" method="POST">
                            @csrf
                            <div class="row">
                                <div class="col-xl-6 col-lg-6 form-group">
                                    <label>{{ __("From Country") }} <span class="text--base">*</span></label>
                                    <select class="form--control select2-auto-tokenize"  name="form_country" required data-minimum-results-for-search="Infinity">
                                        <option value="{{ get_default_currency_code() }}" >{{ get_default_currency_name() }}</option>
                                    </select>

                                </div>
                                <div class="col-xl-6 col-lg-6 form-group">
                                    <label>{{ __("To Country") }}<span class="text--base">*</span></label>
                                    <select name="to_country" class="form--control select2-basic" required data-placeholder="Select To Country" >
                                        @foreach ($receiverCountries as $country)
                                            <option value="{{ $country->id }}" {{ @$token->receiver_country ==  $country->id ? 'selected':''}}
                                                data-code="{{ $country->code }}"
                                                data-symbol="{{ $country->symbol }}"
                                                data-rate="{{ $country->rate }}"
                                                data-name="{{ $country->country }}"
                                                >{{ $country->country }} ({{ $country->code }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-xl-12 col-lg-12 form-group">
                                    <label>{{ __("Transaction Type") }}<span>*</span></label>
                                    <select  name="transaction_type" required  class="form--control select2-auto-tokenize" data-placeholder="Select Transaction Type" data-minimum-results-for-search="Infinity">
                                        <option value="bank-transfer" {{ @$token->transacion_type == 'bank-transfer' ? 'selected':''}} data-name="Bank Transfer">{{__("Bank Transfer")}}</option>
                                        <option value="wallet-to-wallet-transfer" {{ @$token->transacion_type == 'wallet-to-wallet-transfer' ? 'selected':''}} data-name="wallet-to-wallet-transfer">{{ @$basic_settings->site_name }} {{__("Wallet")}}</option>
                                        <option value="cash-pickup" {{ @$token->transacion_type ==  'cash-pickup' ? 'selected':''}} data-name="Cash Pickup">{{__("Cash Pickup")}}</option>

                                </select>
                                </div>
                                <div class="col-xl-10 col-lg-10 form-group">
                                    <label>{{__("recipient")}} <span class="text--base">*</span></label>
                                    <select name="recipient" class="form--control  select2-basic  recipient" required data-placeholder="{{ __("Select Recipient") }}" >

                                    </select>
                                </div>
                                <div class="col-xl-2 col-lg-2 form-group mt-4">
                                    <div class="remittance-add-btn-area mt-2">
                                        <a href="javascript:void(0)" class="btn--base w-100 add-recipient">{{ __('Add') }} <i class="fas fa-plus-circle ms-1"></i></a>
                                    </div>
                                </div>

                                <div class="col-xl-6 col-lg-6 form-group">
                                    <label>{{ __("sending Amount") }} <span class="text--base">*</span></label>
                                    <div class="input-group">
                                        <input type="text" name="send_amount" class="form--control number-input" placeholder="{{ __('enter Amount') }}" value="{{ old('send_amount') }}" >
                                        <div class="input-group-append">
                                            <span class="input-group-text copytext">{{ get_default_currency_code() }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-6 col-lg-6 form-group">
                                    <label>{{ __("recipient Get") }} <span class="text--base">*</span></label>
                                    <div class="input-group">
                                        <input type="text" name="receive_amount" class="form--control number-input" placeholder="{{ __('enter Amount') }}" value="{{ old('receive_amount') }}" >
                                        <div class="input-group-append">
                                            <span class="input-group-text reciver_curr_code">{{ get_default_currency_code() }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-12 col-lg-12 form-group">
                                    <div class="note-area">
                                        <code class="d-block limit-show">--</code>
                                        <code class="d-block fees-show">--</code>
                                    </div>
                                </div>
                                <div class="col-xl-12 col-lg-12 form-group">
                                    <div class="note-area">
                                        <code class="d-block">{{ __("Available Balance") }}: {{ authWalletBalance() }} {{ get_default_currency_code() }}</code>
                                    </div>
                                </div>
                                <div class="withdraw-btn mt-20">
                                    <button type="submit" class="btn--base w-100 btn-loading confirmed">{{ __("Continue") }} <i class="fas fa-paper-plane ms-1"></i></button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-6 mb-30">
            <div class="dash-payment-item-wrapper">
                <div class="dash-payment-item active">
                    <div class="dash-payment-title-area">
                        <span class="dash-payment-badge">!</span>
                        <h5 class="title">{{ __("Preview") }}</h5>
                    </div>
                    <div class="dash-payment-body">
                        <div class="preview-list-wrapper">
                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="las la-flag"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span>{{ __("sending Country") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="sender-county">--</span>
                                </div>
                            </div>
                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="lab la-font-awesome-flag"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span>{{ __("Receiving Country") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="receiver-county">--</span>
                                </div>
                            </div>
                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="las la-user-tag"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span>{{ __("recipient") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="recipient-name">--</span>
                                </div>
                            </div>
                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="las la-cash-register"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span>{{ __("Transaction Type") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="trans-type">--</span>
                                </div>
                            </div>
                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="las la-paper-plane"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span>{{ __("sending Amount") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="request-amount">--</span>
                                </div>
                            </div>
                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="las la-arrow-right"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span>{{ __("Transfer Fee") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="fees">--</span>
                                </div>
                            </div>
                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="las la-exchange-alt"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span>{{ __("Exchange Rate") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="fw-bold rate-show">--</span>
                                </div>
                            </div>
                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="las la-money-check-alt"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span>{{ __("recipient Get") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="text--base fw-bold recipient-amount">--</span>
                                </div>
                            </div>
                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="las la-money-check-alt"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span>{{ __("Total Payable") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="text--base last payable-amount">--</span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="dashboard-list-area mt-20">
        <div class="dashboard-header-wrapper">
            <h4 class="title">{{ __("Remittance Log") }}</h4>
            <div class="dashboard-btn-wrapper">
                <div class="dashboard-btn mb-2">
                    <a href="{{ setRoute('user.transactions.index','remittance') }}" class="btn--base">{{__("View More")}}</a>
                </div>
            </div>
        </div>
        <div class="dashboard-list-wrapper">
            @include('user.components.transaction-log',compact("transactions"))
        </div>
    </div>
</div>
@endsection

@push('script')
<script>
    document.querySelector('.number-input').addEventListener('input', function (e) {
        let value = e.target.value;
        
        if (!/^\d*\.?\d{0,2}$/.test(value)) {
            e.target.value = value.slice(0, -1);
        }
    });
</script>
<script>
    var defualCurrency = "{{ get_default_currency_code() }}";
    var defualCurrencyRate = "{{ get_default_currency_rate() }}";
    var senderCountry = "{{ get_default_currency_name() }}";
    var walletTransactionName ="{{ @$basic_settings->site_name }}" +' '+ 'Wallet';
    var selectedRecipientByToken = "{{ @$token->recipient }}"



   $(document).ready(function(){
    recipientFilterByCountry();
    recipientFilterByTransactionType();
    set_currency_code();
    getLimit();
    getFees();
    getExchangeRate();
    getPreview();
    // readOnly();

    });
    $("select[name=to_country]").change(function(){
        recipientFilterByCountry();
        set_currency_code();
        getLimit();
        getFees();
        getExchangeRate();
        getReceiverAmount();
        getSenderAmount();
        getPreview();
        // readOnly();

    });
    $("select[name=transaction_type]").change(function(){
        recipientFilterByTransactionType();
        set_currency_code();
        getLimit();
        getFees();
        getExchangeRate();
        getPreview();
        // readOnly();

    });
    $("select[name=recipient]").change(function(){
        set_currency_code();
        getLimit();
        getFees();
        getExchangeRate();
        getPreview();
        // readOnly();
    });
    $("input[name=send_amount]").keyup(function(){
        getFees();
        getReceiverAmount();
        getPreview();

    });
    $("input[name=receive_amount]").keyup(function(){
        getSenderAmount();
        getFees();
        getPreview();
    });
    $("input[name=send_amount]").focusout(function(){
            enterLimit();
    });
    $("input[name=receive_amount]").focusout(function(){
            enterLimit();
    });


    function getLimit() {
            var sender_currency = acceptVar().sCurrency;
            var sender_currency_rate = acceptVar().sCurrency_rate;
            var min_limit = acceptVar().currencyMinAmount;
            var max_limit =acceptVar().currencyMaxAmount;
            if($.isNumeric(min_limit) || $.isNumeric(max_limit)) {
                var min_limit_calc = parseFloat(min_limit/sender_currency_rate).toFixed(2);
                var max_limit_clac = parseFloat(max_limit/sender_currency_rate).toFixed(2);
                $('.limit-show').html("{{ __('limit') }} " + min_limit_calc + " " + defualCurrency + " - " + max_limit_clac + " " + defualCurrency);
                return {
                    minLimit:min_limit_calc,
                    maxLimit:max_limit_clac,
                };
            }else {
                $('.limit-show').html("--");
                return {
                    minLimit:0,
                    maxLimit:0,
                };
            }
    }
    function getExchangeRate(event) {
            var element = event;
            var currencyCode = acceptVar().receiverCurrency;
            var currencyRate = acceptVar().receiverCurrency_rate;
            // var currencyMinAmount = acceptVar().currencyMinAmount;
            // var currencyMaxAmount = acceptVar().currencyMaxAmount;
            $('.rate-show').html("1 " + defualCurrency + " = " + parseFloat(currencyRate).toFixed(2) + " " + currencyCode);
    }
    function acceptVar() {
           var senderCurrency = defualCurrency;
           var senderCurrency_rate = defualCurrencyRate;
           var receiver_conctry = $("select[name=to_country] :selected").val();
           var receiver_conctry_name = $("select[name=to_country] :selected").data('name');
           var receiverCurrency = $("select[name=to_country] :selected").data('code');
           var receiverCurrency_rate = $("select[name=to_country] :selected").data('rate');
           var tranaction_type = $("select[name=transaction_type] :selected").val();
           var tranaction_name = $("select[name=transaction_type] :selected").data('name');
           var currencyMinAmount ="{{getAmount($exchangeCharge->min_limit)}}";
           var currencyMaxAmount = "{{getAmount($exchangeCharge->max_limit)}}";
           var currencyFixedCharge = "{{getAmount($exchangeCharge->fixed_charge)}}";
           var currencyPercentCharge = "{{getAmount($exchangeCharge->percent_charge)}}";
           var recipient =  $("select[name=recipient] :selected").val();
           var recipientName =  $("select[name=recipient] :selected").data('name');

           return {
            sCurrency:senderCurrency,
            sCurrency_rate:senderCurrency_rate,
            receiver_conctry:receiver_conctry,
            receiver_conctry_name:receiver_conctry_name,
            receiverCurrency:receiverCurrency,
            receiverCurrency_rate:receiverCurrency_rate,
            tranaction_type:tranaction_type,
            tranaction_name:tranaction_name,
            currencyMinAmount:currencyMinAmount,
            currencyMaxAmount:currencyMaxAmount,
            currencyFixedCharge:currencyFixedCharge,
            currencyPercentCharge:currencyPercentCharge,
            recipient:recipient,
            recipientName:recipientName,
        };
    }
    function feesCalculation() {
           var currencyCode = acceptVar().sCurrency;
           var currencyRate = acceptVar().sCurrency_rate;
           var sender_amount = $("input[name=send_amount]").val();
           sender_amount == "" ? (sender_amount = 0) : (sender_amount = sender_amount);

           var fixed_charge = acceptVar().currencyFixedCharge;
           var percent_charge = acceptVar().currencyPercentCharge;
           if ($.isNumeric(percent_charge) && $.isNumeric(fixed_charge) && $.isNumeric(sender_amount)) {
               // Process Calculation
               var fixed_charge_calc = parseFloat(currencyRate * fixed_charge);
               var percent_charge_calc = parseFloat(currencyRate)*(parseFloat(sender_amount) / 100) * parseFloat(percent_charge);
               var total_charge = parseFloat(fixed_charge_calc) + parseFloat(percent_charge_calc);
               total_charge = parseFloat(total_charge).toFixed(2);
               // return total_charge;
               return {
                   total: total_charge,
                   fixed: fixed_charge_calc,
                   percent: percent_charge,
               };
           } else {
               // return "--";
               return false;
           }
    }
    function getFees() {
           var currencyCode = acceptVar().sCurrency;
           var percent = acceptVar().currencyPercentCharge;
           var charges = feesCalculation();
           if (charges == false) {
               return false;
           }
           $(".fees-show").html("{{ __('charge') }}: " + parseFloat(charges.fixed).toFixed(2) + " " + currencyCode + " + " + parseFloat(charges.percent).toFixed(2) + "%  ");
       }



    function getSenderAmount() {
            var sender_currency = acceptVar().sCurrency;
            var sender_currency_rate = acceptVar().sCurrency_rate;
            var receiver_currency = acceptVar().receiverCurrency;
            var receiver_currency_rate = acceptVar().receiverCurrency_rate;
            var sender_amount = $("input[name=send_amount]");
            var receiver_amount = $("input[name=receive_amount]").val();
            if($.isNumeric(receiver_amount)) {
                var rate = parseFloat(sender_currency_rate) / parseFloat(receiver_currency_rate);
                var sender_will_get = parseFloat(rate) * parseFloat(receiver_amount);
                sender_will_get = parseFloat(sender_will_get).toFixed(2);
                sender_amount.val(sender_will_get);
                preview_receiver_will_get = parseFloat(receiver_amount).toFixed(2);
            }else {
                sender_amount.val("");
                preview_receiver_will_get = "0";
            }
    }
    function getReceiverAmount() {
            var sender_currency = acceptVar().sCurrency;
            var sender_currency_rate = acceptVar().sCurrency_rate;
            var receiver_currency = acceptVar().receiverCurrency;
            var receiver_currency_rate = acceptVar().receiverCurrency_rate;
            var sender_amount = $("input[name=send_amount]").val();
            var receiver_amount = $("input[name=receive_amount]");
            if($.isNumeric(sender_amount)) {
                var rate = parseFloat(receiver_currency_rate) / parseFloat(sender_currency_rate);
                var receiver_will_get = parseFloat(rate) * parseFloat(sender_amount);
                receiver_will_get = parseFloat(receiver_will_get).toFixed(2);
                receiver_amount.val(receiver_will_get);
                preview_receiver_will_get = receiver_will_get;
            }else {
                receiver_amount.val("");
                preview_receiver_will_get = "0";
            }
    }

    function  recipientFilterByCountry(){
        var receiver_country = acceptVar().receiver_conctry;
        var transacion_type = acceptVar().tranaction_type;
        $(".recipient").html('');
        $.ajax({
                url: "{{route('user.remittance.get.recipient.country')}}",
                type: "POST",
                data: {
                    receiver_country: receiver_country,
                    transacion_type: transacion_type,
                    _token: '{{csrf_token()}}'
                },
                dataType: 'json',
                success: function (res) {
                    var recipients = res.recipient;
                    if( recipients == ''){
                        $('.recipient').html('<option value="">No Recipient Aviliable</option>');
                    }else{
                        $('.recipient').html('<option value="">Select Recipient</option>');

                    }
                     $.each(res.recipient, function (key, value) {
                            var selected = value.id == selectedRecipientByToken ? 'selected' : '';
                            $(".recipient").append('<option value="' + value.id + '" data-trx-type="' + value.type + '" data-name="' + value.firstname + ' ' + value.lastname +'" ' + selected + ' >' + value.firstname + ' ' + value.lastname + '</option>');
                    });


                }
            });

    }
    function  recipientFilterByTransactionType(){
        var receiver_country = acceptVar().receiver_conctry;
        var transacion_type = acceptVar().tranaction_type;
        $(".recipient").html('');
        $.ajax({
                url: "{{route('user.remittance.get.recipient.transtype')}}",
                type: "POST",
                data: {
                    receiver_country: receiver_country,
                    transacion_type: transacion_type,
                    _token: '{{csrf_token()}}'
                },
                dataType: 'json',
                success: function (res) {
                    var recipients = res.recipient;
                    if( recipients == ''){
                        $('.recipient').html('<option value="">No Recipient Aviliable</option>');
                    }else{
                        $('.recipient').html('<option value="">Select Recipient</option>');
                    }
                    $.each(res.recipient, function (key, value) {
                            var selected = value.id == selectedRecipientByToken ? 'selected' : '';
                            $(".recipient").append('<option value="' + value.id + '" data-trx-type="' + value.type + '" data-name="' + value.firstname + ' ' + value.lastname +'" ' + selected + ' >' + value.firstname + ' ' + value.lastname + '</option>');
                    });

                }
            });

    }
    function set_currency_code(){
        var receiverCurrency = acceptVar().receiverCurrency;
        var transacion_type = acceptVar().tranaction_type;
        if( transacion_type == "wallet-to-wallet-transfer"){
            $('.reciver_curr_code').text(defualCurrency)
        }else{
            $('.reciver_curr_code').text(receiverCurrency)
        }

    }
    function getPreview() {
            var senderAmount = $("input[name=send_amount]").val();
            var receiveAmount = $("input[name=receive_amount]").val();
            var sender_currency = acceptVar().sCurrency;
            var sender_currency_rate = acceptVar().sCurrency_rate;
            var receiverCurrency = acceptVar().receiverCurrency;
            var receiverCurrency_rate = acceptVar().receiverCurrency_rate;
            var receiver_conctry_name = acceptVar().receiver_conctry_name;
            var sender_country = senderCountry;
            var receipient = acceptVar().recipientName;
            var tranaction_name = acceptVar().tranaction_name;


            senderAmount == "" ? senderAmount = 0 : senderAmount = senderAmount;
            // Sending Amount
            $('.request-amount').text(senderAmount + " " + defualCurrency);
            receiveAmount == "" ? receiveAmount = 0 : receiveAmount = receiveAmount;
            // receiveAmount Amount
            $('.recipient-amount').text(receiveAmount + " " + receiverCurrency);

            $('.sender-county').text(sender_country);
            $('.receiver-county').text(receiver_conctry_name);
            if(receipient === undefined){
                $('.recipient-name').text("Choose Recipient");
            }else{
                $('.recipient-name').text(receipient);
            }
            if(tranaction_name === undefined || tranaction_name === ''){
                $('.trans-type').text("Choose One");
            }else if(tranaction_name == 'wallet-to-wallet-transfer'){
                $('.trans-type').text(walletTransactionName);
            }else{
                $('.trans-type').text(tranaction_name);
            }

            // Fees
            var charges = feesCalculation();
            var total_charge = 0;
            if(senderAmount == 0){
                total_charge = 0;
            }else{
                total_charge = charges.total;
            }

            $('.fees').text(total_charge + " " + sender_currency);

            // Pay In Total
            var totalPay = parseFloat(senderAmount) * parseFloat(sender_currency_rate)
            var pay_in_total = 0;
            if(senderAmount == 0){
                pay_in_total = 0;
            }else{
                pay_in_total =  parseFloat(totalPay) + parseFloat(charges.total);
            }
            $('.payable-amount').text(parseFloat(pay_in_total).toFixed(2) + " " + sender_currency);

       }
       function enterLimit(){
        var min_limit = parseFloat("{{getAmount($exchangeCharge->min_limit)}}");
        var max_limit =parseFloat("{{getAmount($exchangeCharge->max_limit)}}");
        var currencyRate = acceptVar().currencyRate;
        var sender_amount = parseFloat($("input[name=send_amount]").val());

        if( sender_amount < min_limit ){
            throwMessage('error',['{{ __("Please follow the mimimum limit") }}']);
            $('.confirmed').attr('disabled',true)
        }else if(sender_amount > max_limit){
            throwMessage('error',['{{ __("Please follow the maximum limit") }}']);
            $('.confirmed').attr('disabled',true)
        }else{
            $('.confirmed').attr('disabled',false)
        }

       }

    $(".add-recipient").click(function(){
        var receiver_country = acceptVar().receiver_conctry;
        var transacion_type = acceptVar().tranaction_type;
        var recipient = acceptVar().recipientName;
        if ( recipient === '' || recipient === undefined) {
            recipient = '';
        }
        var sender_amount = $("input[name=send_amount]").val();
        var receive_amount = $("input[name=receive_amount]").val();

        $.ajax({
                url: "{{route('user.remittance.get.token')}}",
                type: "POST",
                data: {
                    receiver_country: receiver_country,
                    transacion_type: transacion_type,
                    recipient: recipient,
                    sender_amount: sender_amount,
                    receive_amount: receive_amount,
                    _token: '{{csrf_token()}}'
                },
                dataType: 'json',
                success: function (res) {
                    setTimeout(function () {
                    window.location="{{ setRoute('user.receipient.index') }}";
                }, 500);

                }
        });

    });

</script>

@endpush
