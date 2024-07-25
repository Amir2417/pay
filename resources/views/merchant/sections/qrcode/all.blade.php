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
    <div class="dashboard-list-area mt-20">
        <div class="dashboard-header-wrapper">
            <h4 class="title">{{ __($page_title) }}</h4>
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

