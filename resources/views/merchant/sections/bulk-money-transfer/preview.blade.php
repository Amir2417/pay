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
    <div class="row justify-content-center mb-30-none">
        <div class="col-xxl-{{ $collect_data->data->status == global_const()::INVALID ? '12' : '8' }} col-xl-{{ $collect_data->data->status == global_const()::INVALID ? '12' : '12' }} mb-30">
            <div class="dash-payment-item-wrapper">
                <div class="dash-payment-item active">
                    <div class="dash-payment-title-area">
                        <span class="dash-payment-badge">!</span>
                        <h5 class="title">{{ __('Uploaded Data') }}</h5>
                    </div>
                    <div class="dash-payment-body money-preview-table pt-2">
                        <div class="table-responsive">
                            <form action="{{ setRoute('merchant.bulk.money.transfer.submit',$collect_data->identifier) }}" method="post">
                                @csrf
                                <table class="custom-table">
                                    <thead>
                                        <tr>
                                            <th>{{ __("No") }}</th>
                                            <th>{{ __("Username") }}</th>
                                            <th>{{ __("Receiver Type") }}</th>
                                            <th>{{ __("Amount") }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    
                                        @forelse ($collect_data->data->record_data as $key=>$item)
                                            <tr>
                                                <td data-label="{{ __("No") }}">{{ $item->no }} </td>
                                                <td data-label="{{ __("Username") }}"><span class="text--info">{{ $item->username }}</span>
                                                    @if (isset($item->status))
                                                        <span class="other-badge other-badge-danger ms-2">{{ $item->status }}</span>
                                                    @endif
                                                </td>
                                                <td data-label="{{ __("Receiver Type") }}"><span class="text--info">{{ $item->receiver_type }}</span></td>
                                                <td data-label="{{ __("Amount") }}"><span class="text--info">{{ get_amount($item->amount,get_default_currency_code()) }}</span></td>
                                            </tr>
                                        @empty
                                            @include('admin.components.alerts.empty2',['colspan' => 7])
                                        @endforelse
                                    </tbody>
                                </table>
                                
                                @if($collect_data->data->status != global_const()::INVALID && $payable_amount < $merchant_wallet->balance) 
                                    <button type="submit" class="btn--base w-100" data-bs-toggle="modal" data-bs-target="#exampleModal" >{{ __("Confirm & Send") }}</button>
                                @endif
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @if ($collect_data->data->status != global_const()::INVALID)
        <div class="col-xxl-4 col-xl-12 mb-30">
            <div class="dash-payment-item-wrapper">
                <div class="dash-payment-item active">
                    <div class="dash-payment-title-area justify-content-between align-items-center d-sm-flex d-block">
                        <div class="payment-badge-wrapper d-flex align-items-center">
                            <span class="dash-payment-badge">!</span>
                            <h5 class="title">{{ __('Summary') }}</h5>
                        </div>
                        
                        @if ($payable_amount > $merchant_wallet->balance)
                            <span class="other-badge other-badge-danger">{{ __("Insufficient Balance") }}</span>
                        @endif
                    </div>
                    <div class="dash-payment-body">
                        <div class="preview-list-wrapper">
                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="las la-receipt"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span>{{ __("Total Amount") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="request-amount">{{ get_amount($amount,get_default_currency_code()) }}</span>
                                </div>
                            </div>
                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="las la-battery-half"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span>{{ __("Total Fees & Charges") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="fees">{{ get_amount($total_charge,get_default_currency_code()) }}</span>
                                </div>
                            </div>

                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="las la-money-check-alt"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span class="">{{ __("Available Balance") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="text--success will-get">{{ get_amount($merchant_wallet->balance,get_default_currency_code()) }}</span>
                                </div>
                            </div>
                            <div class="preview-list-item">
                                <div class="preview-list-left">
                                    <div class="preview-list-user-wrapper">
                                        <div class="preview-list-user-icon">
                                            <i class="las la-money-check-alt"></i>
                                        </div>
                                        <div class="preview-list-user-content">
                                            <span class="">{{ __("Payable Amount") }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-list-right">
                                    <span class="text--warning">{{ get_amount($payable_amount,get_default_currency_code()) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div> 
        @endif
    </div>
</div>


@endsection