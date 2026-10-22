@extends('errors.layout')

@section('title', 'Session Expired (419)')
@section('code', '419')
@section('badge_class', 'bg-warning text-dark')
@section('heading', 'Your Session Has Expired')
@section('message', 'For your security, your session or form token has timed out due to inactivity. Please return to the previous page, refresh, and sign in again if prompted.')
