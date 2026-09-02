@extends('layouts.app')

@section('title', 'Forgot password')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
        <div class="card shadow-sm mt-5">
            <div class="card-body p-4">
                <h1 class="h4 mb-3 text-center">Forgot your password?</h1>
                <p class="text-muted text-center small mb-4">Enter your account email and we'll send you a link to reset your password.</p>

                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('password.email') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}"
                               class="form-control @error('email') is-invalid @enderror" required autofocus autocomplete="username">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Send reset link</button>
                </form>
                <p class="text-center small mt-3 mb-0"><a href="{{ route('login') }}">&larr; Back to sign in</a></p>
            </div>
        </div>
    </div>
</div>
@endsection
