@extends('errors.layout')

@section('title', 'Too Many Requests (429)')
@section('code', '429')
@section('badge_class', 'bg-warning text-dark')
@section('heading', 'Request Rate Limit Reached')
@section('message', 'Too many requests were submitted from your session in a short period. Please wait a moment and try again.')
