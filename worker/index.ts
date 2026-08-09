/** Cloudflare Worker entry point for the vinext-starter template. */
import { handleImageOptimization, DEFAULT_DEVICE_SIZES, DEFAULT_IMAGE_SIZES } from "vinext/server/image-optimization";
import handler from "vinext/server/app-router-entry";

interface Env {
  ASSETS: Fetcher;
  DB: D1Database;
  IMAGES: {
    input(stream: ReadableStream): {
      transform(options: Record<string, unknown>): {
        output(options: { format: string; quality: number }): Promise<{ response(): Response }>;
      };
    };
  };
}

interface ExecutionContext {
  waitUntil(promise: Promise<unknown>): void;
  passThroughOnException(): void;
}

// Image security config. SVG sources with .svg extension auto-skip the
// optimization endpoint on the client side (served directly, no proxy).
// To route SVGs through the optimizer (with security headers), set
// dangerouslyAllowSVG: true in next.config.js and uncomment below:
// const imageConfig: ImageConfig = { dangerouslyAllowSVG: true };

const worker = {
  async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    const url = new URL(request.url);
    const correlationId = /^[0-9a-f-]{36}$/i.test(request.headers.get("x-correlation-id") ?? "")
      ? request.headers.get("x-correlation-id")!
      : crypto.randomUUID();

    if (request.method === "TRACE" || request.method === "CONNECT") {
      return secureResponse(request, Response.json({ title: "Method not allowed", status: 405, correlation_id: correlationId }, { status: 405, headers: { allow: "GET, HEAD, POST, OPTIONS" } }), correlationId);
    }
    if (url.pathname.length + url.search.length > 8_192) {
      return secureResponse(request, Response.json({ title: "URI too long", status: 414, correlation_id: correlationId }, { status: 414 }), correlationId);
    }

    if (url.pathname === "/_vinext/image") {
      const allowedWidths = [...DEFAULT_DEVICE_SIZES, ...DEFAULT_IMAGE_SIZES];
      const response = await handleImageOptimization(request, {
        fetchAsset: (path) => env.ASSETS.fetch(new Request(new URL(path, request.url))),
        transformImage: async (body, { width, format, quality }) => {
          const result = await env.IMAGES.input(body).transform(width > 0 ? { width } : {}).output({ format, quality });
          return result.response();
        },
      }, allowedWidths);
      return secureResponse(request, response, correlationId);
    }

    const response = await handler.fetch(request, env, ctx);
    return secureResponse(request, response, correlationId);
  },
};

function secureResponse(request: Request, response: Response, correlationId: string): Response {
  const secured = new Response(response.body, response);
  const headers = secured.headers;
  headers.set("x-correlation-id", headers.get("x-correlation-id") ?? correlationId);
  headers.set("x-content-type-options", "nosniff");
  headers.set("x-frame-options", "DENY");
  headers.set("referrer-policy", "strict-origin-when-cross-origin");
  headers.set("permissions-policy", "camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()");
  headers.set("cross-origin-opener-policy", "same-origin");
  headers.set("cross-origin-resource-policy", "same-site");
  const contentSecurityPolicy = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self' ws: wss:; manifest-src 'self'";
  headers.set("content-security-policy", new URL(request.url).protocol === "https:" ? `${contentSecurityPolicy}; upgrade-insecure-requests` : contentSecurityPolicy);
  headers.delete("server");

  const url = new URL(request.url);
  if (url.protocol === "https:") headers.set("strict-transport-security", "max-age=63072000; includeSubDomains; preload");
  if (url.pathname.startsWith("/api/v1/verify/")) {
    headers.set("cache-control", "public, max-age=60, stale-while-revalidate=300");
  } else if (url.pathname.startsWith("/api/") || !url.pathname.match(/\.(?:css|js|png|svg|ico|woff2?)$/i)) {
    headers.set("cache-control", "no-store");
  } else {
    headers.set("cache-control", "public, max-age=31536000, immutable");
  }
  return secured;
}

export default worker;
