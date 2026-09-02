<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/** Ported from lib/domain/identity.ts's normalizeRegistrationDecision. */
class DecideRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:APPROVE,REJECT'],
            'reason' => ['required', 'string', 'min:5', 'max:240'],
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge(['decision' => mb_strtoupper(trim((string) $this->input('decision')))]);
    }
}
