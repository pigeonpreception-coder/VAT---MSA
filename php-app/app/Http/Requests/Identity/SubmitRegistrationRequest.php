<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ported from lib/domain/identity.ts's normalizeAndValidateRegistration.
 * Identifier pattern, length bounds and distinctness rule copied verbatim.
 */
class SubmitRegistrationRequest extends FormRequest
{
    private const IDENTIFIER_PATTERN = '/^[A-Z0-9][A-Z0-9.\/\-]{2,39}$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vat_number' => ['required', 'string', 'regex:'.self::IDENTIFIER_PATTERN],
            'tin' => ['required', 'string', 'regex:'.self::IDENTIFIER_PATTERN, 'different:vat_number'],
            'company_registration_number' => ['nullable', 'string', 'regex:'.self::IDENTIFIER_PATTERN],
            'legal_name' => ['required', 'string', 'min:2', 'max:200'],
            'trading_name' => ['nullable', 'string', 'min:2', 'max:200'],
            'taxpayer_type' => ['required', 'string', 'in:PRIVATE_COMPANY,CLOSE_CORPORATION,SOLE_PROPRIETOR,PARTNERSHIP,TRUST,NON_PROFIT,PUBLIC_ENTITY,OTHER'],
            'return_frequency' => ['required', 'string', 'in:MONTHLY,BIMONTHLY,QUARTERLY,ANNUAL'],
            'address' => ['required', 'string', 'min:5', 'max:500'],
            'email' => ['required', 'string', 'email', 'max:254'],
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'vat_number' => mb_strtoupper(trim((string) $this->input('vat_number'))),
            'tin' => mb_strtoupper(trim((string) $this->input('tin'))),
            'company_registration_number' => $this->filled('company_registration_number')
                ? mb_strtoupper(trim((string) $this->input('company_registration_number'))) : null,
            'taxpayer_type' => mb_strtoupper(trim((string) $this->input('taxpayer_type'))),
            'return_frequency' => mb_strtoupper(trim((string) $this->input('return_frequency'))),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }

    /** @return array<string, mixed> */
    public function normalized(): array
    {
        return $this->only([
            'vat_number', 'tin', 'company_registration_number', 'legal_name', 'trading_name',
            'taxpayer_type', 'return_frequency', 'address', 'email',
        ]);
    }
}
