<?php
// Registro de partidas de lobby pelo ORGANIZADOR (a partir do print da tela de fim de jogo).
// Protegido por chave: cabeçalho "Authorization: Bearer <chave>". A chave fica só no PC do dono
// (LAN_TOKEN.txt, fora do Git); aqui guardamos apenas o hash dela.
//
// POST JSON {"acao": "registrar", "radiant": [ids], "dire": [ids], "vencedor": "radiant"|"dire",
//            "matchId"?: número, "duracao"?: minutos, "inicio"?: unix, "players"?: {"<id>": {"hero","k","d","a"}}}
// POST JSON {"acao": "excluir", "uid": "..."}      POST JSON {"acao": "listar"}
// POST JSON {"acao": "inscrever", "id": conta, "apelido": "..."}   (confirma direto na LAN)
require __DIR__ . '/_lan.php';
require __DIR__ . '/_lan_token.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['erro' => 'Use POST.'], 405);
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) json_out(['erro' => 'JSON inválido.'], 400);
// chave no cabeçalho Authorization (Bearer) ou, se o servidor descartar o cabeçalho, no campo "chave"
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$tok = preg_match('~^Bearer\s+(\S+)$~', $auth, $m) ? $m[1] : (string) ($in['chave'] ?? '');
if ($tok === '' || !hash_equals(LAN_TOKEN_SHA256, hash('sha256', $tok))) { usleep(500000); json_out(['erro' => 'Não autorizado.'], 401); }
$ev = lan_evento($in['evento'] ?? null);
$acao = $in['acao'] ?? 'registrar';

if ($acao === 'listar') {
    json_out(['evento' => $ev['id'], 'inscritos' => lan_inscritos($ev), 'pendentes' => lan_inscritos($ev, 'pendente'),
        'partidas' => read_json(lan_file($ev, 'partidas'), [])]);
}

if ($acao === 'inscrever') {
    $id = parse_account_id($in['id'] ?? '');
    $nick = clean_nick($in['apelido'] ?? '');
    if (!$id || mb_strlen($nick) < 2) json_out(['erro' => 'Informe id e apelido.'], 400);
    $prof = opendota_profile($id);
    $pr = $prof['profile'] ?? [];
    with_lock(function () use ($ev, $id, $nick, $pr, $prof) {
        $f = lan_file($ev, 'inscricoes');
        $q = read_json($f, []);
        $existe = false;
        foreach ($q as $p) if ((int) $p['id'] === $id) $existe = true;
        if (!$existe) {
            $q[] = ['id' => $id, 'apelido' => $nick, 'nomeSteam' => $pr['personaname'] ?? '', 'avatar' => $pr['avatarfull'] ?? '',
                'medalha' => $prof['rank_tier'] ?? null, 'privadoOpenDota' => !empty($pr['fh_unavailable']), 'status' => 'pendente', 'criado' => time()];
            write_json($f, $q);
        }
        lan_aprovar($ev, $id, $nick);
    });
    json_out(['ok' => true]);
}

if ($acao === 'excluir') {
    $uid = preg_replace('~[^a-f0-9]~', '', (string) ($in['uid'] ?? ''));
    with_lock(fn() => lan_set_status_partida($ev, $uid, 'excluida'));
    json_out(['ok' => true]);
}

// registrar
$aprovados = [];
foreach (lan_inscritos($ev) as $p) $aprovados[(int) $p['id']] = true;
$ids = fn($arr) => array_values(array_unique(array_filter(array_map('intval', (array) $arr), fn($i) => $i > 0)));
$radiant = $ids($in['radiant'] ?? []);
$dire = $ids($in['dire'] ?? []);
if (!$radiant && !$dire) json_out(['erro' => 'Informe ao menos um jogador da LAN.'], 400);
if (array_intersect($radiant, $dire)) json_out(['erro' => 'Jogador nos dois times.'], 400);
foreach (array_merge($radiant, $dire) as $i) if (!isset($aprovados[$i])) json_out(['erro' => "Conta $i não está confirmada na LAN."], 400);
$venc = $in['vencedor'] ?? '';
if (!in_array($venc, ['radiant', 'dire'], true)) json_out(['erro' => 'Informe o vencedor.'], 400);
$matchId = !empty($in['matchId']) ? (int) preg_replace('~\D~', '', (string) $in['matchId']) : null;
$players = [];
foreach (array_merge($radiant, $dire) as $i) {
    $p = $in['players'][(string) $i] ?? [];
    $num = fn($v) => ($v === '' || $v === null) ? null : max(0, min(99, (int) $v));
    $players[(string) $i] = ['hero' => max(0, min(300, (int) ($p['hero'] ?? 0))), 'k' => $num($p['k'] ?? null), 'd' => $num($p['d'] ?? null), 'a' => $num($p['a'] ?? null)];
}
$dur = !empty($in['duracao']) ? (int) round((float) $in['duracao'] * 60) : null;
$inicio = !empty($in['inicio']) ? (int) $in['inicio'] : null;

// Pede ao OpenDota para buscar a partida (se for possível, os dados oficiais completam/corrigem o registro).
if ($matchId) http_post_ok("https://api.opendota.com/api/request/$matchId");

$res = with_lock(function () use ($ev, $radiant, $dire, $venc, $matchId, $players, $dur, $inicio) {
    $f = lan_file($ev, 'partidas');
    $q = read_json($f, []);
    if ($matchId) {
        foreach ($q as $m) {
            if ((int) ($m['matchId'] ?? 0) === $matchId && ($m['status'] ?? '') === 'confirmada') return ['erro' => 'Essa partida já foi registrada.', 'uid' => $m['uid']];
        }
    }
    $uid = bin2hex(random_bytes(6));
    $q[] = ['uid' => $uid, 'status' => 'confirmada', 'origemRegistro' => 'organizador', 'radiant' => $radiant, 'dire' => $dire,
        'vencedor' => $venc, 'matchId' => $matchId, 'players' => $players, 'duracao' => $dur, 'inicioInformado' => $inicio,
        'criado' => time(), 'decidido' => time()];
    write_json($f, $q);
    return ['ok' => true, 'uid' => $uid];
});
json_out($res, isset($res['erro']) ? 409 : 200);
