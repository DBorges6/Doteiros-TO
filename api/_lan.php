<?php
// Núcleo da LAN Party: eventos, inscrições, partidas de lobby e cache das partidas do OpenDota.
// Cada evento tem seus próprios arquivos em doteiros_dados/ (lan_<evento>_*.json).
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit; }
require_once __DIR__ . '/_lib.php';

// Eventos. Para uma próxima LAN, acrescente um item e mova 'atual' => true para ele.
const LAN_EVENTOS = [
    '2026-10-17' => [
        'nome' => 'LAN Party Doteiros',
        'inicio' => '2026-10-17T08:00:00-03:00',
        'fim' => '2026-10-17T22:00:00-03:00',
        'atual' => true,
    ],
];

function lan_evento(?string $id = null): array {
    if ($id === null || !isset(LAN_EVENTOS[$id])) {
        foreach (LAN_EVENTOS as $k => $e) { if (!empty($e['atual'])) { $id = $k; break; } }
    }
    $e = LAN_EVENTOS[$id];
    $e['id'] = $id;
    $e['ini'] = strtotime($e['inicio']);
    $e['end'] = strtotime($e['fim']);
    return $e;
}

function lan_file(array $ev, string $k): string {
    return 'lan_' . preg_replace('~[^a-z0-9-]~', '', $ev['id']) . '_' . $k . '.json';
}

function http_post_ok(string $url): bool {
    if (!function_exists('curl_init')) return false;
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'doteiros.com.br']);
    curl_exec($c);
    $ok = curl_getinfo($c, CURLINFO_HTTP_CODE) < 300;
    curl_close($c);
    return $ok;
}

// ---------- Inscrições ----------
function lan_inscritos(array $ev, string $status = 'aprovado'): array {
    return array_values(array_filter(read_json(lan_file($ev, 'inscricoes'), []), fn($p) => ($p['status'] ?? '') === $status));
}

// Aprova uma inscrição (chamar DENTRO de with_lock). Também aprova no ranking geral (exceto no evento de teste).
function lan_aprovar(array $ev, int $id, string $nick = ''): void {
    $f = lan_file($ev, 'inscricoes');
    $q = read_json($f, []);
    $aprovado = null;
    foreach ($q as &$p) {
        if ((int) $p['id'] !== $id) continue;
        $p['status'] = 'aprovado';
        if (mb_strlen($nick) >= 2) $p['apelido'] = $nick;
        $p['decidido'] = time();
        $aprovado = $p;
    }
    unset($p);
    write_json($f, $q);
    if ($aprovado && empty($ev['teste'])) lan_entrar_no_ranking($aprovado);
}

function lan_entrar_no_ranking(array $p): void {
    foreach (current_roster() as $r) { if ((int) $r['id'] === (int) $p['id']) return; }
    $q = read_json('pedidos.json', []);
    foreach ($q as &$x) {
        if ((int) $x['id'] !== (int) $p['id']) continue;
        if ($x['status'] !== 'aprovado') { $x['status'] = 'aprovado'; $x['decidido'] = time(); $x['origem'] = 'lan'; write_json('pedidos.json', $q); }
        return;
    }
    unset($x);
    $q[] = ['id' => (int) $p['id'], 'apelido' => $p['apelido'], 'nomeSteam' => $p['nomeSteam'] ?? '', 'avatar' => $p['avatar'] ?? '',
        'medalha' => $p['medalha'] ?? null, 'privadoOpenDota' => $p['privadoOpenDota'] ?? false,
        'status' => 'aprovado', 'criado' => time(), 'decidido' => time(), 'origem' => 'lan'];
    write_json('pedidos.json', $q);
}

// ---------- Partidas de lobby ----------
function lan_extrair_od(array $d): array {
    $pl = [];
    foreach ($d['players'] ?? [] as $p) {
        if (empty($p['account_id'])) continue;
        $pl[(string) $p['account_id']] = ['hero' => (int) ($p['hero_id'] ?? 0), 'k' => (int) ($p['kills'] ?? 0),
            'd' => (int) ($p['deaths'] ?? 0), 'a' => (int) ($p['assists'] ?? 0), 'radiant' => ($p['player_slot'] ?? 0) < 128];
    }
    return ['radiantWin' => !empty($d['radiant_win']), 'duracao' => $d['duration'] ?? null, 'inicio' => $d['start_time'] ?? null, 'players' => $pl];
}

