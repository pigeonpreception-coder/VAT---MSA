/**
 * Test-only stand-in for `next/navigation`. Only present because
 * app/chatgpt-auth.ts imports `redirect` at module scope; getCurrentUser()
 * (the only path Module 1 route tests exercise) calls getChatGPTUser(), never
 * requireChatGPTUser(), so this should never actually run — it throws rather
 * than silently no-op so a future path that does call it fails loudly.
 */
export function redirect(url: string): never {
  throw new Error(`Unexpected next/navigation redirect() in a test: ${url}`);
}
