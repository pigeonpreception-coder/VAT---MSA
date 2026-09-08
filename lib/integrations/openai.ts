type AnalyticsModelProvider = "deterministic" | "openai";
type OpenAiModelId = "gpt-5.6-sol" | "gpt-6-astra";
type ReasoningEffort = "low" | "medium" | "high" | "xhigh" | "max";

type OpenAiResponseContent = { text?: string };
type OpenAiResponseOutput = { content?: OpenAiResponseContent[] };
type OpenAiResponsePayload = {
  id?: string;
  status?: string;
  output_text?: string;
  output?: OpenAiResponseOutput[];
  error?: { message?: string; code?: string };
};

const OPENAI_MODELS = new Set<OpenAiModelId>(["gpt-5.6-sol", "gpt-6-astra"]);
const REASONING_EFFORTS = new Set<ReasoningEffort>(["low", "medium", "high", "xhigh", "max"]);
const DEFAULT_BASE_URL = "https://api.openai.com/v1";
const DEFAULT_MODEL: OpenAiModelId = "gpt-5.6-sol";
const DEFAULT_REASONING: ReasoningEffort = "low";
const DEFAULT_TIMEOUT_MS = 30_000;

export class OpenAiIntegrationError extends Error {
  readonly status: number;

  constructor(message: string, status = 502) {
    super(message);
    this.name = "OpenAiIntegrationError";
    this.status = status;
  }
}

function readEnv(name: string): string {
  return (process.env[name] ?? "").trim();
}

export function getAnalyticsModelProvider(): AnalyticsModelProvider {
  const provider = (readEnv("VAT_MSA_ANALYTICS_MODEL_PROVIDER") || "deterministic").toLowerCase();
  if (provider === "deterministic" || provider === "openai") return provider;
  throw new OpenAiIntegrationError("VAT_MSA_ANALYTICS_MODEL_PROVIDER must be deterministic or openai.", 503);
}

function getOpenAiApiKey(): string {
  return readEnv("VAT_MSA_OPENAI_API_KEY") || readEnv("OPENAI_API_KEY");
}

function getOpenAiModel(): OpenAiModelId {
  const model = readEnv("VAT_MSA_OPENAI_MODEL") || DEFAULT_MODEL;
  if (OPENAI_MODELS.has(model as OpenAiModelId)) return model as OpenAiModelId;
  throw new OpenAiIntegrationError("VAT_MSA_OPENAI_MODEL must be gpt-5.6-sol or gpt-6-astra.", 503);
}

function getReasoningEffort(): ReasoningEffort {
  const effort = (readEnv("VAT_MSA_OPENAI_REASONING_EFFORT") || DEFAULT_REASONING).toLowerCase();
  if (REASONING_EFFORTS.has(effort as ReasoningEffort)) return effort as ReasoningEffort;
  throw new OpenAiIntegrationError("VAT_MSA_OPENAI_REASONING_EFFORT must be low, medium, high, xhigh or max.", 503);
}

function getBaseUrl(): string {
  const raw = readEnv("VAT_MSA_OPENAI_BASE_URL") || DEFAULT_BASE_URL;
  try {
    const url = new URL(raw);
    if (url.protocol !== "https:") throw new Error("Only HTTPS is allowed.");
    return url.toString().replace(/\/$/, "");
  } catch {
    throw new OpenAiIntegrationError("VAT_MSA_OPENAI_BASE_URL must be a valid HTTPS URL.", 503);
  }
}

function getTimeoutMs(): number {
  const raw = readEnv("VAT_MSA_OPENAI_TIMEOUT_MS");
  if (!raw) return DEFAULT_TIMEOUT_MS;
  const value = Number(raw);
  if (!Number.isSafeInteger(value) || value < 1_000 || value > 120_000) {
    throw new OpenAiIntegrationError("VAT_MSA_OPENAI_TIMEOUT_MS must be an integer from 1000 to 120000.", 503);
  }
  return value;
}

export function getOpenAiAnalyticsConfig() {
  const apiKey = getOpenAiApiKey();
  if (!apiKey) throw new OpenAiIntegrationError("OpenAI analytics is enabled, but no OPENAI_API_KEY or VAT_MSA_OPENAI_API_KEY secret is configured.", 503);
  return {
    apiKey,
    baseUrl: getBaseUrl(),
    model: getOpenAiModel(),
    reasoningEffort: getReasoningEffort(),
    timeoutMs: getTimeoutMs(),
  };
}

