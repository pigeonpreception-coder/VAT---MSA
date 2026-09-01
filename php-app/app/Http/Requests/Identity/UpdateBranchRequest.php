<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Ported from lib/domain/identity.ts's normalizeBranchUpdate -- at least one of name/address/status must be present. */
class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'address' => ['sometimes', 'string', 'min:5', 'max:500'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,INACTIVE'],
        ];
    }

    public function prepareForValidation(): void
    {
        if ($this->filled('status')) {
            $this->merge(['status' => mb_strtoupper(trim((string) $this->input('status')))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasAny(['name', 'address', 'status'])) {
                $validator->errors()->add('_', 'Provide at least one field to update: name, address or status.');
            }
        });
    }
}
