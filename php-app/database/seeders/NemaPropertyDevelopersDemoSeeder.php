<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatPeriod;
use App\Models\VatReturnVersion;
use App\Services\Invoice\InvoiceService;
use App\Services\VatLifecycle\VatLifecycleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Populates the Invoice Reconciliation Report and NamRA VAT Summary Report
 * (resources/views/vat-management/reconciliation.blade.php and the
 * VAT Refund Report's NamRA summary panel) with a real taxpayer and real
 * certified invoices pulled from the two sample documents the user
 * attached: "Invoice Reconciliation Summary Report - May - June 2026.xlsx"
 * (Godfrey Takayedza's own accountant-prepared reconciliation workbook,
 * "May - June 2026" sheet) and the matching NamRA "VAT Summary Report" PDF
 * -- both for the same real taxpayer, NEMA PROPERTY DEVELOPERS CC
 * (VAT/TIN 12384786-01-5), for the same May-June 2026 period.
 *
 * The sample's own line items (dates, counterparties, descriptions, net/VAT
 * amounts) are read from data/nema_property_developers_invoices.json, a
 * literal transcription of the xlsx's Exempt/Zero-rated/Rated output
 * sections and its two input-purchase batches, split into the app's own
 * monthly VatPeriod grain (see the note on $periods below) with per-line
 * VAT amounts recomputed at 15% in integer cents so every line passes
 * InvoiceCalculator::calculateAndValidate exactly, rather than trusting the
 * source spreadsheet's own floating-point roundings.
 *
 * Every invoice is submitted through the real InvoiceService::submit
 * pipeline (never inserted directly), so certification, VAT-rule
 * resolution, risk scoring, ledger entries and -- where a line trips the
 * risk scorer -- a real ReconciliationException are all genuine, not
 * fixtures. The nine distinct input-side suppliers (BUCO Winhoek, MegaBuild,
 * Mr Price Home, Game, High Link Hardware, BUCO Ondangwa, Swachem Namibia,
 * Bargain Building Suppliers, Timber Winhoek) are registered as their own
 * taxpayers with SELLER capability so their invoices to NEMA reach MATCHED
 * status -- VatReconciliationReportService's own INPUT_STATUSES filter
 * only ever counts MATCHED input invoices. Output-side counterparties (the
 * banks, NamRA itself, the rated-output "clients") are deliberately left
 * unregistered, matching the source data as given.
 *
 * The source workbook's own "AUDIT PERIOD: MAY - JUNE 2026" is a single
 * bimonthly window, but VatLifecycleService::generateReturn ties every
 * ledger entry to a single calendar-month period_code (an invoice's own
 * issue_date, truncated to YYYY-MM) -- a real, pre-existing constraint of
 * the ledger tagging, not something this seeder's job to fix. The sample is
 * therefore split into two real monthly VatPeriod rows, 2026-05 and
 * 2026-06, each independently reconciled and returned -- which is also how
 * a NamRA officer would actually browse this taxpayer's history one period
 * at a time. The two "input adjustment" lines the workbook dates in March
 * 2026 (purchases claimed in the May-June return) are carried into the May
 * period with their issue_date moved to 2026-05-01, since this app has no
 * separate "claim period" distinct from issue_date to express that
 * otherwise.
 */
class NemaPropertyDevelopersDemoSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/data/nema_property_developers_invoices.json'), true);

        [$nema, $nemaOrg] = $this->registerParty(
            'NEMA PROPERTY DEVELOPERS CC', 'Nema Property Developers',
            '12384786-01-5', '12384786', 'CLOSE_CORPORATION', 'BIMONTHLY',
            'Nkurenkuru, Kavango West Region, Namibia', 'accounts@nema-property-developers.test',
            ['BUYER', 'SELLER'],
        );

        $supplierNames = collect(array_merge($data['may']['input'], $data['june']['input']))
            ->pluck('party')->unique()->values();
        $suppliers = [];
        foreach ($supplierNames as $index => $name) {
            $n = $index + 1;
            [$taxpayer] = $this->registerParty(
                $name, $name, "NEMA-SEED-SUP-{$n}", "TIN-SEED-SUP-{$n}", 'CLOSE_CORPORATION', 'MONTHLY',
                'Windhoek, Namibia', Str::slug($name).'@demo-supplier.test', ['SELLER'],
            );
            $suppliers[$name] = $taxpayer;
        }

        $officer = User::updateOrCreate(
            ['email' => 'namra-seed@vat-msa.test'],
            [
                'name' => 'NamRA Seed Officer', 'password' => Hash::make('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT',
                'taxpayer_id' => null, 'status' => 'ACTIVE', 'email_verified_at' => now(),
            ],
        );

        $invoiceService = app(InvoiceService::class);
        $lifecycle = app(VatLifecycleService::class);

        $periods = [
            'may' => ['code' => '2026-05', 'start' => '2026-05-01', 'end' => '2026-05-31', 'due' => '2026-06-25'],
            'june' => ['code' => '2026-06', 'start' => '2026-06-01', 'end' => '2026-06-30', 'due' => '2026-07-25'],
        ];

        foreach ($periods as $key => $meta) {
            $period = VatPeriod::updateOrCreate(
                ['organisation_id' => $nemaOrg->id, 'period_code' => $meta['code']],
                [
                    'taxpayer_id' => $nema->id, 'period_start' => $meta['start'], 'period_end' => $meta['end'],
                    'due_date' => $meta['due'], 'status' => 'OPEN', 'lock_version' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ],
            );

            $counter = 0;
            foreach ($data[$key]['output'] as $item) {
                $counter++;
                $this->submitOnce($invoiceService, $officer, $nema, $this->outputPayload($nema, $item, $key, $counter));
            }

            $counter = 0;
            foreach ($data[$key]['input'] as $item) {
                $counter++;
                $supplier = $suppliers[$item['party']];
                $this->submitOnce($invoiceService, $officer, $supplier, $this->inputPayload($supplier, $nema, $item, $key, $counter));
            }

            if (! VatReturnVersion::where('vat_period_id', $period->id)->exists()) {
                $lifecycle->generateReturn($period->id, $officer, 'nema-seed-return-'.$meta['code'], (string) Str::uuid());
            }
        }
    }

    /** @return array{0: Taxpayer, 1: Organisation} */
    private function registerParty(
        string $legalName, string $tradingName, string $vatNumber, string $tin, string $taxpayerType,
        string $returnFrequency, string $address, string $email, array $capabilities,
    ): array {
        $taxpayer = Taxpayer::updateOrCreate(
            ['vat_number' => $vatNumber],
            [
                'tin' => $tin, 'legal_name' => $legalName, 'trading_name' => $tradingName, 'taxpayer_type' => $taxpayerType,
                'vat_status' => 'ACTIVE', 'return_frequency' => $returnFrequency, 'address' => $address, 'email' => $email,
            ],
        );
        $organisation = Organisation::updateOrCreate(
            ['taxpayer_id' => $taxpayer->id],
            ['legal_name' => $taxpayer->legal_name, 'trading_name' => $taxpayer->trading_name, 'status' => 'ACTIVE'],
        );
        foreach ($capabilities as $capability) {
            OrganisationCapability::updateOrCreate(
                ['organisation_id' => $organisation->id, 'capability' => $capability],
                ['status' => 'ACTIVE', 'effective_from' => now()->subYear(), 'approved_by' => null, 'created_at' => now()],
            );
        }
        User::updateOrCreate(
            ['email' => "owner-{$vatNumber}@demo-taxpayer.test"],
            [
                'name' => "{$legalName} Owner", 'password' => Hash::make('password'), 'role' => 'TAXPAYER_OWNER',
                'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE', 'email_verified_at' => now(),
            ],
        );

        return [$taxpayer, $organisation];
    }

    private function submitOnce(InvoiceService $invoiceService, User $actor, Taxpayer $supplier, array $payload): void
    {
        $exists = Invoice::where('supplier_taxpayer_id', $supplier->id)
            ->where('source_system', $payload['source']['system_id'])
            ->where('source_document_id', $payload['source']['document_id'])
            ->exists();
        if ($exists) {
            return;
        }

        $invoiceService->submit($payload, $actor, 'nema-seed-'.sha1($payload['source']['document_id'].$supplier->id));
    }

    private function centsToStr(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), abs($cents) % 100);
    }

    private function outputPayload(Taxpayer $nema, array $item, string $periodKey, int $counter): array
    {
        $netCents = $item['net_cents'];
        $taxCents = $item['tax_cents'];
        $totalCents = $netCents + $taxCents;
        $rate = $item['category'] === 'STANDARD' ? '15.00' : '0.00';
        $invoiceNumber = "NEMA-OUT-{$periodKey}-".str_pad((string) $counter, 3, '0', STR_PAD_LEFT);

        return [
            'schema_version' => '1.0.0', 'invoice_number' => $invoiceNumber, 'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'nema-recon-seed', 'document_id' => $invoiceNumber, 'submitted_at' => $item['date'].'T09:00:00Z'],
            'supplier' => ['name' => $nema->legal_name, 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $nema->vat_number]]],
            'customer' => ['name' => $item['party'], 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'UNREG-'.Str::slug($item['party'])]]],
            'issue_date' => $item['date'], 'currency' => 'NAD',
            'lines' => [[
                'line_number' => 1, 'description' => $item['description'], 'quantity' => '1', 'unit_code' => 'EA',
                'unit_price' => $this->centsToStr($netCents), 'net_amount' => $this->centsToStr($netCents),
                'tax' => ['category' => $item['category'], 'rate' => $rate, 'taxable_amount' => $this->centsToStr($netCents), 'tax_amount' => $this->centsToStr($taxCents)],
            ]],
            'totals' => [
                'line_net_amount' => $this->centsToStr($netCents), 'tax_exclusive_amount' => $this->centsToStr($netCents),
                'tax_amount' => $this->centsToStr($taxCents), 'tax_inclusive_amount' => $this->centsToStr($totalCents),
                'payable_amount' => $this->centsToStr($totalCents),
            ],
        ];
    }

    private function inputPayload(Taxpayer $supplier, Taxpayer $nema, array $item, string $periodKey, int $counter): array
    {
        $netCents = $item['net_cents'];
        $taxCents = $item['tax_cents'];
        $totalCents = $netCents + $taxCents;
        $invoiceNumber = $item['invoice_no'] ?: ("NEMA-IN-{$periodKey}-".str_pad((string) $counter, 3, '0', STR_PAD_LEFT));

        return [
            'schema_version' => '1.0.0', 'invoice_number' => $invoiceNumber, 'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'nema-recon-seed', 'document_id' => $invoiceNumber, 'submitted_at' => $item['date'].'T09:00:00Z'],
            'supplier' => ['name' => $supplier->legal_name, 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $supplier->vat_number]]],
            'customer' => ['name' => $nema->legal_name, 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => $nema->vat_number]]],
            'issue_date' => $item['date'], 'currency' => 'NAD',
            'lines' => [[
                'line_number' => 1, 'description' => $item['description'], 'quantity' => '1', 'unit_code' => 'EA',
                'unit_price' => $this->centsToStr($netCents), 'net_amount' => $this->centsToStr($netCents),
                'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => $this->centsToStr($netCents), 'tax_amount' => $this->centsToStr($taxCents)],
            ]],
            'totals' => [
                'line_net_amount' => $this->centsToStr($netCents), 'tax_exclusive_amount' => $this->centsToStr($netCents),
                'tax_amount' => $this->centsToStr($taxCents), 'tax_inclusive_amount' => $this->centsToStr($totalCents),
                'payable_amount' => $this->centsToStr($totalCents),
            ],
        ];
    }
}
