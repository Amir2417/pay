<table class="custom-table agent-search-table">
    <thead>
        <tr>
            <th></th>
            <th>{{ __("Username") }}</th>
            <th>{{ __("Email") }}</th>
            <th>{{ __("Phone") }}</th>
            <th>{{__("Status") }}</th>
            <th>{{__("action")}}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($agents ?? [] as $key => $item)
            <tr>
                <td>
                    <ul class="user-list">
                        <li><img src="{{ $item->agentImage }}" alt="user"></li>
                    </ul>
                </td>
                <td><span>{{ $item->username }}</span></td>
                @if ($item->email == '' || $item->email == null)
                    <td>N/A</td>
                @else
                <td>{{ $item->email }} <span class="{{ $item->emailStatus->class }}">{{ __($item->emailStatus->value) }}</span></td>
                @endif
                @if ($item->full_mobile == '' || $item->full_mobile == null)
                    <td>N/A</td>
                @else
                    <td>{{ @$item->full_mobile }} <span class="{{ $item->emailStatus->class }}">{{ __($item->emailStatus->value) }}</span></td>
                @endif
                <td>
                    @if (Route::currentRouteName() == "admin.agents.kyc.unverified")
                        <span class="{{ $item->kycStringStatus->class }}">{{ __($item->kycStringStatus->value ) }}</span>
                    @else
                        <span class="{{ $item->stringStatus->class }}">{{ __($item->stringStatus->value) }}</span>
                    @endif
                </td>
                <td>
                    @if (Route::currentRouteName() == "admin.agents.kyc.unverified")
                        @include('admin.components.link.info-default',[
                            'href'          => setRoute('admin.agents.kyc.details', $item->username),
                            'permission'    => "admin.agents.kyc.details",
                        ])
                    @else
                        @include('admin.components.link.info-default',[
                            'href'          => setRoute('admin.agents.details', $item->username),
                            'permission'    => "admin.agents.details",
                        ])
                    @endif
                </td>
            </tr>
        @empty
            @include('admin.components.alerts.empty',['colspan' => 7])
        @endforelse
    </tbody>
</table>
