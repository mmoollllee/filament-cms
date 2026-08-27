{{--
    403 — the members-only gate (TenantVisibility::Members) is what reaches this
    view in practice: a site being finished with a customer is visible to tenant
    members only, and everyone else lands here. So it points at the login rather
    than at the home page, which would just bounce them back.

    Rendered through the `errors::` namespace, which Laravel rebuilds from
    config('view.paths') on every error — the package's own copies live outside
    it, so this view belongs to the app.
--}}
@extends('errors.layout')

@section('title', 'Noch nicht öffentlich')
@section('code', '403')
@section('message', 'Diese Seite ist noch nicht freigegeben. Nach dem Anmelden ist sie sichtbar.')
@section('link')
    <a href="/panel" class="error-link">Anmelden</a>
@endsection
