@extends('merchant.layouts.master')

@push('css')
<style>
    .qr-code-item {
        padding: 15px;
        display: flex;
        justify-content: center;
    }
    .qr-code-item .card {
        height: 100%;
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .qr-code {
        display: flex;
        justify-content: center;
        align-items: center;
    }
</style>
@endpush

@section('breadcrumb')
    @include('merchant.components.breadcrumb',['breadcrumbs' => [
        [
            'name'  => __("Dashboard"),
            'url'   => setRoute("merchant.dashboard"),
        ]
    ], 'active' => __(@$page_title)])
@endsection

@section('content')
<div class="body-wrapper">
    <div class="row mb-30-none justify-content-center">
        <div class="col-xl-12 mb-30">
            <div class="dash-payment-item-wrapper">
                <div class="dash-payment-item active">
                    <div class="dash-payment-title-area">
                        <span class="dash-payment-badge">!</span>
                        <h5 class="title">{{ __($page_title) }}</h5>
                    </div>
                    <div class="dash-payment-body">
                        <div class="card-body">
                            <form class="card-form" action="{{ setRoute('merchant.qrcode.generate.store') }}" method="post" enctype="multipart/form-data">
                                @csrf
                                <div class="row">
                                    <div class="col-xl-6 col-lg-6 form-group">
                                        <label>{{ __("Amount") }}</label>
                                        <div class="input-group">
                                            <input type="text" name="amount" class="form--control" placeholder="Enter Amount...">
                                        </div>
                                    </div>
                                    <div class="col-xl-6 col-lg-6 form-group">
                                        <label>{{ __("Sender Type") }}</label>
                                        <div class="toggle-container">
                                            <div class="switch-toggles active w-100" data-deactive="deactive">
                                                <input type="hidden" name="sender_type">
                                                <span class="switch" data-value="{{ global_const()::SENDER_TYPE_USER }}">{{ __("User") }}</span>
                                                <span class="switch" data-value="{{ global_const()::SENDER_TYPE_AGENT }}">{{ __("Agent") }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-12 col-lg-12">
                                    <button type="submit" class="btn--base w-100" data-bs-toggle="modal" data-bs-target="#exampleModal">{{ __("Generate QR Code") }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="dashboard-list-area mt-20">
        <div class="dashboard-header-wrapper">
            <h4 class="title">{{ __("Latest QRCodes") }}</h4>
            <div class="dashboard-btn-wrapper">
                <div class="dashboard-btn mb-2">
                    <a href="{{ setRoute('merchant.qrcode.generate.all') }}" class="btn--base">{{__("View More")}}</a>
                </div>
            </div>
        </div>
        <div class="row">
            @foreach($qr_codes as $index => $item)
                <div class="col-md-4">
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="qr-code">
                                @php
                                    $qrCode = \SimpleSoftwareIO\QrCode\Facades\QrCode::size(300)->generate(json_encode($item->qr_code));
                                @endphp
                                {!! $qrCode !!}
                            </div>
                            <p class="card-text">{{ __("Sender Type") }}: {{ $item->sender_type }}</p>
                            <p class="card-text">{{ __("Amount") }}: {{ get_amount($item->amount,get_default_currency_code()) }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>


@endsection

