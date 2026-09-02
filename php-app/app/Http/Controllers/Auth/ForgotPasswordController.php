<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $request->sendResetLink();

        // Identical wording regardless of whether the email exists -- see
        // ForgotPasswordRequest's own doc comment.
        return back()->with('status', 'If an account exists for that email address, a password reset link has been sent to it.');
    }
}
