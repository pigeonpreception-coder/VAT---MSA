<?php

/**
 * Post-deploy smoke test for a live VAT-MSA instance.
 *
 * Drives the real browser-facing golden path over plain HTTP (session
 * cookies + CSRF, exactly like a real user) against a deployed instance:
 * login -> upload a document (exercises whichever disk FILESYSTEM_DISK
 * currently points at -- local or a real S3/R2 bucket) -> run a report
 * -> request its export -> download the export and check its contents.
 *
 * This does NOT touch the database directly and needs no shell access to
 * the server -- it's meant to be run from anywhere against a public URL,
 * right after a deploy, before calling a rollout done.
 *
 * Usage:
 *   php scripts/smoke-test.php https://your-domain.example [email]
 *   SMOKE_TEST_PASSWORD=... php scripts/smoke-test.php https://your-domain.example you@real-domain.tld
 *
 * Defaults to the seeded demo login (owner@demo-trading.test / password)
 * if email is omitted entirely. For any other email, the password MUST
 * come from the SMOKE_TEST_PASSWORD environment variable, never a CLI
 * argument -- command-line arguments are visible to any other process on
 * the same host via /proc/<pid>/cmdline (or `ps aux` while this runs) and
 * commonly end up in shell history or CI job logs, which would leak a
 * real account's password for anyone who followed the old convenience of
 * passing it positionally.
 *
 * Exit code 0 if every step passes, 1 otherwise.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$baseUrl = rtrim($argv[1] ?? '', '/');
$email = $argv[2] ?? 'owner@demo-trading.test';

if ($baseUrl === '') {
    fwrite(STDERR, "Usage: php scripts/smoke-test.php https://your-domain.example [email]\n");
    fwrite(STDERR, "Set SMOKE_TEST_PASSWORD in the environment unless using the demo login.\n");
    exit(1);
}

$password = getenv('SMOKE_TEST_PASSWORD');
if ($password === false) {
    if ($email !== 'owner@demo-trading.test') {
        fwrite(STDERR, "SMOKE_TEST_PASSWORD is not set -- required for any email other than the demo login.\n");
        exit(1);
    }
    $password = 'password';
}

$cookieJar = tempnam(sys_get_temp_dir(), 'vatmsa-smoke-');
register_shutdown_function(function () use ($cookieJar) {
    if (is_file($cookieJar)) {
        unlink($cookieJar);
    }
});

/**
 * @param  array<string, string>  $fields
 * @param  array<string, string>  $files  path => [name, mime]
 * @return array{status: int, body: string, url: string}
 */
