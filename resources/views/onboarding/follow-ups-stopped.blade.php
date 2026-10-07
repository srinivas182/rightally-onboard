@extends('layouts.page')
@section('title', __('Reminders stopped'))
@section('content')
<div class="text-center mx-auto" style="max-width:520px">
    <h1 class="h2">{{ __('Reminders stopped') }}</h1>
    <p class="text-slate">{{ __('You won’t get any more reminders about finishing your set-up. Your details are still saved if you change your mind.') }}</p>
    <a class="btn btn-outline-primary mt-2" href="{{ route('book') }}">{{ __('Book a call instead') }}</a>
</div>
@endsection
