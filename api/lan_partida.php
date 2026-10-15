<?php
// Registro do resultado de uma partida de lobby, enviado por um inscrito. Fica "pendente" até o
// dono confirmar no /admin.
// POST JSON: {"enviadoPor": id, "radiant": [ids], "dire": [ids], "vencedor": "radiant"|"dire",
//             "matchId"?: número, "players"?: {"<id>": {"hero": id, "k":, "d":, "a":}}, "site": ""}
require __DIR__ . '/_lan.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['erro' => 'Use o formulário da página da LAN.'], 405);
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) json_out(['erro' => 'Pedido inválido.'], 400);
if (!empty($in['site'])) json_out(['ok' => true]);

$ev = lan_evento($in['evento'] ?? null);
$now = time();
if (empty($ev['teste']) && ($now < $ev['ini'] - 3600 || $now > $ev['end'] + 12 * 3600)) {
    json_out(['erro' => 'O registro de partidas só fica aberto durante a LAN.'], 410);
}

$aprovados = [];
foreach (lan_inscritos($ev) as $p) $aprovados[(int) $p['id']] = true;
$ids = fn($arr) => array_values(array_unique(array_filter(array_map('intval', (array) $arr), fn($i) => $i > 0)));
$radiant = $ids($in['radiant'] ?? []);
$dire = $ids($in['dire'] ?? []);
$por = (int) ($in['enviadoPor'] ?? 0);

if (!isset($aprovados[$por])) json_out(['erro' => 'Escolha quem está enviando (precisa estar confirmado na LAN).'], 400);
if (count($radiant) < 1 || count($dire) < 1 || count($radiant) > 5 || count($dire) > 5) json_out(['erro' => 'Cada time precisa ter de 1 a 5 jogadores.'], 400);
if (array_intersect($radiant, $dire)) json_out(['erro' => 'O mesmo jogador não pode estar nos dois times.'], 400);
foreach (array_merge($radiant, $dire) as $i) {
    if (!isset($aprovados[$i])) json_out(['erro' => 'Só jogadores confirmados na LAN podem entrar no placar.'], 400);
}
$venc = $in['vencedor'] ?? '';
if (!in_array($venc, ['radiant', 'dire'], true)) json_out(['erro' => 'Marque qual time venceu.'], 400);

$matchId = isset($in['matchId']) && $in['matchId'] !== '' ? (int) preg_replace('~\D~', '', (string) $in['matchId']) : null;
if ($matchId !== null && ($matchId < 1000000 || $matchId > 99999999999)) json_out(['erro' => 'O ID da partida não parece válido. Deixe em branco se não souber.'], 400);

$players = [];
foreach (array_merge($radiant, $dire) as $i) {
    $p = $in['players'][(string) $i] ?? [];
    $num = fn($v) => ($v === '' || $v === null) ? null : max(0, min(99, (int) $v));
    $players[(string) $i] = ['hero' => max(0, min(300, (int) ($p['hero'] ?? 0))), 'k' => $num($p['k'] ?? null), 'd' => $num($p['d'] ?? null), 'a' => $num($p['a'] ?? null)];
}
$dur = isset($in['duracao']) && $in['duracao'] !== '' ? max(0, min(240, (int) $in['duracao'])) * 60 : null;

[$code, $resp] = with_lock(function () use ($ev, $por, $radiant, $dire, $venc, $matchId, $players, $dur, $now) {
    $f = lan_file($ev, 'partidas');
    $q = read_json($f, []);
    $ipHash = hash('sha256', client_ip() . '|doteiros');
    if (count(array_filter($q, fn($m) => ($m['ipHash'] ?? '') === $ipHash && $now - ($m['criado'] ?? 0) < 3600)) >= 20) {
        return [429, ['erro' => 'Muitos registros seguidos. Tente de novo mais tarde.']];
    }
    if ($matchId) {
        foreach ($q as $m) {
            if ((int) ($m['matchId'] ?? 0) === $matchId && in_array($m['status'], ['pendente', 'confirmada'], true)) {
                return [409, ['erro' => 'Essa partida já foi registrada.']];
            }
        }
    }
    $uid = bin2hex(random_bytes(6));
    $q[] = ['uid' => $uid, 'status' => 'pendente', 'enviadoPor' => $por, 'radiant' => $radiant, 'dire' => $dire,
        'vencedor' => $venc, 'matchId' => $matchId, 'players' => $players, 'duracao' => $dur, 'criado' => $now, 'ipHash' => $ipHash];
    write_json($f, $q);
    if (!empty($ev['teste'])) lan_confirmar($ev, $uid);  // evento de teste: confirma sozinho
    return [200, ['ok' => true, 'uid' => $uid]];
});
json_out($resp, $code);