// Confirma uma partida de lobby (chamar DENTRO de with_lock). Se tiver ID da partida, tenta completar pelo OpenDota.
function lan_confirmar(array $ev, string $uid): void {
    $f = lan_file($ev, 'partidas');
    $q = read_json($f, []);
    foreach ($q as &$m) {
        if (($m['uid'] ?? '') !== $uid) continue;
        $m['status'] = 'confirmada';
        $m['decidido'] = time();
        if (!empty($m['matchId']) && empty($m['od'])) {
            $d = http_get_json('https://api.opendota.com/api/matches/' . (int) $m['matchId']);
            if ($d && !empty($d['players'])) $m['od'] = lan_extrair_od($d);
        }
    }
    unset($m);
    write_json($f, $q);
}

function lan_set_status_partida(array $ev, string $uid, string $status): void {
    $f = lan_file($ev, 'partidas');
    $q = read_json($f, []);
    foreach ($q as &$m) { if (($m['uid'] ?? '') === $uid) { $m['status'] = $status; $m['decidido'] = time(); } }
    unset($m);
    write_json($f, $q);
}

// ---------- Partidas de matchmaking (OpenDota), com cache ----------
// Atualiza no máximo a cada 10 minutos (limite diário do OpenDota gratuito), só perto do horário do evento. Pede ao OpenDota para
// buscar partidas novas dos inscritos a cada 30 minutos.
function lan_atualizar_cache(array $ev, array $ids): void {
    $f = lan_file($ev, 'cache');
    $cache = read_json($f, ['atualizado' => 0, 'refresh' => 0, 'jogadores' => []]);
    $now = time();
    $ativo = !empty($ev['teste']) || ($now >= $ev['ini'] - 3600 && $now <= $ev['end'] + 3 * 3600);
    $faltando = array_diff(array_map('strval', $ids), array_keys($cache['jogadores'] ?? []));
    if (!$ativo || ($now - ($cache['atualizado'] ?? 0) < 600 && !$faltando)) return;

    $h = fopen(data_path('lan_cache.lock'), 'c');
    if (!flock($h, LOCK_EX | LOCK_NB)) { fclose($h); return; }  // outra visita já está atualizando
    try {
        $refresh = $now - ($cache['refresh'] ?? 0) > 1800;
        $dias = max(1, (int) ceil(($now - $ev['ini']) / 86400) + 1);
        $proj = implode('&', array_map(fn($x) => "project=$x", ['hero_id', 'kills', 'deaths', 'assists', 'start_time',
            'duration', 'player_slot', 'radiant_win', 'party_size', 'lobby_type', 'game_mode']));
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($refresh) { http_post_ok("https://api.opendota.com/api/players/$id/refresh"); usleep(250000); }
            $list = http_get_json("https://api.opendota.com/api/players/$id/matches?significant=0&date=$dias&$proj");
            if (is_array($list)) {
                $cache['jogadores'][(string) $id] = array_values(array_filter($list,
                    fn($m) => ($m['start_time'] ?? 0) >= $ev['ini'] && ($m['start_time'] ?? 0) <= $ev['end']));
            }
            usleep(250000);
        }
        // Lobbies registrados com número da partida e ainda sem dados oficiais: tenta buscar no OpenDota
        // (no máximo 5 por rodada; pede a busca ao OpenDota até 3 vezes por partida).
        with_lock(function () use ($ev, $now) {
            $pf = lan_file($ev, 'partidas');
            $q = read_json($pf, []);
            $mudou = false; $n = 0;
            foreach ($q as &$m) {
                if (empty($m['matchId']) || !empty($m['od']) || ($m['status'] ?? '') !== 'confirmada' || $n >= 5) continue;
                if ($now - ($m['odTentativa'] ?? 0) < 600) continue;
                $n++; $mudou = true;
                $m['odTentativa'] = $now;
                $d = http_get_json('https://api.opendota.com/api/matches/' . (int) $m['matchId']);
                if ($d && !empty($d['players'])) $m['od'] = lan_extrair_od($d);
                elseif (($m['odPedidos'] ?? 0) < 3) { http_post_ok('https://api.opendota.com/api/request/' . (int) $m['matchId']); $m['odPedidos'] = ($m['odPedidos'] ?? 0) + 1; }
            }
            unset($m);
            if ($mudou) write_json($pf, $q);
        });
        $cache['atualizado'] = time();
        if ($refresh) $cache['refresh'] = $now;
        write_json($f, $cache);
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

// Junta lobby (confirmadas) + matchmaking (OpenDota) numa lista única de partidas.
function lan_partidas(array $ev, array $ids): array {
    $set = array_flip(array_map('intval', $ids));
    $out = [];
    $vistos = [];
    foreach (read_json(lan_file($ev, 'partidas'), []) as $m) {
        if (($m['status'] ?? '') !== 'confirmada') continue;
        $od = $m['od'] ?? null;
        $players = [];
        $radiant = array_values(array_filter(array_map('intval', $m['radiant'] ?? []), fn($i) => isset($set[$i])));
        $dire = array_values(array_filter(array_map('intval', $m['dire'] ?? []), fn($i) => isset($set[$i])));
        foreach (array_merge($radiant, $dire) as $i) {
            $p = $od['players'][(string) $i] ?? null;
            $man = $m['players'][(string) $i] ?? [];
            $players[(string) $i] = [
                'hero' => (int) ($p['hero'] ?? $man['hero'] ?? 0),
                'k' => $p['k'] ?? (isset($man['k']) && $man['k'] !== '' ? (int) $man['k'] : null),
                'd' => $p['d'] ?? (isset($man['d']) && $man['d'] !== '' ? (int) $man['d'] : null),
                'a' => $p['a'] ?? (isset($man['a']) && $man['a'] !== '' ? (int) $man['a'] : null),
            ];
        }
        $radiantWin = $od ? $od['radiantWin'] : (($m['vencedor'] ?? '') === 'radiant');
        if ($od) {  // se o OpenDota trouxe os lados, ele vale mais que o registro manual
            $r2 = []; $d2 = [];
            foreach ($od['players'] as $aid => $p) { if (isset($set[(int) $aid])) { if ($p['radiant']) $r2[] = (int) $aid; else $d2[] = (int) $aid; } }
            if ($r2 || $d2) { $radiant = $r2; $dire = $d2; }
        }
        if (!empty($m['matchId'])) $vistos[(string) $m['matchId']] = true;
        $out[] = ['uid' => $m['uid'], 'origem' => 'lobby', 'matchId' => $m['matchId'] ?? null,
            'inicio' => $od['inicio'] ?? ($m['inicioInformado'] ?? ($m['criado'] ?? 0)), 'duracao' => $od['duracao'] ?? ($m['duracao'] ?? null),
            'radiant' => $radiant, 'dire' => $dire, 'radiantWin' => $radiantWin, 'players' => $players, 'fonte' => $od ? 'opendota' : 'manual'];
    }
    $cache = read_json(lan_file($ev, 'cache'), ['jogadores' => []]);
    $porPartida = [];
    foreach ($cache['jogadores'] ?? [] as $aid => $list) {
        if (!isset($set[(int) $aid])) continue;
        foreach ($list as $m) {
            $mid = (string) $m['match_id'];
            if (isset($vistos[$mid])) continue;
            $pm = &$porPartida[$mid];
            $pm['inicio'] = $m['start_time'];
            $pm['duracao'] = $m['duration'] ?? null;
            $pm['radiantWin'] = !empty($m['radiant_win']);
            $pm['lobby'] = (int) ($m['lobby_type'] ?? 0);
            $pm['partido'] = max($pm['partido'] ?? 0, (int) ($m['party_size'] ?? 0));
            $rad = ($m['player_slot'] ?? 0) < 128;
            $pm[$rad ? 'radiant' : 'dire'][] = (int) $aid;
            $pm['players'][(string) $aid] = ['hero' => (int) ($m['hero_id'] ?? 0), 'k' => (int) ($m['kills'] ?? 0),
                'd' => (int) ($m['deaths'] ?? 0), 'a' => (int) ($m['assists'] ?? 0)];
            unset($pm);
        }
    }
    foreach ($porPartida as $mid => $pm) {
        $out[] = ['uid' => 'mm' . $mid, 'origem' => $pm['lobby'] === 1 ? 'lobby' : 'mm', 'matchId' => $mid,
            'inicio' => $pm['inicio'], 'duracao' => $pm['duracao'], 'radiant' => $pm['radiant'] ?? [], 'dire' => $pm['dire'] ?? [],
            'radiantWin' => $pm['radiantWin'], 'players' => $pm['players'], 'fonte' => 'opendota'];
    }
    usort($out, fn($a, $b) => ($b['inicio'] ?? 0) <=> ($a['inicio'] ?? 0));
    return $out;
}

function lan_herois(): array {
    $f = dirname(__DIR__) . '/lan/herois.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
    $out = [];
    foreach ((array) $d as $h) $out[(int) $h['id']] = $h['name'];
    return $out;
}
