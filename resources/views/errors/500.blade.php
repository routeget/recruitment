@extends('errors.layout')

@section('title', 'Service Error (500)')
@section('code', '500')
@section('badge_class', 'bg-danger')
@section('heading', 'Unexpected Service Error')
@section('message', 'An unexpected error occurred while processing your request. No sensitive data was exposed, and the event has been logged for operational review. Please return to your workspace and try again.')
