@extends('layouts.app')

@section('title', 'Claim your invitation')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
        <div class="card shadow-sm mt-5">
            <div class="card-body p-4">
                <h1 class="h4 mb-3 text-center">Claim your invitation</h1>
                <p class="text-muted text-center small mb-4">Set your name and password to create your account.</p>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

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
                    <div class="mb-3">
                        <label for="password_confirmation" class="form-label">Confirm password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation"
                               class="form-control" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Create account</button>
                </form>
                <p class="text-center small mt-3 mb-0"><a href="{{ route('login') }}">&larr; Back to sign in</a></p>
            </div>
        </div>
    </div>
</div>
@endsection
