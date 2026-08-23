export type TaxCategory =
  | "STANDARD"
  | "ZERO_RATED"
  | "EXEMPT"
  | "OUTSIDE_SCOPE"
  | "REVERSE_CHARGE"
  | "OTHER";

export type DocumentType =
  | "TAX_INVOICE"
  | "SIMPLIFIED_TAX_INVOICE"
  | "CREDIT_NOTE"
  | "DEBIT_NOTE"
  | "SELF_BILLED_INVOICE";

export type PartyIdentifier = {
  type: "VAT_NUMBER" | "TIN" | "COMPANY_NUMBER" | "NATIONAL_ID" | "PASSPORT" | "OTHER";
  value: string;
  country?: string;
};

export type InvoiceParty = {
  vat_msa_taxpayer_id?: string;
  name: string;
  trading_name?: string;
  identifiers: PartyIdentifier[];
  email?: string;
  phone?: string;
};

export type InvoiceLineSubmission = {
  line_number: number;
  item_code?: string;
  description: string;
  quantity: string;
  unit_code: string;
  unit_price: string;
  net_amount: string;
  tax: {
    category: TaxCategory;
    rate: string;
    taxable_amount: string;
    tax_amount: string;
    exemption_reason_code?: string;
    rule_reference?: string;
  };
};

export type InvoiceSubmission = {
  schema_version: "1.0.0";
  document_type: DocumentType;
  source: {
    system_id: string;
    device_id?: string;
    document_id: string;
    submitted_at: string;
  };
  supplier: InvoiceParty;
  customer: InvoiceParty;
  invoice_number: string;
  issue_date: string;
  due_date?: string;
  currency: string;
  original_document_reference?: {
    source_document_id: string;
    vat_msa_invoice_id?: string;
    reason_code?: string;
    reason?: string;
  };
  lines: InvoiceLineSubmission[];
  totals: {
    line_net_amount: string;
    tax_exclusive_amount: string;
    tax_amount: string;
    tax_inclusive_amount: string;
    payable_amount: string;
  };
  notes?: string[];
};

export type CalculatedLine = InvoiceLineSubmission & {
  quantityMicros: number;
  unitPriceCents: number;
  netAmountCents: number;
  taxRateBps: number;
  taxAmountCents: number;
};

export type CalculatedInvoice = {
  lineNetCents: number;
  taxCents: number;
  totalCents: number;
  lines: CalculatedLine[];
};

export type RiskLevel = "LOW" | "MEDIUM" | "HIGH" | "CRITICAL";

export type UserContext = {
  userId: string;
  email: string;
  displayName: string;
  role: string;
  taxpayerId: string | null;
  organisationId: string | null;
  capabilities: string[];
  dynamicPermissions: string[];
  isDevelopmentIdentity: boolean;
};

export type InvoiceSummary = {
  id: string;
  invoiceNumber: string;
  documentType: string;
  supplierName: string;
  supplierVatNumber: string;
  customerName: string;
  customerVatNumber: string | null;
  issueDate: string;
  currency: string;
  lineNetCents: number;
  taxCents: number;
  totalCents: number;
  status: string;
  riskLevel: RiskLevel;
  transactionId: string;
  certificateId: string;
  verificationToken: string;
  certifiedAt: string;
};

export type InvoiceDetail = InvoiceSummary & {
  sourceSystem: string;
  sourceDocumentId: string;
  payloadHash: string;
  certificationHash: string;
  signature: string;
  signatureProfile: string;
  taxRuleSetId: string;
  taxRuleSetVersion: string;
  taxLegalAuthorityReference: string;
  correction: {
    originalInvoiceId: string;
    originalInvoiceNumber: string;
    correctionType: string;
    reasonCode: string | null;
    reason: string;
    status: string;
    createdAt: string;
  } | null;
  corrections: Array<{
    correctionInvoiceId: string;
    correctionInvoiceNumber: string;
    correctionType: string;
    reasonCode: string | null;
    reason: string;
    status: string;
    totalCents: number;
    createdAt: string;
  }>;
  lines: Array<{
    id: string;
    lineNumber: number;
    description: string;
    quantity: string;
    unitCode: string;
    unitPriceCents: number;
    netAmountCents: number;
    taxRateBps: number;
    taxCategory: string;
    taxAmountCents: number;
  }>;
  ledgerEntries: Array<{
    id: string;
    taxpayerName: string;
    entryType: string;
    direction: string;
    amountCents: number;
    period: string;
  }>;
};
