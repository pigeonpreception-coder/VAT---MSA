@extends('layouts.app')

@section('title', 'Confirm password')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
        <div class="card shadow-sm mt-5">
            <div class="card-body p-4">
                <h1 class="h5 mb-2">Confirm your password</h1>
                <p class="text-muted small mb-4">This is a sensitive action. Please confirm your password before continuing.</p>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('password.confirm') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input id="password" type="password" name="password" class="form-control" required autofocus autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Confirm</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