function extractOutputText(payload: OpenAiResponsePayload): string {
  if (typeof payload.output_text === "string") return payload.output_text.trim();
  const parts: string[] = [];
  for (const item of payload.output ?? []) {
    for (const content of item.content ?? []) {
      if (typeof content.text === "string") parts.push(content.text);
    }
  }
  return parts.join("").trim();
}

function parseResponseJson(text: string): Record<string, unknown> {
  try {
    const value = JSON.parse(text) as unknown;
    if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error("not an object");
    return value as Record<string, unknown>;
  } catch {
    throw new OpenAiIntegrationError("OpenAI returned a model output that was not a JSON object.", 502);
  }
}

function stableValue(value: unknown): string {
  return JSON.stringify(value);
}

export function assertGovernedAnalyticsOutput(sourceResult: Record<string, unknown>, modelResult: Record<string, unknown>): Record<string, unknown> {
  const sourceKeys = Object.keys(sourceResult).filter((key) => key !== "suppressed");
  const outputKeys = Object.keys(modelResult);
  const allowed = new Set(sourceKeys);
  const unknownKey = outputKeys.find((key) => !allowed.has(key));
  if (unknownKey) throw new OpenAiIntegrationError(`OpenAI returned an unexpected analytics field: ${unknownKey}.`, 502);
  if (outputKeys.length !== sourceKeys.length) throw new OpenAiIntegrationError("OpenAI did not return every governed analytics field.", 502);

  const normalized: Record<string, unknown> = {};
  for (const key of sourceKeys) {
    if (stableValue(modelResult[key]) !== stableValue(sourceResult[key])) {
      throw new OpenAiIntegrationError(`OpenAI changed the governed analytics value for ${key}.`, 502);
    }
    normalized[key] = modelResult[key];
  }
  return normalized;
}

export async function runOpenAiAnalyticsModel(input: { dataProductId: string; reportRunId: string; sourceResult: Record<string, unknown> }) {
  const config = getOpenAiAnalyticsConfig();
  const responseBody = {
    model: config.model,
    instructions: [
      "You are a VAT-MSA governed analytics adapter.",
      "Return only a compact JSON object.",
      "Copy the provided source_result fields exactly, excluding any suppressed flag.",
      "Do not infer, enrich, explain, transform, round, redact or add fields.",
    ].join(" "),
    input: [{
      role: "user",
      content: [{
        type: "input_text",
        text: JSON.stringify({
          data_product_id: input.dataProductId,
          report_run_id: input.reportRunId,
          source_result: input.sourceResult,
        }),
      }],
    }],
    reasoning: { effort: config.reasoningEffort },
    text: { format: { type: "json_object" } },
    store: false,
  };

  let response: Response;
  try {
    response = await fetch(`${config.baseUrl}/responses`, {
      method: "POST",
      headers: { authorization: `Bearer ${config.apiKey}`, "content-type": "application/json" },
      body: JSON.stringify(responseBody),
      signal: AbortSignal.timeout(config.timeoutMs),
    });
  } catch (error) {
    if (error instanceof Error && (error.name === "AbortError" || error.name === "TimeoutError")) {
      throw new OpenAiIntegrationError("OpenAI analytics request timed out before a governed result was returned.", 504);
    }
    throw new OpenAiIntegrationError("OpenAI analytics request could not be completed.", 502);
  }

  let payload: OpenAiResponsePayload;
  try {
    payload = await response.json() as OpenAiResponsePayload;
  } catch {
    throw new OpenAiIntegrationError("OpenAI returned a non-JSON API response.", 502);
  }

  if (!response.ok) {
    const reason = payload.error?.message ? ` ${payload.error.message}` : "";
    throw new OpenAiIntegrationError(`OpenAI analytics request failed with HTTP ${response.status}.${reason}`, response.status === 401 || response.status === 403 || response.status === 404 ? 503 : 502);
  }
  if (payload.status && payload.status !== "completed") {
    throw new OpenAiIntegrationError(`OpenAI analytics response status was ${payload.status}, not completed.`, 502);
  }

  const outputText = extractOutputText(payload);
  if (!outputText) throw new OpenAiIntegrationError("OpenAI completed without returning analytics output text.", 502);
  const modelResult = parseResponseJson(outputText);
  return {
    output: assertGovernedAnalyticsOutput(input.sourceResult, modelResult),
    model: config.model,
    responseId: payload.id ?? null,
  };
}
