@extends('merchant.layouts.master')

@push('css')

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
                            <form class="card-form" action="{{ setRoute('merchant.bulk.money.transfer.upload') }}" method="post" enctype="multipart/form-data">
                                @csrf
                                <div class="row">
                                    <div class="col-xl-12 col-lg-12 form-group">
                                        <label>{{ __("File") }}</label>
                                        <div class="input-group">
                                            <input type="file" name="file" class="form--control">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-12 col-lg-12">
                                    <button type="submit" class="btn--base w-100" data-bs-toggle="modal" data-bs-target="#exampleModal">{{ __("Import & Preview") }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="dashboard-list-wrapper">
            @include('merchant.components.transaction-log',compact("transactions"))
        </div>
    </div>
</div>


@endsection

@push('script')

@endpush
