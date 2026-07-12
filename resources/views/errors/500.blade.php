@extends('errors.layout')

@section('title', __('cms::errors.server_error_title'))
@section('code', '500')
@section('message', __('cms::errors.server_error_message'))
@section('link')
    <a href="/" class="error-link">{{ __('cms::errors.to_home') }}</a>
@endsection
