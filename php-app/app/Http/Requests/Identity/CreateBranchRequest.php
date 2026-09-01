<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/** Ported from lib/domain/identity.ts's normalizeBranch. */
class CreateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9\-]{1,19}$/'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'address' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code')))]);
    }
}
