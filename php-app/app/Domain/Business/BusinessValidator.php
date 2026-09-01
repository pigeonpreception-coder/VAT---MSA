<?php

namespace App\Domain\Business;

use App\Exceptions\BusinessValidationException;

/**
 * Direct port of lib/domain/business.ts's normalize/validate functions --
 * this phase's slice covers business parties, quotations, and accounting
 * (journals/chart of accounts/periods) (see docs/MIGRATION_MATRIX.md's
 * Phase 10 section for what's still deferred: expenses,
 * inventory/products/warehouses, projects). Every function returns a
 * normalized array and throws BusinessValidationException (a list of
 * {code, path, message}, never a single message) on any failure, matching
 * the source's own BusinessValidationError exactly.
 */
class BusinessValidator
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';
    private const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9._\/-]{1,39}$/';
    private const CURRENCY_PATTERN = '/^[A-Z]{3}$/';
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/';
    private const TAX_CATEGORIES = ['STANDARD', 'ZERO_RATED', 'EXEMPT', 'OUT_OF_SCOPE'];
    private const PARTY_RELATIONSHIPS = ['CUSTOMER', 'SUPPLIER'];
    private const PARTY_STATUSES = ['ACTIVE', 'INACTIVE'];
    private const ACCOUNT_TYPES = ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'];
    private const JOURNAL_SOURCE_TYPES = ['MANUAL', 'EXPENSE', 'INVOICE', 'IMPORT', 'ADJUSTMENT'];
    private const PERIOD_CODE_PATTERN = '/^\d{4}-\d{2}$/';
    private const QUOTATION_STATUSES = ['DRAFT', 'ISSUED', 'ACCEPTED', 'REJECTED', 'EXPIRED', 'CONVERTED'];
    private const MAX_SEARCH_LIMIT = 200;
    private const DEFAULT_SEARCH_LIMIT = 50;

    /** @return array{schema_version: string, display_name: string, legal_name: ?string, vat_number: ?string, tin: ?string, email: ?string, phone: ?string, address: ?string, relationships: list<string>} */
    public static function party(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $displayName = self::textField($input['display_name'] ?? null, '/display_name', 'Display name', 2, 200, $messages);
        $legalName = self::optionalText($input['legal_name'] ?? null, '/legal_name', 'Legal name', 200, $messages);
        $vatNumber = self::optionalText($input['vat_number'] ?? null, '/vat_number', 'VAT number', 40, $messages);
        $vatNumber = $vatNumber !== null ? mb_strtoupper($vatNumber) : null;
        $tin = self::optionalText($input['tin'] ?? null, '/tin', 'TIN', 40, $messages);
        $tin = $tin !== null ? mb_strtoupper($tin) : null;
        $email = self::optionalText($input['email'] ?? null, '/email', 'Email', 254, $messages);
        $email = $email !== null ? mb_strtolower($email) : null;
        $phone = self::optionalText($input['phone'] ?? null, '/phone', 'Phone', 40, $messages);
        $address = self::optionalText($input['address'] ?? null, '/address', 'Address', 1000, $messages);

        if ($vatNumber && ! preg_match('/^[A-Z0-9][A-Z0-9 ._\/-]{1,39}$/', $vatNumber)) {
            $messages[] = ['code' => 'VAT_NUMBER_INVALID', 'path' => '/vat_number', 'message' => 'VAT number contains unsupported characters.'];
        }
        if ($tin && ! preg_match('/^[A-Z0-9][A-Z0-9 ._\/-]{1,39}$/', $tin)) {
            $messages[] = ['code' => 'TIN_INVALID', 'path' => '/tin', 'message' => 'TIN contains unsupported characters.'];
        }
        if ($email && ! preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
            $messages[] = ['code' => 'EMAIL_INVALID', 'path' => '/email', 'message' => 'Email must be a valid address.'];
        }
        if ($phone && ! preg_match('/^\+?[0-9][0-9 ()-]{5,39}$/', $phone)) {
            $messages[] = ['code' => 'PHONE_INVALID', 'path' => '/phone', 'message' => 'Phone contains unsupported characters.'];
        }

        $rawRelationships = is_array($input['relationships'] ?? null) ? $input['relationships'] : [];
        $relationships = array_values(array_unique(array_map(fn ($v) => mb_strtoupper(self::textValue($v)), $rawRelationships)));
        if (count($relationships) < 1) {
            $messages[] = ['code' => 'RELATIONSHIP_REQUIRED', 'path' => '/relationships', 'message' => 'Select at least one customer or supplier relationship.'];
        }
        foreach ($relationships as $relationship) {
            if (! in_array($relationship, self::PARTY_RELATIONSHIPS, true)) {
                $messages[] = ['code' => 'RELATIONSHIP_INVALID', 'path' => '/relationships', 'message' => ($relationship ?: 'Empty relationship').' is not supported.'];
            }
        }

        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return [
            'schema_version' => '1.0.0', 'display_name' => $displayName, 'legal_name' => $legalName,
            'vat_number' => $vatNumber, 'tin' => $tin, 'email' => $email, 'phone' => $phone, 'address' => $address,
            'relationships' => $relationships,
        ];
    }

    /** @return array{schema_version: string, reason: string} */
    public static function partyDeactivation(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $reason = self::textField($input['reason'] ?? null, '/reason', 'Deactivation reason', 5, 500, $messages);
        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'reason' => $reason];
    }

    /** @return array{relationship: ?string, q: ?string, status: ?string, limit: int, offset: int} */
    public static function partySearchQuery(array $params): array
    {
        $messages = [];

        $relationship = isset($params['relationship']) && $params['relationship'] !== '' ? mb_strtoupper(trim((string) $params['relationship'])) : null;
        if ($relationship && ! in_array($relationship, self::PARTY_RELATIONSHIPS, true)) {
            $messages[] = ['code' => 'RELATIONSHIP_INVALID', 'path' => '/relationship', 'message' => 'relationship must be CUSTOMER or SUPPLIER.'];
        }

        $status = isset($params['status']) && $params['status'] !== '' ? mb_strtoupper(trim((string) $params['status'])) : null;
        if ($status && ! in_array($status, self::PARTY_STATUSES, true)) {
            $messages[] = ['code' => 'STATUS_INVALID', 'path' => '/status', 'message' => 'status must be ACTIVE or INACTIVE.'];
        }

        $q = self::textValue($params['q'] ?? null);
        if (mb_strlen($q) > 200) {
            $messages[] = ['code' => 'QUERY_TOO_LONG', 'path' => '/q', 'message' => 'q must not exceed 200 characters.'];
        }
        $q = $q !== '' ? $q : null;

        [$limit, $offset] = self::limitOffset($params, $messages);

        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return ['relationship' => $relationship, 'q' => $q, 'status' => $status, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array{status: ?string, customer_party_id: ?string, q: ?string, limit: int, offset: int} */
    public static function quotationSearchQuery(array $params): array
    {
        $messages = [];

        $status = isset($params['status']) && $params['status'] !== '' ? mb_strtoupper(trim((string) $params['status'])) : null;
        if ($status && ! in_array($status, self::QUOTATION_STATUSES, true)) {
            $messages[] = ['code' => 'STATUS_INVALID', 'path' => '/status', 'message' => 'status must be one of: '.implode(', ', self::QUOTATION_STATUSES).'.'];
        }

        $customerPartyId = self::idField($params['customer_party_id'] ?? null, '/customer_party_id', 'Customer party', $messages, true);

        $q = self::textValue($params['q'] ?? null);
        if (mb_strlen($q) > 200) {
            $messages[] = ['code' => 'QUERY_TOO_LONG', 'path' => '/q', 'message' => 'q must not exceed 200 characters.'];
        }
        $q = $q !== '' ? $q : null;

        [$limit, $offset] = self::limitOffset($params, $messages);

        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return ['status' => $status, 'customer_party_id' => $customerPartyId, 'q' => $q, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array{schema_version: string, customer_party_id: string, branch_id: ?string, quotation_number: string,
     *   currency: string, issue_date: string, valid_until: string, notes: ?string, lines: list<array<string, mixed>>,
     *   subtotal_cents: int, tax_cents: int, total_cents: int}
     */
    public static function quotation(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $customerPartyId = self::idField($input['customer_party_id'] ?? null, '/customer_party_id', 'Customer party', $messages) ?? '';
        $branchId = self::idField($input['branch_id'] ?? null, '/branch_id', 'Branch', $messages, true);
        $quotationNumber = mb_strtoupper(self::textField($input['quotation_number'] ?? null, '/quotation_number', 'Quotation number', 2, 40, $messages));
        if ($quotationNumber && ! preg_match(self::CODE_PATTERN, $quotationNumber)) {
            $messages[] = ['code' => 'CODE_INVALID', 'path' => '/quotation_number', 'message' => 'Quotation number contains unsupported characters.'];
        }
        $currency = self::currencyField($input['currency'] ?? null, $messages);
        $issueDate = self::dateField($input['issue_date'] ?? null, '/issue_date', 'Issue date', $messages);
        $validUntil = self::dateField($input['valid_until'] ?? null, '/valid_until', 'Valid-until date', $messages);
        if ($issueDate && $validUntil && $validUntil < $issueDate) {
            $messages[] = ['code' => 'DATE_ORDER_INVALID', 'path' => '/valid_until', 'message' => 'Valid-until date cannot be earlier than issue date.'];
        }
        $notes = self::optionalText($input['notes'] ?? null, '/notes', 'Notes', 2000, $messages);

        $rawLines = is_array($input['lines'] ?? null) ? $input['lines'] : [];
        if (count($rawLines) < 1 || count($rawLines) > 200) {
            $messages[] = ['code' => 'LINE_COUNT_INVALID', 'path' => '/lines', 'message' => 'A quotation must contain 1 to 200 lines.'];
        }
        $subtotal = 0;
        $taxTotal = 0;
        $lines = [];
        foreach (array_slice($rawLines, 0, 200) as $index => $rawLine) {
            $line = is_array($rawLine) ? $rawLine : [];
            $path = "/lines/{$index}";
            $productId = self::idField($line['product_id'] ?? null, "{$path}/product_id", 'Product', $messages, true);
            $description = self::textField($line['description'] ?? null, "{$path}/description", 'Description', 2, 500, $messages);
            $quantityMicros = self::integerField($line['quantity_micros'] ?? null, "{$path}/quantity_micros", 'Quantity micros', $messages, 1);
            $unitCode = mb_strtoupper(self::textField($line['unit_code'] ?? null, "{$path}/unit_code", 'Unit code', 1, 12, $messages));
            $unitPriceCents = self::integerField($line['unit_price_cents'] ?? null, "{$path}/unit_price_cents", 'Unit price cents', $messages);
            $taxCategory = mb_strtoupper(self::textValue($line['tax_category'] ?? null));
            if (! in_array($taxCategory, self::TAX_CATEGORIES, true)) {
                $messages[] = ['code' => 'TAX_CATEGORY_INVALID', 'path' => "{$path}/tax_category", 'message' => 'Select a supported tax category.'];
            }
            $taxRateBps = self::integerField($line['tax_rate_bps'] ?? null, "{$path}/tax_rate_bps", 'Tax rate basis points', $messages);
            if ($taxRateBps > 10000) {
                $messages[] = ['code' => 'TAX_RATE_INVALID', 'path' => "{$path}/tax_rate_bps", 'message' => 'Tax rate cannot exceed 10000 basis points.'];
            }
            if ($taxCategory !== 'STANDARD' && $taxRateBps !== 0) {
                $messages[] = ['code' => 'TAX_RATE_CATEGORY_MISMATCH', 'path' => "{$path}/tax_rate_bps", 'message' => 'Only standard-rated lines may have a non-zero tax rate.'];
            }
            $netAmountCents = (int) round(($quantityMicros * $unitPriceCents) / 1_000_000);
            $taxAmountCents = (int) round(($netAmountCents * $taxRateBps) / 10_000);
            $subtotal += $netAmountCents;
            $taxTotal += $taxAmountCents;
            $lines[] = [
                'product_id' => $productId, 'description' => $description, 'quantity_micros' => $quantityMicros,
                'unit_code' => $unitCode, 'unit_price_cents' => $unitPriceCents, 'tax_category' => $taxCategory,
                'tax_rate_bps' => $taxRateBps, 'line_number' => $index + 1, 'net_amount_cents' => $netAmountCents,
                'tax_amount_cents' => $taxAmountCents,
            ];
        }

        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return [
            'schema_version' => '1.0.0', 'customer_party_id' => $customerPartyId, 'branch_id' => $branchId,
            'quotation_number' => $quotationNumber, 'currency' => $currency, 'issue_date' => $issueDate,
            'valid_until' => $validUntil, 'notes' => $notes, 'lines' => $lines,
            'subtotal_cents' => $subtotal, 'tax_cents' => $taxTotal, 'total_cents' => $subtotal + $taxTotal,
        ];
    }

    /** @return array{schema_version: string, invoice_number: string, issue_date: string, due_date: ?string} */
    public static function quotationConversion(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $invoiceNumber = mb_strtoupper(self::textField($input['invoice_number'] ?? null, '/invoice_number', 'Invoice number', 2, 100, $messages));
        if ($invoiceNumber && ! preg_match('/^[A-Z0-9][A-Z0-9._\/-]{1,99}$/', $invoiceNumber)) {
            $messages[] = ['code' => 'CODE_INVALID', 'path' => '/invoice_number', 'message' => 'Invoice number contains unsupported characters.'];
        }
        $issueDate = self::dateField($input['issue_date'] ?? null, '/issue_date', 'Issue date', $messages);
        $dueDate = self::textValue($input['due_date'] ?? null) !== '' ? self::dateField($input['due_date'], '/due_date', 'Due date', $messages) : null;
        if ($dueDate && $issueDate && $dueDate < $issueDate) {
            $messages[] = ['code' => 'DATE_ORDER_INVALID', 'path' => '/due_date', 'message' => 'Due date cannot be earlier than issue date.'];
        }
        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'invoice_number' => $invoiceNumber, 'issue_date' => $issueDate, 'due_date' => $dueDate];
    }

    /** @return array{schema_version: string, reason: string} */
    public static function quotationRejection(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $reason = self::textField($input['reason'] ?? null, '/reason', 'Rejection reason', 5, 500, $messages);
        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'reason' => $reason];
    }

    /**
     * Ported verbatim from lib/domain/business.ts's evaluateQuotationLifecycle.
     *
     * @return array{allowed: bool, targetStatus: string, reason: string}
     */
    public static function evaluateQuotationLifecycle(string $status, string $action, string $validUntil, string $today): array
    {
        if ($action === 'SEND') {
            return $status === 'DRAFT'
                ? ['allowed' => true, 'targetStatus' => 'ISSUED', 'reason' => 'A draft quotation may be sent to the customer.']
                : ['allowed' => false, 'targetStatus' => 'ISSUED', 'reason' => "Only a draft quotation can be sent; current status is {$status}."];
        }
        if ($action === 'CONVERT') {
            return $status === 'ACCEPTED'
                ? ['allowed' => true, 'targetStatus' => 'CONVERTED', 'reason' => 'Accepted quotation may be converted.']
                : ['allowed' => false, 'targetStatus' => 'CONVERTED', 'reason' => "Only an accepted quotation can be converted; current status is {$status}."];
        }
        if ($action === 'EDIT') {
            if ($status !== 'DRAFT' && $status !== 'ISSUED') {
                return ['allowed' => false, 'targetStatus' => $status, 'reason' => "A quotation can only be edited while draft or issued; current status is {$status}."];
            }
            if ($status === 'ISSUED' && $validUntil < $today) {
                return ['allowed' => false, 'targetStatus' => 'ISSUED', 'reason' => 'The quotation validity period has ended; expire it instead.'];
            }

            return ['allowed' => true, 'targetStatus' => $status, 'reason' => 'The '.mb_strtolower($status).' quotation may be edited.'];
        }
        $targetStatus = ['ACCEPT' => 'ACCEPTED', 'REJECT' => 'REJECTED', 'EXPIRE' => 'EXPIRED'];
        if ($status !== 'ISSUED') {
            return ['allowed' => false, 'targetStatus' => $targetStatus[$action], 'reason' => "Only an issued quotation can be ".mb_strtolower($action)."ed; current status is {$status}."];
        }
        $overdue = $validUntil < $today;
        if ($action === 'EXPIRE') {
            return $overdue
                ? ['allowed' => true, 'targetStatus' => 'EXPIRED', 'reason' => 'The issued quotation is overdue and may be explicitly expired.']
                : ['allowed' => false, 'targetStatus' => 'EXPIRED', 'reason' => 'A quotation cannot be expired before its valid-until date has passed.'];
        }
        if ($overdue) {
            return ['allowed' => false, 'targetStatus' => $targetStatus[$action], 'reason' => 'The quotation validity period has ended; expire it instead.'];
        }

        return ['allowed' => true, 'targetStatus' => $targetStatus[$action], 'reason' => 'The issued quotation may be '.mb_strtolower($action).'ed.'];
    }

    /**
     * @return array{schema_version: string, journal_number: string, journal_date: string, reference: ?string,
     *   description: string, currency: string, source_type: string, source_id: ?string, lines: list<array<string, mixed>>}
     */
    public static function journal(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $journalNumber = mb_strtoupper(self::textField($input['journal_number'] ?? null, '/journal_number', 'Journal number', 2, 40, $messages));
        if ($journalNumber && ! preg_match(self::CODE_PATTERN, $journalNumber)) {
            $messages[] = ['code' => 'CODE_INVALID', 'path' => '/journal_number', 'message' => 'Journal number contains unsupported characters.'];
        }
        $journalDate = self::dateField($input['journal_date'] ?? null, '/journal_date', 'Journal date', $messages);
        $reference = self::optionalText($input['reference'] ?? null, '/reference', 'Reference', 100, $messages);
        $description = self::textField($input['description'] ?? null, '/description', 'Description', 2, 500, $messages);
        $currency = self::currencyField($input['currency'] ?? null, $messages);
        $sourceType = mb_strtoupper(self::textValue($input['source_type'] ?? null));
        if (! in_array($sourceType, self::JOURNAL_SOURCE_TYPES, true)) {
            $messages[] = ['code' => 'SOURCE_TYPE_INVALID', 'path' => '/source_type', 'message' => 'Select a supported journal source type.'];
        }
        $sourceId = self::idField($input['source_id'] ?? null, '/source_id', 'Source', $messages, true);

        $rawLines = is_array($input['lines'] ?? null) ? $input['lines'] : [];
        if (count($rawLines) < 2 || count($rawLines) > 500) {
            $messages[] = ['code' => 'LINE_COUNT_INVALID', 'path' => '/lines', 'message' => 'A journal must contain 2 to 500 lines.'];
        }
        $debitTotal = 0;
        $creditTotal = 0;
        $lines = [];
        foreach (array_slice($rawLines, 0, 500) as $index => $rawLine) {
            $line = is_array($rawLine) ? $rawLine : [];
            $path = "/lines/{$index}";
            $accountId = self::idField($line['account_id'] ?? null, "{$path}/account_id", 'Account', $messages) ?? '';
            $branchId = self::idField($line['branch_id'] ?? null, "{$path}/branch_id", 'Branch', $messages, true);
            $projectId = self::idField($line['project_id'] ?? null, "{$path}/project_id", 'Project', $messages, true);
            $lineDescription = self::textField($line['description'] ?? null, "{$path}/description", 'Description', 2, 500, $messages);
            $debitCents = self::integerField($line['debit_cents'] ?? null, "{$path}/debit_cents", 'Debit cents', $messages);
            $creditCents = self::integerField($line['credit_cents'] ?? null, "{$path}/credit_cents", 'Credit cents', $messages);
            if (($debitCents === 0) === ($creditCents === 0)) {
                $messages[] = ['code' => 'JOURNAL_SIDE_INVALID', 'path' => $path, 'message' => 'Each journal line must contain a positive debit or credit, but not both.'];
            }
            $debitTotal += $debitCents;
            $creditTotal += $creditCents;
            $taxCode = self::optionalText($line['tax_code'] ?? null, "{$path}/tax_code", 'Tax code', 40, $messages);
            $lines[] = [
                'account_id' => $accountId, 'branch_id' => $branchId, 'project_id' => $projectId,
                'description' => $lineDescription, 'debit_cents' => $debitCents, 'credit_cents' => $creditCents, 'tax_code' => $taxCode,
            ];
        }
        if ($debitTotal <= 0 || $debitTotal !== $creditTotal) {
            $messages[] = ['code' => 'JOURNAL_UNBALANCED', 'path' => '/lines', 'message' => 'Total debits must equal total credits and be greater than zero.'];
        }

        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return [
            'schema_version' => '1.0.0', 'journal_number' => $journalNumber, 'journal_date' => $journalDate,
            'reference' => $reference, 'description' => $description, 'currency' => $currency,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'lines' => $lines,
        ];
    }

    /** @return array{schema_version: string, code: string, name: string, account_type: string, currency: string, control_type: ?string} */
    public static function account(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $code = mb_strtoupper(self::textField($input['code'] ?? null, '/code', 'Account code', 1, 20, $messages));
        if ($code && ! preg_match('/^[A-Z0-9][A-Z0-9._-]{0,19}$/', $code)) {
            $messages[] = ['code' => 'CODE_INVALID', 'path' => '/code', 'message' => 'Account code contains unsupported characters.'];
        }
        $name = self::textField($input['name'] ?? null, '/name', 'Account name', 2, 200, $messages);
        $accountType = mb_strtoupper(self::textValue($input['account_type'] ?? null));
        if (! in_array($accountType, self::ACCOUNT_TYPES, true)) {
            $messages[] = ['code' => 'ACCOUNT_TYPE_INVALID', 'path' => '/account_type', 'message' => 'account_type must be one of: '.implode(', ', self::ACCOUNT_TYPES).'.'];
        }
        $currency = self::currencyField($input['currency'] ?? null, $messages);
        $controlType = self::optionalText($input['control_type'] ?? null, '/control_type', 'Control type', 40, $messages);
        $controlType = $controlType !== null ? mb_strtoupper($controlType) : null;
        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'code' => $code, 'name' => $name, 'account_type' => $accountType, 'currency' => $currency, 'control_type' => $controlType];
    }

    /** @return array{schema_version: string, reason: string} */
    public static function journalReversal(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $reason = self::textField($input['reason'] ?? null, '/reason', 'Reversal reason', 10, 500, $messages);
        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'reason' => $reason];
    }

    /** @return array{schema_version: string, period_code: string} */
    public static function periodClose(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $periodCode = self::textValue($input['period_code'] ?? null);
        if (! preg_match(self::PERIOD_CODE_PATTERN, $periodCode)) {
            $messages[] = ['code' => 'PERIOD_CODE_INVALID', 'path' => '/period_code', 'message' => 'period_code must use YYYY-MM.'];
        }
        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'period_code' => $periodCode];
    }

    /** @return array{schema_version: string, code: string, name: string, default_tax_category: string, requires_receipt: bool} */
    public static function expenseCategory(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $code = mb_strtoupper(self::textField($input['code'] ?? null, '/code', 'Category code', 1, 20, $messages));
        if ($code && ! preg_match('/^[A-Z0-9][A-Z0-9._-]{0,19}$/', $code)) {
            $messages[] = ['code' => 'CODE_INVALID', 'path' => '/code', 'message' => 'Category code contains unsupported characters.'];
        }
        $name = self::textField($input['name'] ?? null, '/name', 'Category name', 2, 200, $messages);
        $defaultTaxCategory = mb_strtoupper(self::textValue($input['default_tax_category'] ?? null));
        if (! in_array($defaultTaxCategory, self::TAX_CATEGORIES, true)) {
            $messages[] = ['code' => 'TAX_CATEGORY_INVALID', 'path' => '/default_tax_category', 'message' => 'Select a supported default tax category.'];
        }
        $requiresReceipt = ! array_key_exists('requires_receipt', $input) || $input['requires_receipt'] === null ? true : (bool) $input['requires_receipt'];
        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'code' => $code, 'name' => $name, 'default_tax_category' => $defaultTaxCategory, 'requires_receipt' => $requiresReceipt];
    }

    /**
     * @return array{schema_version: string, category_id: string, supplier_party_id: ?string, project_id: ?string,
     *   branch_id: ?string, expense_number: string, expense_date: string, description: string, currency: string,
     *   net_cents: int, tax_cents: int, total_cents: int}
     */
    public static function expense(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $categoryId = self::idField($input['category_id'] ?? null, '/category_id', 'Expense category', $messages) ?? '';
        $supplierPartyId = self::idField($input['supplier_party_id'] ?? null, '/supplier_party_id', 'Supplier party', $messages, true);
        $projectId = self::idField($input['project_id'] ?? null, '/project_id', 'Project', $messages, true);
        $branchId = self::idField($input['branch_id'] ?? null, '/branch_id', 'Branch', $messages, true);
        $expenseNumber = mb_strtoupper(self::textField($input['expense_number'] ?? null, '/expense_number', 'Expense number', 2, 40, $messages));
        $expenseDate = self::dateField($input['expense_date'] ?? null, '/expense_date', 'Expense date', $messages);
        $description = self::textField($input['description'] ?? null, '/description', 'Description', 2, 500, $messages);
        $currency = self::currencyField($input['currency'] ?? null, $messages);
        $netCents = self::integerField($input['net_cents'] ?? null, '/net_cents', 'Net cents', $messages);
        $taxCents = self::integerField($input['tax_cents'] ?? null, '/tax_cents', 'Tax cents', $messages);
        $totalCents = self::integerField($input['total_cents'] ?? null, '/total_cents', 'Total cents', $messages);
        if ($netCents + $taxCents !== $totalCents) {
            $messages[] = ['code' => 'TOTAL_MISMATCH', 'path' => '/total_cents', 'message' => 'Total cents must equal net cents plus tax cents.'];
        }
        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return [
            'schema_version' => '1.0.0', 'category_id' => $categoryId, 'supplier_party_id' => $supplierPartyId,
            'project_id' => $projectId, 'branch_id' => $branchId, 'expense_number' => $expenseNumber,
            'expense_date' => $expenseDate, 'description' => $description, 'currency' => $currency,
            'net_cents' => $netCents, 'tax_cents' => $taxCents, 'total_cents' => $totalCents,
        ];
    }

    /** @return array{schema_version: string, reason: string} */
    public static function expenseRejection(array $input): array
    {
        $messages = [];
        self::schemaVersion($input, $messages);
        $reason = self::textField($input['reason'] ?? null, '/reason', 'Rejection reason', 5, 500, $messages);
        if (count($messages) > 0) {
            throw new BusinessValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'reason' => $reason];
    }

    // -- shared field helpers, ported from lib/domain/business.ts's own private helpers --

    private static function schemaVersion(array $input, array &$messages): void
    {
        if (($input['schema_version'] ?? null) !== '1.0.0') {
            $messages[] = ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'];
        }
    }

    private static function textValue(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : '';
    }

    private static function textField(mixed $value, string $path, string $label, int $min, int $max, array &$messages): string
    {
        $normalized = self::textValue($value);
        $length = mb_strlen($normalized);
        if ($length < $min || $length > $max) {
            $messages[] = ['code' => 'FIELD_LENGTH_INVALID', 'path' => $path, 'message' => "{$label} must contain {$min} to {$max} characters."];
        }

        return $normalized;
    }

    private static function optionalText(mixed $value, string $path, string $label, int $max, array &$messages): ?string
    {
        $normalized = self::textValue($value);
        if ($normalized === '') {
            return null;
        }
        if (mb_strlen($normalized) > $max) {
            $messages[] = ['code' => 'FIELD_LENGTH_INVALID', 'path' => $path, 'message' => "{$label} must not exceed {$max} characters."];
        }

        return $normalized;
    }

    private static function idField(mixed $value, string $path, string $label, array &$messages, bool $optional = false): ?string
    {
        $normalized = self::textValue($value);
        if ($normalized === '' && $optional) {
            return null;
        }
        if (! preg_match(self::ID_PATTERN, $normalized)) {
            $messages[] = ['code' => 'IDENTIFIER_INVALID', 'path' => $path, 'message' => "{$label} is invalid."];
        }

        return $normalized;
    }

    private static function integerField(mixed $value, string $path, string $label, array &$messages, int $min = 0): int
    {
        if (! is_int($value) || $value < $min) {
            $messages[] = ['code' => 'INTEGER_INVALID', 'path' => $path, 'message' => "{$label} must be a safe integer greater than or equal to {$min}."];

            return 0;
        }

        return $value;
    }

    private static function dateField(mixed $value, string $path, string $label, array &$messages): string
    {
        $normalized = self::textValue($value);
        if (! preg_match(self::DATE_PATTERN, $normalized) || strtotime("{$normalized}T00:00:00Z") === false) {
            $messages[] = ['code' => 'DATE_INVALID', 'path' => $path, 'message' => "{$label} must be a valid ISO date."];
        }

        return $normalized;
    }

    private static function currencyField(mixed $value, array &$messages): string
    {
        $currency = mb_strtoupper(self::textValue($value));
        if (! preg_match(self::CURRENCY_PATTERN, $currency)) {
            $messages[] = ['code' => 'CURRENCY_INVALID', 'path' => '/currency', 'message' => 'Currency must be a three-letter ISO 4217 code.'];
        }

        return $currency;
    }

    /** @return array{0: int, 1: int} */
    private static function limitOffset(array $params, array &$messages): array
    {
        $limit = self::DEFAULT_SEARCH_LIMIT;
        if (isset($params['limit']) && $params['limit'] !== '') {
            $parsed = filter_var($params['limit'], FILTER_VALIDATE_INT);
            if ($parsed === false || $parsed < 1 || $parsed > self::MAX_SEARCH_LIMIT) {
                $messages[] = ['code' => 'LIMIT_INVALID', 'path' => '/limit', 'message' => 'limit must be an integer between 1 and '.self::MAX_SEARCH_LIMIT.'.'];
            } else {
                $limit = $parsed;
            }
        }
        $offset = 0;
        if (isset($params['offset']) && $params['offset'] !== '') {
            $parsed = filter_var($params['offset'], FILTER_VALIDATE_INT);
            if ($parsed === false || $parsed < 0) {
                $messages[] = ['code' => 'OFFSET_INVALID', 'path' => '/offset', 'message' => 'offset must be a non-negative integer.'];
            } else {
                $offset = $parsed;
            }
        }

        return [$limit, $offset];
    }
}
