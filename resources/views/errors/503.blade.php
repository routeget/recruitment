@extends('errors.layout')

@section('title', 'Service Temporarily Unavailable (503)')
@section('code', '503')
@section('badge_class', 'bg-secondary')
@section('heading', 'Feature or Integration Currently Unavailable')
@section('message', 'The requested integration or service endpoint is currently disabled by platform governance policy or undergoing scheduled maintenance.')

@if(isset($exception) && $exception->getMessage())
@section('detail')
    <strong>Status Detail:</strong> {{ $exception->getMessage() }}
@endsection
@endif
