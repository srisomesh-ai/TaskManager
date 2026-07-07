<?php
// ============================================================
// Customer request link tokens — 6h expiry + single use
// ============================================================
if (!defined('REQ_TOKEN_SECRET')) define('REQ_TOKEN_SECRET', 'bgps_req_2026_x7k');

// Make a token for a given form type, valid for $hours
function reqMakeToken(string $type, int $hours = 6): string {
    $expiry = time() + ($hours * 3600);
    $payload = $type . '|' . $expiry;
    $sig = substr(hash_hmac('sha256', $payload, REQ_TOKEN_SECRET), 0, 16);
    return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
}

// Validate a token. Returns ['valid'=>bool,'expired'=>bool,'used'=>bool,'type'=>string,'hash'=>string]
function reqCheckToken($pdo, string $token, string $expectType): array {
    $out = ['valid'=>false,'expired'=>false,'used'=>false,'type'=>'','hash'=>''];
    if ($token === '') { return $out; }
    $raw = base64_decode(strtr($token, '-_', '+/'));
    if ($raw === false) return $out;
    $parts = explode('|', $raw);
    if (count($parts) !== 3) return $out;
    list($type, $expiry, $sig) = $parts;
    $expect = substr(hash_hmac('sha256', $type . '|' . $expiry, REQ_TOKEN_SECRET), 0, 16);
    if (!hash_equals($expect, $sig)) return $out;         // tampered
    if ($type !== $expectType) return $out;               // wrong form
    $out['type'] = $type;
    $out['hash'] = hash('sha256', $token);
    if (time() > intval($expiry)) { $out['expired'] = true; return $out; }

    // single-use check
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS used_tokens (id INT AUTO_INCREMENT PRIMARY KEY, token_hash VARCHAR(64) UNIQUE NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $chk = $pdo->prepare("SELECT id FROM used_tokens WHERE token_hash=?");
        $chk->execute([$out['hash']]);
        if ($chk->fetch()) { $out['used'] = true; return $out; }
    } catch (Exception $e) {}

    $out['valid'] = true;
    return $out;
}

// Mark a token used after successful submit
function reqMarkUsed($pdo, string $tokenHash): void {
    if ($tokenHash === '') return;
    try { $pdo->prepare("INSERT IGNORE INTO used_tokens (token_hash) VALUES (?)")->execute([$tokenHash]); } catch (Exception $e) {}
}
