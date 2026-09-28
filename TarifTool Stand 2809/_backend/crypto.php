<?php
// ============================================================
// _backend/crypto.php  –  AES-256-CBC Ver-/Entschlüsselung
// ============================================================

function encrypt(string $plaintext): string {
    $iv        = random_bytes(16);
    $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

function decrypt(string $ciphertext): string {
    $data = base64_decode($ciphertext);
    if (strlen($data) < 16) return '';
    $iv  = substr($data, 0, 16);
    $enc = substr($data, 16);
    return (string) openssl_decrypt($enc, 'AES-256-CBC', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
}

function generate_token(): string {
    return bin2hex(random_bytes(32)); // 64-stelliger Hex-String
}
