@extends('layouts.app')

@section('title', 'Claim your invitation')

@section('content')
<x-auth-card title="Claim your invitation" subtitle="Set your name and password to create your account.">
    <form method="POST" action="{{ route('invitations.claim.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ old('token', $token) }}">
        <div class="mb-3">
            <label for="name" class="form-label">Your name</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}"
                   class="form-control @error('name') is-invalid @enderror" required autofocus autocomplete="name">
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror" required autocomplete="new-password" aria-describedby="password-help">
            <div id="password-help" class="form-text">At least 10 characters, including upper and lower case letters and a number.</div>
        </div>
        <div class="mb-4">
            <label for="password_confirmation" class="form-label">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   class="form-control" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary w-100">Create account</button>
    </form>
    <p class="text-center small mt-3 mb-0"><a href="{{ route('login') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to sign in</a></p>
</x-auth-card>
@endsection
