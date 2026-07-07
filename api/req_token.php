<?php
// ============================================================
// Customer request link tokens - 6h expiry + single use
// Payload: type|expiry|price|gst|sig   (price in rupees, gst = 1/0)
// ============================================================
if (!defined('REQ_TOKEN_SECRET')) define('REQ_TOKEN_SECRET', 'bgps_req_2026_x7k');

// Make a token. $price = final price (rupees), $gst = 1 to add 18% GST, else 0.
function reqMakeToken(string $type, int $hours = 6, float $price = 0, int $gst = 0): string {
    $expiry = time() + ($hours * 3600);
    $price = round($price, 2);
    $gst = $gst ? 1 : 0;
    $payload = $type . '|' . $expiry . '|' . $price . '|' . $gst;
    $sig = substr(hash_hmac('sha256', $payload, REQ_TOKEN_SECRET), 0, 16);
    return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
}

// Validate a token. Returns valid/expired/used/type/hash/price/gst
function reqCheckToken($pdo, string $token, string $expectType): array {
    $out = ['valid'=>false,'expired'=>false,'used'=>false,'type'=>'','hash'=>'','price'=>0.0,'gst'=>0];
    if ($token === '') { return $out; }
    $raw = base64_decode(strtr($token, '-_', '+/'));
    if ($raw === false) return $out;
    $parts = explode('|', $raw);

    // Support both new (5-part) and legacy (3-part) tokens
    if (count($parts) === 5) {
        list($type, $expiry, $price, $gst, $sig) = $parts;
        $payload = $type . '|' . $expiry . '|' . $price . '|' . $gst;
    } elseif (count($parts) === 3) {
        list($type, $expiry, $sig) = $parts;
        $payload = $type . '|' . $expiry;
        $price = 0; $gst = 0;
    } else {
        return $out;
    }

    $expect = substr(hash_hmac('sha256', $payload, REQ_TOKEN_SECRET), 0, 16);
    if (!hash_equals($expect, $sig)) return $out;         // tampered
    if ($type !== $expectType) return $out;               // wrong form
    $out['type'] = $type;
    $out['price'] = floatval($price);
    $out['gst'] = intval($gst) ? 1 : 0;
    $out['hash'] = hash('sha256', $token);
    if (time() > intval($expiry)) { $out['expired'] = true; return $out; }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS used_tokens (id INT AUTO_INCREMENT PRIMARY KEY, token_hash VARCHAR(64) UNIQUE NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $chk = $pdo->prepare("SELECT id FROM used_tokens WHERE token_hash=?");
        $chk->execute([$out['hash']]);
        if ($chk->fetch()) { $out['used'] = true; return $out; }
    } catch (Exception $e) {}

    $out['valid'] = true;
    return $out;
}

function reqMarkUsed($pdo, string $tokenHash): void {
    if ($tokenHash === '') return;
    try { $pdo->prepare("INSERT IGNORE INTO used_tokens (token_hash) VALUES (?)")->execute([$tokenHash]); } catch (Exception $e) {}
}
