@extends('admin.layouts.master')

@push('css')

@endpush

@section('page-title')
    @include('admin.components.page-title',['title' => __($page_title)])
@endsection

@section('breadcrumb')
    @include('admin.components.breadcrumb',['breadcrumbs' => [
        [
            'name'  => __("Dashboard"),
            'url'   => setRoute("admin.dashboard"),
        ]
    ], 'active' => __( $page_title)])
@endsection

@section('content')
    <div class="custom-card">
        <div class="card-header">
            <h6 class="title">{{ __( $page_title) }}</h6>
            <div class="col-xl-2 col-lg-2 form-group">
                <!-- Open Modal For Test code Send -->
                @include('admin.components.link.custom',[
                    'class'         => "btn--base modal-btn w-100",
                    'href'          => "#test-sms",
                    'text'          => "Send Test Code",
                    'permission'    => "admin.setup.sms.test.code.send",
                ])
            </div>
        </div>
        <div class="card-body">
            <form class="card-form" method="POST" action="{{ setRoute('admin.setup.sms.update') }}">
                @csrf
              
                <div class="row mb-10-none">
                    <div class="form-row d-none configForm row" id="twilio">
                        <div class="col-md-12">
                            <h6 class="mb-2">@lang('8x8 Configuration')</h6>
                        </div>
                        <div class="form-group col-md-6">
                            <label class="font-weight-bold">@lang('API Keys') <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" placeholder="@lang('API Keys')" name="api_key" value="{{ @$general->sms_config->api_key }}"/>
                        </div>
                        <div class="form-group col-md-6">
                            <label class="font-weight-bold">@lang('Sub Account ID') <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" placeholder="@lang('Sub Account ID')" name="sub_account_id" value="{{ @$general->sms_config->sub_account_id }}"/>
                        </div>
                        <div class="form-group col-md-6">
                            <label class="font-weight-bold">@lang('Base Url') <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" placeholder="@lang('Base Url')" name="base_url" value="{{ @$general->sms_config->base_url }}"/>
                        </div>
                        <div class="form-group col-md-6">
                            <label class="font-weight-bold">@lang('Source') <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" placeholder="@lang('Source')" name="source" value="{{ @$general->sms_config->source }}"/>
                        </div>
                    </div>
                   
               

                    <div class="col-xl-12 col-lg-12 form-group">
                        @include('admin.components.button.form-btn',[
                            'class'         => "w-100 btn-loading",
                            'text'          => "Update",
                            'permission'    => "admin.setup.sms.update",
                        ])
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Test mail send modal --}}
    <div id="test-sms" class="mfp-hide medium">
        <div class="modal-data">
            <div class="modal-header px-0">
                <h5 class="modal-title">{{ __("Send Test Sms") }}</h5>
            </div>
            <div class="modal-form-data">
                <form class="modal-form" method="POST" action="{{ setRoute('admin.setup.sms.test.code.send') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="row mb-10-none mt-3">
                        <div class="col-xl-12 col-lg-12 form-group">
                            @include('admin.components.form.input',[
                                'label'         => "Mobile Number*",
                                'name'          => "mobile",
                                'type'          => "text",
                                'value'         => old("mobile"),
                            ])
                        </div>

                        <div class="col-xl-12 col-lg-12 form-group d-flex align-items-center justify-content-between mt-4">
                            <button type="button" class="btn btn--danger modal-close">{{ __("Cancel") }}</button>
                            <button type="submit" class="btn btn--base">{{ __("Send") }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection

@push('script')
    <script>
        (function ($) {
            "use strict";
            
            var method = '{{ @$general->sms_config->name }}';
         

            if (!method) {
                method = 'twilio';
            }

            smsMethod(method);
            $('select[name=sms_method]').on('change', function() {
                var method = $(this).val();
                smsMethod(method);
            });

            function smsMethod(method){
                $('.configForm').addClass('d-none');
                if(method != 'php') {
                    $(`#${method}`).removeClass('d-none');
                }
            }

        })(jQuery);

    </script>
@endpush