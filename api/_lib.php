<?php
// Funções comuns do backend de pedidos de entrada.
// Os pedidos ficam numa pasta FORA do site público (ao lado de public_html), para que
// as publicações automáticas do GitHub não apaguem nada e ninguém leia pelo navegador.

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit; }

define('DATA_DIR', dirname(__DIR__, 2) . '/doteiros_dados');

function data_path(string $name): string {
    if (!is_dir(DATA_DIR)) { mkdir(DATA_DIR, 0700, true); }
    return DATA_DIR . '/' . $name;
}

function read_json(string $name, $default) {
    $f = data_path($name);
    if (!is_file($f)) return $default;
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : $default;
}

function write_json(string $name, $data): void {
    $f = data_path($name);
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $f);
}

// Executa $fn com uma trava exclusiva, para dois pedidos simultâneos não se sobrescreverem.
function with_lock(callable $fn) {
    $h = fopen(data_path('trava.lock'), 'c');
    flock($h, LOCK_EX);
    try { return $fn(); } finally { flock($h, LOCK_UN); fclose($h); }
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Aceita o número da conta (32 bits), o SteamID64 ou links do Dotabuff, OpenDota, Stratz e Steam.
function parse_account_id($raw): ?int {
    $raw = trim((string) $raw);
    if (preg_match('~(?:dotabuff\.com|opendota\.com|stratz\.com)/players/(\d+)~i', $raw, $m)) { $raw = $m[1]; }
    elseif (preg_match('~steamcommunity\.com/profiles/(\d{17})~i', $raw, $m)) { $raw = $m[1]; }
    if (!preg_match('~^\d{1,20}$~', $raw)) return null;
    if (strlen($raw) === 17 && strncmp($raw, '7656119', 7) === 0) {
        $n = (int) $raw - 76561197960265728;
    } else {
        $n = (int) $raw;
    }
    return ($n > 0 && $n < 4294967296) ? $n : null;
}

function http_get_json(string $url): ?array {
    $body = false;
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'doteiros.com.br']);
        $body = curl_exec($c);
        $ok = curl_getinfo($c, CURLINFO_HTTP_CODE) === 200;
        curl_close($c);
        if (!$ok) $body = false;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 12, 'header' => "User-Agent: doteiros.com.br\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
    }
    if ($body === false) return null;
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

function opendota_profile(int $id): ?array {
    return http_get_json("https://api.opendota.com/api/players/$id");
}

// Lista de quem já está no ranking (gerada a cada atualização em doteiros-TO/data/roster.json).
function current_roster(): array {
    $f = dirname(__DIR__) . '/doteiros-TO/data/roster.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
    return is_array($d) ? $d : [];
}

function client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
    }
    return '0';
}

function clean_nick($s): string {
    $s = trim(preg_replace('~\s+~u', ' ', strip_tags((string) $s)));
    return mb_substr($s, 0, 24);
}

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
