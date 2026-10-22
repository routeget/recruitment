@extends('errors.layout')

@section('title', 'Workflow Prerequisite Required (409)')
@section('code', '409')
@section('badge_class', 'bg-warning text-dark')
@section('heading', 'Workflow Prerequisite or Governance Gate Not Satisfied')
@section('message', 'This action cannot be completed until all required workflow prerequisites, approvals, or reconciliation checks are satisfied.')

@if(isset($exception) && $exception->getMessage())
@section('detail')
    <strong>{{ $errorCode ?? 'WORKFLOW_CONFLICT' }}:</strong> {{ $exception->getMessage() }}
@endsection
@endif
