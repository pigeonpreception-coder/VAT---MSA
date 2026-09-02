@extends('layouts.app')

@section('title', 'Reset password')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
        <div class="card shadow-sm mt-5">
            <div class="card-body p-4">
                <h1 class="h4 mb-3 text-center">Reset your password</h1>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('password.update') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email', $email) }}"
                               class="form-control @error('email') is-invalid @enderror" required autofocus autocomplete="username">
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">New password</label>
                        <input id="password" type="password" name="password"
                               class="form-control @error('password') is-invalid @enderror" required autocomplete="new-password" aria-describedby="password-help">
                        <div id="password-help" class="form-text">At least 10 characters, including upper and lower case letters and a number.</div>
                    </div>
                    <div class="mb-3">
                        <label for="password_confirmation" class="form-label">Confirm new password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation"
                               class="form-control" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Reset password</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
