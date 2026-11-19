@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
<x-auth-card title="Sign in" subtitle="Use your work email and password to access your workspace.">
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror" required autofocus autocomplete="username">
        </div>
        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-baseline">
                <label for="password" class="form-label">Password</label>
                <a href="{{ route('password.request') }}" class="small">Forgot password?</a>
            </div>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror" required autocomplete="current-password">
        </div>
        <div class="form-check mb-4">
            <input type="checkbox" name="remember" id="remember" class="form-check-input">
            <label for="remember" class="form-check-label">Keep me signed in</label>
        </div>
        <button type="submit" class="btn btn-primary w-100">Sign in</button>
    </form>
</x-auth-card>
@endsection