function httpRequest(string $baseUrl, string $cookieJar, string $method, string $path, array $fields = [], array $files = []): array
{
    $ch = curl_init($baseUrl.$path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($files !== []) {
            $payload = $fields;
            foreach ($files as $field => [$path2, $mime]) {
                $payload[$field] = new CURLFile($path2, $mime, basename($path2));
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error on {$method} {$path}: {$err}");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return ['status' => $status, 'body' => $body, 'url' => $url];
}

function extractCsrfToken(string $html): ?string
{
    if (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m)) {
        return html_entity_decode($m[1]);
    }

    return null;
}

/** route() renders absolute URLs -- strip the known base so callers always get a path. */
function toPath(string $baseUrl, string $url): string
{
    return str_starts_with($url, $baseUrl) ? substr($url, strlen($baseUrl)) : $url;
}

/**
 * Standalone RFC 6238 TOTP-SHA1 code generator, ported from
 * App\Support\Access\Totp::generateCode/hotp/base32Decode -- this script
 * runs standalone over plain HTTP against a deployed instance with no
 * access to the Laravel app itself, so the algorithm is duplicated here
 * rather than reused. Keep in sync with that class if the algorithm ever
 * changes (it shouldn't -- RFC 6238 is fixed).
 */
function totpCode(string $secretBase32, int $nowMs): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $clean = preg_replace('/[^A-Z2-7]/', '', strtoupper($secretBase32));
    $bits = 0;
    $value = 0;
    $secretBytes = '';
    foreach (str_split($clean) as $char) {
        $index = strpos($alphabet, $char);
        if ($index === false) {
            continue;
        }
        $value = ($value << 5) | $index;
        $bits += 5;
        if ($bits >= 8) {
            $secretBytes .= chr(($value >> ($bits - 8)) & 0xFF);
            $bits -= 8;
        }
    }

    $counter = intdiv(intdiv($nowMs, 1000), 30);
    $counterBytes = pack('N2', 0, $counter);
    $signature = hash_hmac('sha1', $counterBytes, $secretBytes, true);
    $offset = ord($signature[strlen($signature) - 1]) & 0x0F;
    $binary = ((ord($signature[$offset]) & 0x7F) << 24)
        | ((ord($signature[$offset + 1]) & 0xFF) << 16)
        | ((ord($signature[$offset + 2]) & 0xFF) << 8)
        | (ord($signature[$offset + 3]) & 0xFF);

    return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
}

/** @var list<array{name: string, ok: bool, detail: string}> */
$results = [];

function step(array &$results, string $name, callable $fn): mixed
{
    try {
        $value = $fn();
        $results[] = ['name' => $name, 'ok' => true, 'detail' => ''];

        return $value;
    } catch (Throwable $e) {
        $results[] = ['name' => $name, 'ok' => false, 'detail' => $e->getMessage()];
        echo "\n--- Smoke test stopped early ---\n";
        printSummary($results);
        exit(1);
    }
}

function printSummary(array $results): void
{
    echo "\nResults:\n";
    foreach ($results as $r) {
        printf("  [%s] %s%s\n", $r['ok'] ? 'PASS' : 'FAIL', $r['name'], $r['detail'] !== '' ? " -- {$r['detail']}" : '');
    }
}

echo "Smoke-testing {$baseUrl} as {$email}\n\n";

// 1. Fetch the login page and grab a CSRF token + session cookie.
$loginToken = step($results, 'GET /login', function () use ($baseUrl, $cookieJar) {
    $r = httpRequest($baseUrl, $cookieJar, 'GET', '/login');
    if ($r['status'] !== 200) {
        throw new RuntimeException("expected 200, got {$r['status']}");
    }
    $token = extractCsrfToken($r['body']);
    if ($token === null) {
        throw new RuntimeException('no CSRF token found on login page');
    }

    return $token;
});

// 2. Log in.
step($results, 'POST /login', function () use ($baseUrl, $cookieJar, $email, $password, $loginToken) {
    $r = httpRequest($baseUrl, $cookieJar, 'POST', '/login', [
        '_token' => $loginToken, 'email' => $email, 'password' => $password,
    ]);
    if ($r['status'] !== 200 || str_contains($r['body'], 'name="password"')) {
        throw new RuntimeException("login did not succeed (final status {$r['status']}, url {$r['url']})");
    }
});

// 3. Step up. A sensitive report's export (the demo seed's own
// SALES_VAT_SUMMARY/COMPLIANCE_CASELOAD, TAX_CONFIDENTIAL, or
// PORTFOLIO_EXCEPTIONS/CASE_EVIDENCE_SUMMARY, RESTRICTED) requires a
// fresh TOTP step-up confirmation (App\Services\Identity\MfaService::
// hasFreshStepUp, via App\Http\Middleware\EnsureFreshStepUp). VAT_POSITION
// itself (used below) is CONFIDENTIAL, not sensitive, and auto-approves --
// but step up anyway so this also smoke-tests the real MFA path a
// sensitive report's export actually needs.
//
// 2026-09-15 TOTP cutover: this used to re-confirm the account's own
// password (Laravel's `password.confirm`); that route and
// ConfirmPasswordController are gone. MfaService::enrollTotp() refuses to
// re-enrol an already-ACTIVE credential, and the real UI only ever shows
// a freshly-generated secret once (by design -- see MfaViewController's
// own doc comment), so a repeatable smoke test against a persistently-
// enrolled demo account has nowhere else to keep it: the secret is
// cached locally, once, right after the first successful enrolment.
$totpSecretCacheFile = sys_get_temp_dir().'/vatmsa-smoke-totp-'.md5($baseUrl.'|'.$email).'.secret';
step($results, 'TOTP step-up (POST /security/mfa + /security/mfa/verification + /security/step-up)', function () use ($baseUrl, $cookieJar, $totpSecretCacheFile) {
    $mfaGet = httpRequest($baseUrl, $cookieJar, 'GET', '/security/mfa');
    if ($mfaGet['status'] !== 200) {
        throw new RuntimeException("expected 200 on GET /security/mfa, got {$mfaGet['status']} -- does this user have identity:read?");
    }
    $token = extractCsrfToken($mfaGet['body']);
    if ($token === null) {
        throw new RuntimeException('no CSRF token found on the MFA page');
    }

    $secret = is_file($totpSecretCacheFile) ? trim((string) file_get_contents($totpSecretCacheFile)) : null;

    if ($secret === null) {
        if (! str_contains($mfaGet['body'], 'Not enabled')) {
            throw new RuntimeException('this account already has MFA enrolled but no locally cached secret is available -- '.
                "delete its mfa_totp_credentials row to let this script re-enrol, or restore {$totpSecretCacheFile}.");
        }
        $enroll = httpRequest($baseUrl, $cookieJar, 'POST', '/security/mfa', ['_token' => $token]);
        if (! preg_match('/Secret key<\/dt>\s*<dd[^>]*><code>([^<]+)<\/code>/', $enroll['body'], $m)) {
            throw new RuntimeException("enrolment did not show a fresh secret (status {$enroll['status']}, url {$enroll['url']})");
        }
        $secret = $m[1];
        if (file_put_contents($totpSecretCacheFile, $secret) === false) {
            throw new RuntimeException("could not cache the TOTP secret at {$totpSecretCacheFile}");
        }
        chmod($totpSecretCacheFile, 0600);

        $verifyToken = extractCsrfToken($enroll['body']);
        $verify = httpRequest($baseUrl, $cookieJar, 'POST', '/security/mfa/verification', [
            '_token' => $verifyToken, 'code' => totpCode($secret, (int) (microtime(true) * 1000)),
        ]);
        if ($verify['status'] !== 200 || ! str_contains($verify['body'], 'Enabled')) {
            throw new RuntimeException("TOTP verification did not activate the credential (status {$verify['status']}, url {$verify['url']})");
        }
        $token = extractCsrfToken($verify['body']) ?? $token;
    }

    // One TOTP step ahead of "now": guarantees a higher HOTP counter than
    // whatever code verification (if it just ran) already consumed, so
    // MfaService::confirmStepUp's own anti-replay check
    // (matchedCounter > last_used_counter) doesn't reject this as a
    // reused code, while still landing well within the server's own
    // real-time ±1-step drift tolerance for the near-instant round trip.
    $stepUp = httpRequest($baseUrl, $cookieJar, 'POST', '/security/step-up', [
        '_token' => $token, 'code' => totpCode($secret, (int) (microtime(true) * 1000) + 30_000),
    ]);
    if ($stepUp['status'] !== 200 || ! str_contains($stepUp['body'], 'Fresh')) {
        throw new RuntimeException("step-up confirmation did not succeed (status {$stepUp['status']}, url {$stepUp['url']})");
    }
});

// 4. Upload a document -- exercises the configured storage disk (local or R2).
$docToken = step($results, 'GET /documents (csrf)', function () use ($baseUrl, $cookieJar) {
    $r = httpRequest($baseUrl, $cookieJar, 'GET', '/documents');
    if ($r['status'] !== 200) {
        throw new RuntimeException("expected 200, got {$r['status']} -- does this user have documents:read?");
    }
    $token = extractCsrfToken($r['body']);
    if ($token === null) {
        throw new RuntimeException('no CSRF token found on documents page');
    }

    return $token;
});

$tmpPdf = tempnam(sys_get_temp_dir(), 'smoke-').'.pdf';
file_put_contents($tmpPdf, "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF");
register_shutdown_function(fn () => is_file($tmpPdf) && unlink($tmpPdf));

step($results, 'POST /documents (upload)', function () use ($baseUrl, $cookieJar, $docToken, $tmpPdf) {
    $r = httpRequest($baseUrl, $cookieJar, 'POST', '/documents', [
        '_token' => $docToken,
        'owner_domain' => 'EXPENSE',
        'owner_resource_id' => 'smoke-test-'.time(),
        'classification' => 'INTERNAL',
    ], [
        'file' => [$tmpPdf, 'application/pdf'],
    ]);
    if ($r['status'] !== 200 || ! str_contains($r['body'], 'Evidence quarantined')) {
        throw new RuntimeException("upload did not confirm (status {$r['status']}) -- check the configured FILESYSTEM_DISK is reachable");
    }
});

// 5. Run a report, request its export, and download it -- also exercises
//    the storage disk, via the export's own file write.
$reportsToken = step($results, 'GET /reports (csrf)', function () use ($baseUrl, $cookieJar) {
    $r = httpRequest($baseUrl, $cookieJar, 'GET', '/reports');
    if ($r['status'] !== 200) {
        throw new RuntimeException("expected 200, got {$r['status']} -- does this user have reports:read?");
    }
    $token = extractCsrfToken($r['body']);
    if ($token === null) {
        throw new RuntimeException('no CSRF token found on reports page');
    }

    return $token;
});

step($results, 'POST /reports/VAT_POSITION/run', function () use ($baseUrl, $cookieJar, $reportsToken) {
    $r = httpRequest($baseUrl, $cookieJar, 'POST', '/reports/VAT_POSITION/run', ['_token' => $reportsToken]);
    if ($r['status'] !== 200 || ! str_contains($r['body'], 'run inline')) {
        throw new RuntimeException("report run did not confirm (status {$r['status']}) -- is VAT_POSITION seeded and ACTIVE?");
    }
});

$exportRequestPath = step($results, 'find the run\'s export-request form', function () use ($baseUrl, $cookieJar) {
    $r = httpRequest($baseUrl, $cookieJar, 'GET', '/reports');
    if (! preg_match('#action="([^"]*/reports/runs/[0-9a-f-]{36}/export)"#', $r['body'], $m)) {
        throw new RuntimeException('no run with an export-request form found on the reports page');
    }

    return toPath($baseUrl, $m[1]);
});

step($results, 'POST '.$exportRequestPath, function () use ($baseUrl, $cookieJar, $reportsToken, $exportRequestPath) {
    $r = httpRequest($baseUrl, $cookieJar, 'POST', $exportRequestPath, ['_token' => $reportsToken]);
    if ($r['status'] !== 200 || ! str_contains($r['body'], 'Export requested')) {
        throw new RuntimeException("export request did not confirm (status {$r['status']})");
    }
});

$downloadPath = step($results, 'find the export\'s download link', function () use ($baseUrl, $cookieJar) {
    $r = httpRequest($baseUrl, $cookieJar, 'GET', '/reports');
    if (! preg_match('#href="([^"]*/reports/exports/[0-9a-f-]{36}/file)"#', $r['body'], $m)) {
        throw new RuntimeException('no approved export with a download link found -- was it auto-approved?');
    }

    return toPath($baseUrl, $m[1]);
});

step($results, 'GET '.$downloadPath, function () use ($baseUrl, $cookieJar, $downloadPath) {
    $r = httpRequest($baseUrl, $cookieJar, 'GET', $downloadPath);
    if ($r['status'] !== 200 || ! str_contains($r['body'], 'code:VAT_POSITION')) {
        throw new RuntimeException("export download did not return the expected CSV content (status {$r['status']}) -- check the configured FILESYSTEM_DISK is reachable");
    }
});

echo "\nAll steps passed.\n";
printSummary($results);
exit(0);
