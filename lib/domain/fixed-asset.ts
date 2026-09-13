/**
 * Operations > Immovable Asset Management and Movable Asset Management
 * (NamRA e-VAT MS master prompt section 16E): pure validation and the
 * register -> (active <-> under maintenance) -> disposed lifecycle for an
 * organisation's own immovable (land/buildings) and movable (vehicles,
 * equipment, furniture, IT hardware) assets. One shared table/domain module
 * backs both — the two are the same lifecycle with a different asset_class
 * discriminator, not two separate concepts, so the UI pages simply filter
 * on asset_class rather than duplicating this file.
 */

export type FixedAssetValidationMessage = { code: string; path: string; message: string };

export class FixedAssetValidationError extends Error {
  readonly messages: FixedAssetValidationMessage[];

  constructor(messages: FixedAssetValidationMessage[]) {
    super("Fixed asset command failed validation.");
    this.name = "FixedAssetValidationError";
    this.messages = messages;
  }
}

function object(payload: unknown) {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new FixedAssetValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be an object." }]);
  return payload as Record<string, unknown>;
}

function text(value: unknown) {
  return typeof value === "string" ? value.trim().replaceAll(/\s+/g, " ") : "";
}

function bounded(value: unknown, path: string, label: string, min: number, max: number, messages: FixedAssetValidationMessage[]) {
  const normalized = text(value);
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

function optionalBounded(value: unknown, path: string, label: string, min: number, max: number, messages: FixedAssetValidationMessage[]) {
  const normalized = text(value);
  if (!normalized) return undefined;
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

function schema(input: Record<string, unknown>, messages: FixedAssetValidationMessage[]) {
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
}

function money(value: unknown, path: string, label: string, messages: FixedAssetValidationMessage[], allowNull = false): number | null {
  if (value === null || value === undefined) {
    if (allowNull) return null;
    messages.push({ code: "FIELD_REQUIRED", path, message: `${label} is required.` });
    return 0;
  }
  const cents = Number(value);
  if (!Number.isInteger(cents) || cents < 0) messages.push({ code: "AMOUNT_INVALID", path, message: `${label} must be a non-negative integer number of cents.` });
  return cents;
}

const ASSET_CLASS_VALUES = new Set(["IMMOVABLE", "MOVABLE"]);
const IMMOVABLE_CATEGORY_VALUES = new Set(["LAND", "BUILDING", "OTHER"]);
const MOVABLE_CATEGORY_VALUES = new Set(["VEHICLE", "EQUIPMENT", "FURNITURE", "IT_HARDWARE", "OTHER"]);
const ISO_DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

export type FixedAssetRegistration = {
  schema_version: "1.0.0";
  asset_class: "IMMOVABLE" | "MOVABLE";
  asset_code: string;
  category: string;
  description: string;
  serial_or_registration_number?: string;
  location_or_address: string;
  custodian_employee_id?: string;
  acquisition_date: string;
  acquisition_cost_cents: number;
  current_value_cents: number | null;
};

/** RegisterFixedAsset. */
export function validateFixedAssetRegistration(payload: unknown): FixedAssetRegistration {
  const input = object(payload);
  const messages: FixedAssetValidationMessage[] = [];
  schema(input, messages);
  const assetClass = text(input.asset_class).toUpperCase();
  if (!ASSET_CLASS_VALUES.has(assetClass)) messages.push({ code: "ASSET_CLASS_INVALID", path: "/asset_class", message: "asset_class must be IMMOVABLE or MOVABLE." });
  const assetCode = bounded(input.asset_code, "/asset_code", "Asset code", 2, 40, messages).toUpperCase();
  const category = text(input.category).toUpperCase();
  const validCategories = assetClass === "IMMOVABLE" ? IMMOVABLE_CATEGORY_VALUES : MOVABLE_CATEGORY_VALUES;
  if (ASSET_CLASS_VALUES.has(assetClass) && !validCategories.has(category)) {
    messages.push({ code: "CATEGORY_INVALID", path: "/category", message: `category must be one of: ${[...validCategories].join(", ")}.` });
  }
  const description = bounded(input.description, "/description", "Description", 2, 300, messages);
  const serialOrRegistrationNumber = optionalBounded(input.serial_or_registration_number, "/serial_or_registration_number", "Serial or registration number", 1, 80, messages);
  const locationOrAddress = bounded(input.location_or_address, "/location_or_address", "Location or address", 2, 300, messages);
  const custodianEmployeeId = optionalBounded(input.custodian_employee_id, "/custodian_employee_id", "Custodian employee", 1, 80, messages);
  const acquisitionDate = text(input.acquisition_date);
  if (!ISO_DATE_PATTERN.test(acquisitionDate) || Number.isNaN(Date.parse(acquisitionDate))) messages.push({ code: "ACQUISITION_DATE_INVALID", path: "/acquisition_date", message: "acquisition_date must be an ISO date (YYYY-MM-DD)." });
  const acquisitionCostCents = money(input.acquisition_cost_cents, "/acquisition_cost_cents", "Acquisition cost", messages) ?? 0;
  const currentValueCents = money(input.current_value_cents, "/current_value_cents", "Current value", messages, true);
  if (messages.length) throw new FixedAssetValidationError(messages);
  return {
    schema_version: "1.0.0",
    asset_class: assetClass as "IMMOVABLE" | "MOVABLE",
    asset_code: assetCode,
    category,
    description,
    location_or_address: locationOrAddress,
    acquisition_date: acquisitionDate,
    acquisition_cost_cents: acquisitionCostCents,
    current_value_cents: currentValueCents,
    ...(serialOrRegistrationNumber ? { serial_or_registration_number: serialOrRegistrationNumber } : {}),
    ...(custodianEmployeeId ? { custodian_employee_id: custodianEmployeeId } : {}),
  };
}

export type FixedAssetValuation = { schema_version: "1.0.0"; current_value_cents: number };

/** RecordFixedAssetValuation. */
export function validateFixedAssetValuation(payload: unknown): FixedAssetValuation {
  const input = object(payload);
  const messages: FixedAssetValidationMessage[] = [];
  schema(input, messages);
  const currentValueCents = money(input.current_value_cents, "/current_value_cents", "Current value", messages) ?? 0;
  if (messages.length) throw new FixedAssetValidationError(messages);
  return { schema_version: "1.0.0", current_value_cents: currentValueCents };
}

export type FixedAssetDisposal = { schema_version: "1.0.0"; reason: string };

/** DisposeFixedAsset. */
export function validateFixedAssetDisposal(payload: unknown): FixedAssetDisposal {
  const input = object(payload);
  const messages: FixedAssetValidationMessage[] = [];
  schema(input, messages);
  const reason = bounded(input.reason, "/reason", "Reason", 10, 500, messages);
  if (messages.length) throw new FixedAssetValidationError(messages);
  return { schema_version: "1.0.0", reason };
}

export type FixedAssetStatus = "ACTIVE" | "UNDER_MAINTENANCE" | "DISPOSED";
export type FixedAssetAction = "FLAG_MAINTENANCE" | "RESTORE" | "DISPOSE";

const FIXED_ASSET_TRANSITIONS: Record<FixedAssetStatus, Partial<Record<FixedAssetAction, FixedAssetStatus>>> = {
  ACTIVE: { FLAG_MAINTENANCE: "UNDER_MAINTENANCE", DISPOSE: "DISPOSED" },
  UNDER_MAINTENANCE: { RESTORE: "ACTIVE", DISPOSE: "DISPOSED" },
  DISPOSED: {},
};

export function assertFixedAssetTransition(action: FixedAssetAction, current: string): FixedAssetStatus {
  const rule = FIXED_ASSET_TRANSITIONS[current as FixedAssetStatus];
  const target = rule?.[action];
  if (!target) throw new FixedAssetValidationError([{ code: "FIXED_ASSET_TRANSITION_INVALID", path: "/action", message: `Cannot ${action.toLowerCase().replaceAll("_", " ")} an asset currently ${current}.` }]);
  return target;
}
