@extends('errors.layout')

@section('title', 'Serverfehler')
@section('code', '500')
@section('message', 'Da ist etwas schiefgegangen. Bitte versuche es in ein paar Minuten erneut.')
@section('link')
    <a href="/" class="error-link">Zur Startseite</a>
@endsection
