export class PlatformValidationError extends Error {
  readonly messages: Array<{ code: string; path: string; message: string }>;
  constructor(messages: Array<{ code: string; path: string; message: string }>) {
    super("Platform command failed validation.");
    this.name = "PlatformValidationError";
    this.messages = messages;
  }
}

export type OfflineBatchSubmission = {
  device_id: string;
  batch_id: string;
  sequence_from: number;
  sequence_to: number;
  created_at: string;
  previous_batch_hash?: string;
  documents: unknown[];
  device_signature: string;
};

const ID_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/;
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const HASH_PATTERN = /^[a-f0-9]{64}$/i;
const ISO_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/;

function text(value: unknown) { return typeof value === "string" ? value.trim() : ""; }

export function validateOfflineBatch(payload: unknown): OfflineBatchSubmission {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new PlatformValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be an offline batch object." }]);
  const input = payload as Record<string, unknown>;
  const messages: Array<{ code: string; path: string; message: string }> = [];
  const deviceId = text(input.device_id);
  if (!ID_PATTERN.test(deviceId)) messages.push({ code: "DEVICE_ID_INVALID", path: "/device_id", message: "Device id is invalid." });
  const batchId = text(input.batch_id);
  if (!UUID_PATTERN.test(batchId)) messages.push({ code: "BATCH_ID_INVALID", path: "/batch_id", message: "Batch id must be a UUID." });
  const sequenceFrom = Number(input.sequence_from);
  const sequenceTo = Number(input.sequence_to);
  if (!Number.isSafeInteger(sequenceFrom) || sequenceFrom < 1) messages.push({ code: "SEQUENCE_INVALID", path: "/sequence_from", message: "sequence_from must be a positive safe integer." });
  if (!Number.isSafeInteger(sequenceTo) || sequenceTo < sequenceFrom) messages.push({ code: "SEQUENCE_INVALID", path: "/sequence_to", message: "sequence_to must be greater than or equal to sequence_from." });
  const createdAt = text(input.created_at);
  if (!ISO_PATTERN.test(createdAt) || Number.isNaN(Date.parse(createdAt))) messages.push({ code: "TIMESTAMP_INVALID", path: "/created_at", message: "created_at must be an ISO UTC timestamp." });
  const previousHash = text(input.previous_batch_hash) || undefined;
  if (previousHash && !HASH_PATTERN.test(previousHash)) messages.push({ code: "HASH_INVALID", path: "/previous_batch_hash", message: "Previous batch hash must contain 64 hexadecimal characters." });
  const documents = Array.isArray(input.documents) ? input.documents : [];
  if (documents.length < 1 || documents.length > 1_000) messages.push({ code: "DOCUMENT_COUNT_INVALID", path: "/documents", message: "An offline batch must contain 1 to 1000 documents." });
  if (Number.isSafeInteger(sequenceFrom) && Number.isSafeInteger(sequenceTo) && documents.length !== sequenceTo - sequenceFrom + 1) messages.push({ code: "SEQUENCE_DOCUMENT_MISMATCH", path: "/documents", message: "Document count must match the inclusive sequence range." });
  const signature = text(input.device_signature);
  if (signature.length < 32 || signature.length > 8_192) messages.push({ code: "SIGNATURE_INVALID", path: "/device_signature", message: "Device signature length is invalid." });
  if (messages.length) throw new PlatformValidationError(messages);
  return { device_id: deviceId, batch_id: batchId, sequence_from: sequenceFrom, sequence_to: sequenceTo, created_at: createdAt, ...(previousHash ? { previous_batch_hash: previousHash } : {}), documents, device_signature: signature };
}

export function validateReportParameters(payload: unknown) {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new PlatformValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "Report parameters must be an object." }]);
  const encoded = JSON.stringify(payload);
  if (encoded.length > 16_384) throw new PlatformValidationError([{ code: "PARAMETERS_TOO_LARGE", path: "/", message: "Report parameters must not exceed 16384 characters." }]);
  return payload as Record<string, unknown>;
}

export function safeFileName(value: string) {
  const leaf = value.replaceAll("\\", "/").split("/").pop()?.trim() ?? "evidence";
  const safe = leaf.replaceAll(/[^A-Za-z0-9._ -]/g, "_").slice(0, 180);
  return safe || "evidence";
}
