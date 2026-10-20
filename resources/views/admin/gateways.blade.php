@extends('layouts.admin')

@section('title', __('admin.gw_title'))
@section('crumb', __('admin.gw_title'))

@section('content')
@include('admin.partials.flash')

<div class="page-head">
    <div>
        <h1>{{ __('admin.gw_title') }}</h1>
        <p>{{ __('admin.gw_subtitle') }}</p>
    </div>
</div>

@unless ($supportsGateways)
    <div class="section-note" role="alert">{{ __('admin.gw_agent_old') }}</div>
@endunless

@if ($simulated)
    <div class="section-note">{{ __('admin.gw_simulated') }}</div>
@endif

<p class="section-note">{{ __('admin.gw_readonly') }}</p>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('admin.gw_organization') }}</th>
                    <th>{{ __('admin.gw_device') }}</th>
                    <th>{{ __('admin.gw_model') }}</th>
                    <th>{{ __('admin.gw_line') }}</th>
                    <th>{{ __('admin.gw_destination') }}</th>
                    <th>{{ __('admin.gw_provisioning') }}</th>
                    <th>{{ __('admin.gw_registration') }}</th>
                    <th>{{ __('admin.gw_observed') }}</th>
                    <th>{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($devices as $device)
                    @foreach ($device->lines as $line)
                        <tr>
                            <td>{{ $device->organization?->name_ar ?? $device->organization?->name_en ?? '—' }}</td>
                            <td>{{ $device->label }}</td>
                            <td>{{ $device->model }}</td>
                            <td>#{{ $line->line_index }}{{ $line->label ? ' · '.$line->label : '' }}</td>
                            <td>{{ $line->destination?->extension ?? '—' }}</td>
                            <td><span class="status {{ $line->provisioning_status }}">{{ $line->provisioning_status }}</span></td>
                            <td><span class="status {{ $line->registrationLabel() }}">{{ $line->registrationLabel() }}</span></td>
                            <td>{{ $line->registration_observed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.gateways.refresh', $device) }}">
                                    @csrf
                                    <button type="submit" class="row-link">{{ __('admin.gw_refresh') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="9" class="empty-row">{{ __('admin.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
