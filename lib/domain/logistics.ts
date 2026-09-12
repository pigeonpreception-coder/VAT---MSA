/**
 * Operations > Logistics Module (NamRA e-VAT MS master prompt section 16E):
 * pure validation and the create -> dispatch -> deliver lifecycle (or
 * cancel, from either PENDING or IN_TRANSIT) for a delivery of goods already
 * sold — a delivery always references the tax invoice (or, once built, the
 * POS sale) it is fulfilling; it never invents its own separate sale record.
 */

export type LogisticsValidationMessage = { code: string; path: string; message: string };

export class LogisticsValidationError extends Error {
  readonly messages: LogisticsValidationMessage[];

  constructor(messages: LogisticsValidationMessage[]) {
    super("Logistics command failed validation.");
    this.name = "LogisticsValidationError";
    this.messages = messages;
  }
}

function object(payload: unknown) {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new LogisticsValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be an object." }]);
  return payload as Record<string, unknown>;
}

function text(value: unknown) {
  return typeof value === "string" ? value.trim().replaceAll(/\s+/g, " ") : "";
}

function bounded(value: unknown, path: string, label: string, min: number, max: number, messages: LogisticsValidationMessage[]) {
  const normalized = text(value);
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

function optionalBounded(value: unknown, path: string, label: string, min: number, max: number, messages: LogisticsValidationMessage[]) {
  const normalized = text(value);
  if (!normalized) return undefined;
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

function schema(input: Record<string, unknown>, messages: LogisticsValidationMessage[]) {
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
}

const REFERENCE_TYPE_VALUES = new Set(["INVOICE", "POS_SALE", "OTHER"]);

export type LogisticsDeliveryCreation = {
  schema_version: "1.0.0";
  delivery_number: string;
  reference_type: string;
  reference_id?: string;
  origin: string;
  destination: string;
  vehicle_asset_id?: string;
  notes?: string;
};

/** CreateDelivery. */
export function validateLogisticsDeliveryCreation(payload: unknown): LogisticsDeliveryCreation {
  const input = object(payload);
  const messages: LogisticsValidationMessage[] = [];
  schema(input, messages);
  const deliveryNumber = bounded(input.delivery_number, "/delivery_number", "Delivery number", 2, 40, messages).toUpperCase();
  const referenceType = text(input.reference_type).toUpperCase();
  if (!REFERENCE_TYPE_VALUES.has(referenceType)) messages.push({ code: "REFERENCE_TYPE_INVALID", path: "/reference_type", message: `reference_type must be one of: ${[...REFERENCE_TYPE_VALUES].join(", ")}.` });
  const referenceId = optionalBounded(input.reference_id, "/reference_id", "Reference", 1, 80, messages);
  if (referenceType !== "OTHER" && !referenceId) messages.push({ code: "REFERENCE_ID_REQUIRED", path: "/reference_id", message: "reference_id is required unless reference_type is OTHER." });
  const origin = bounded(input.origin, "/origin", "Origin", 2, 300, messages);
  const destination = bounded(input.destination, "/destination", "Destination", 2, 300, messages);
  const vehicleAssetId = optionalBounded(input.vehicle_asset_id, "/vehicle_asset_id", "Vehicle asset", 1, 80, messages);
  const notes = optionalBounded(input.notes, "/notes", "Notes", 1, 500, messages);
  if (messages.length) throw new LogisticsValidationError(messages);
  return {
    schema_version: "1.0.0", delivery_number: deliveryNumber, reference_type: referenceType, origin, destination,
    ...(referenceId ? { reference_id: referenceId } : {}), ...(vehicleAssetId ? { vehicle_asset_id: vehicleAssetId } : {}), ...(notes ? { notes } : {}),
  };
}

export type LogisticsDeliveryCancellation = { schema_version: "1.0.0"; reason: string };

/** CancelDelivery. */
export function validateLogisticsDeliveryCancellation(payload: unknown): LogisticsDeliveryCancellation {
  const input = object(payload);
  const messages: LogisticsValidationMessage[] = [];
  schema(input, messages);
  const reason = bounded(input.reason, "/reason", "Reason", 10, 500, messages);
  if (messages.length) throw new LogisticsValidationError(messages);
  return { schema_version: "1.0.0", reason };
}

export type LogisticsDeliveryStatus = "PENDING" | "IN_TRANSIT" | "DELIVERED" | "CANCELLED";
export type LogisticsDeliveryAction = "DISPATCH" | "DELIVER" | "CANCEL";

const LOGISTICS_DELIVERY_TRANSITIONS: Record<LogisticsDeliveryStatus, Partial<Record<LogisticsDeliveryAction, LogisticsDeliveryStatus>>> = {
  PENDING: { DISPATCH: "IN_TRANSIT", CANCEL: "CANCELLED" },
  IN_TRANSIT: { DELIVER: "DELIVERED", CANCEL: "CANCELLED" },
  DELIVERED: {},
  CANCELLED: {},
};

export function assertLogisticsDeliveryTransition(action: LogisticsDeliveryAction, current: string): LogisticsDeliveryStatus {
  const rule = LOGISTICS_DELIVERY_TRANSITIONS[current as LogisticsDeliveryStatus];
  const target = rule?.[action];
  if (!target) throw new LogisticsValidationError([{ code: "LOGISTICS_DELIVERY_TRANSITION_INVALID", path: "/action", message: `Cannot ${action.toLowerCase()} a delivery currently ${current}.` }]);
  return target;
}
