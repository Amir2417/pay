@extends('admin.layouts.master')

@push('css')

    <style>
        .fileholder {
            min-height: 374px !important;
        }

        .fileholder-files-view-wrp.accept-single-file .fileholder-single-file-view,.fileholder-files-view-wrp.fileholder-perview-single .fileholder-single-file-view{
            height: 330px !important;
        }
    </style>
@endpush

@section('page-title')
    @include('admin.components.page-title',['title' => __($page_title)])
@endsection

@section('breadcrumb')
    @include('admin.components.breadcrumb',['breadcrumbs' => [
        [
            'name'  => __("Dashboard"),
            'url'   => setRoute("admin.dashboard"),
        ],
        
    ], 'active' => __($page_title)])
@endsection

@section('content')
<div class="row mb-30-none">
    
    <div class="col-lg-12 mb-30">
        <div class="transaction-area">
            <h4 class="title mb-0"><i class="fas fa-user text--base me-2"></i>{{ __("Sender Information") }}</h4>
            <div class="content pt-0">
                <div class="list-wrapper">
                    <ul class="list">
                        <li>{{ __("Name") }}<span>{{ $transaction->merchant->full_name ?? '' }}</span></li>
                        <li>{{ __("Email") }}<span class="text-lowercase">{{ $transaction->merchant->email ?? '' }}</span></li>
                        
                        <li>{{ __("Request Amount") }}<span>{{ get_amount($transaction->request_amount,get_default_currency_code()) }}</span></li>
                        <li>{{ __("Total Fees & Charges") }}<span>{{ get_amount($transaction->charge->total_charge,get_default_currency_code()) }}</span></li>
                        <li>{{ __("Payable Amount") }} <span>{{ get_amount($transaction->payable,get_default_currency_code()) }}</span></li>
                        <li>{{ __("Current Balance") }} <span>{{ get_amount($transaction->available_balance,get_default_currency_code()) }}</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <div class="table-area ms-2">
        <div class="table-wrapper">
            <div class="table-header">
                <h4 class="title mb-0"><i class="fas fa-user text--base me-2"></i>{{ __("Receiver Information") }}</h4>
            </div>
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>{{ __("Username") }}</th>
                            <th>{{ __("Receiver Type") }}</th>
                            <th>{{ __("Amount") }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transaction->details->receiver_data->record_data  as $key => $item)
                            <tr>
                                <td>{{ $item->username ?? '' }}</td>
                                <td>{{ $item->receiver_type ?? '' }}</td>
                                <td>{{ get_amount($item->amount,get_default_currency_code()) }}</td>
                            </tr>
                        @empty
                             @include('admin.components.alerts.empty',['colspan' => 9])
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    
</div>


@endsection
