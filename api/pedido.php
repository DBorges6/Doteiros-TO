<?php
// Recebe o pedido de entrada feito pelo formulário do site.
// POST JSON: {"id": "...", "apelido": "...", "site": ""}   (site = campo invisível contra robôs)
// Com "teste": true, valida tudo e responde sem gravar (usado para conferir o servidor).
require __DIR__ . '/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['erro' => 'Use o formulário do site.'], 405);

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (!empty($in['site'])) json_out(['ok' => true]);  // robô preencheu o campo invisível: finge que deu certo

$id = parse_account_id($in['id'] ?? '');
$nick = clean_nick($in['apelido'] ?? '');
$teste = !empty($in['teste']);

if (!$id) json_out(['erro' => 'Não reconheci esse ID. Use o número que aparece no Dotabuff/OpenDota ou cole o link do perfil.'], 400);
if (mb_strlen($nick) < 2) json_out(['erro' => 'Escolha um apelido com pelo menos 2 letras.'], 400);

foreach (current_roster() as $r) {
    if ((int) ($r['id'] ?? 0) === $id) json_out(['erro' => 'Esse jogador já está no ranking como ' . $r['nick'] . '.'], 409);
}

$prof = opendota_profile($id);
if (!$prof || empty($prof['profile'])) {
    json_out(['erro' => 'Não encontrei essa conta. Confira o ID ou o link do perfil.'], 404);
}

[$code, $resp] = with_lock(function () use ($id, $nick, $prof, $teste) {
    $q = read_json('pedidos.json', []);
    $ipHash = hash('sha256', client_ip() . '|doteiros');  // guarda só um resumo do IP, nunca o IP
    $now = time();
    $doIp = array_filter($q, fn($p) => ($p['ipHash'] ?? '') === $ipHash && $now - ($p['criado'] ?? 0) < 3600);
    if (count($doIp) >= 5) return [429, ['erro' => 'Muitos pedidos seguidos. Tente de novo daqui a uma hora.']];
    foreach ($q as $p) {
        if ((int) $p['id'] !== $id) continue;
        if ($p['status'] === 'pendente') return [409, ['erro' => 'Já existe um pedido para essa conta aguardando aprovação.']];
        if ($p['status'] === 'aprovado') return [409, ['erro' => 'Essa conta já foi aprovada e entra na próxima atualização do ranking.']];
    }
    if (count(array_filter($q, fn($p) => $p['status'] === 'pendente')) >= 100) {
        return [503, ['erro' => 'A fila de pedidos está cheia. Tente de novo mais tarde.']];
    }
    $pr = $prof['profile'];
    if ($teste) return [200, ['ok' => true, 'teste' => true, 'nomeSteam' => $pr['personaname'] ?? '']];
    $q[] = [
        'id' => $id, 'apelido' => $nick, 'nomeSteam' => $pr['personaname'] ?? '',
        'avatar' => $pr['avatarfull'] ?? '', 'medalha' => $prof['rank_tier'] ?? null,
        'privadoOpenDota' => !empty($pr['fh_unavailable']), 'status' => 'pendente',
        'criado' => $now, 'ipHash' => $ipHash,
    ];
    write_json('pedidos.json', $q);
    return [200, ['ok' => true]];
});
json_out($resp, $code);
