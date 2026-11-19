@extends('layouts.app')

@section('title', 'Forgot password')

@section('content')
<x-auth-card title="Forgot your password?" subtitle="Enter your account email and we'll send you a link to reset your password.">
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="mb-4">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror" required autofocus autocomplete="username">
        </div>
        <button type="submit" class="btn btn-primary w-100">Send reset link</button>
    </form>
    <p class="text-center small mt-3 mb-0"><a href="{{ route('login') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to sign in</a></p>
</x-auth-card>
@endsection
