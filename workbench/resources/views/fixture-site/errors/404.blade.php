{{--
    Test fixture: a per-site 404 that extends the shared layout and only overrides the
    branded seams. Exercises the `{site_key}.errors.404` convention + the extension points.
--}}
@extends('cms::errors.404')

@section('cms-error-heading', 'FIXTURE SITE 404 HEADLINE')

@push('cms-error-head')
    <style>
        .error-logo { opacity: 0.9; filter: brightness(0) invert(1); }
        .error-code { opacity: 0.5; }
    </style>
@endpush
