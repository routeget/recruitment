@extends('errors.layout')

@section('title', 'Access Restricted (403)')
@section('code', '403')
@section('badge_class', 'bg-danger')
@section('heading', 'Access Restricted')
@section('message', 'Your account does not currently have permission to access this resource or perform this action within the active organization workspace.')

@if(isset($exception) && $exception->getMessage())
@section('detail')
    <strong>Policy Reference:</strong> {{ $exception->getMessage() }}
@endsection
@endif
