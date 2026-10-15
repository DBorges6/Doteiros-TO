<?php
// Inscrição na LAN Party. POST JSON: {"id","apelido","site"(campo invisível),"evento"?,"teste"?}
require __DIR__ . '/_lan.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['erro' => 'Use o formulário da página da LAN.'], 405);
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (!empty($in['site'])) json_out(['ok' => true]);

$ev = lan_evento($in['evento'] ?? null);
if (time() > $ev['end']) json_out(['erro' => 'As inscrições dessa LAN já foram encerradas.'], 410);

$id = parse_account_id($in['id'] ?? '');
$nick = clean_nick($in['apelido'] ?? '');
$teste = !empty($in['teste']);
if (!$id) json_out(['erro' => 'Não reconheci esse ID. Use o número que aparece no Dotabuff/OpenDota ou cole o link do perfil.'], 400);
if (mb_strlen($nick) < 2) json_out(['erro' => 'Escolha um apelido com pelo menos 2 letras.'], 400);

$prof = opendota_profile($id);
if (!$prof || empty($prof['profile'])) json_out(['erro' => 'Não encontrei essa conta. Confira o ID ou o link do perfil.'], 404);

[$code, $resp] = with_lock(function () use ($ev, $id, $nick, $prof, $teste) {
    $f = lan_file($ev, 'inscricoes');
    $q = read_json($f, []);
    $ipHash = hash('sha256', client_ip() . '|doteiros');
    $now = time();
    if (count(array_filter($q, fn($p) => ($p['ipHash'] ?? '') === $ipHash && $now - ($p['criado'] ?? 0) < 3600)) >= 6) {
        return [429, ['erro' => 'Muitas inscrições seguidas. Tente de novo daqui a uma hora.']];
    }
    foreach ($q as $p) {
        if ((int) $p['id'] !== $id) continue;
        if ($p['status'] === 'pendente') return [409, ['erro' => 'Essa conta já está inscrita e aguardando aprovação.']];
        if ($p['status'] === 'aprovado') return [409, ['erro' => 'Essa conta já está confirmada na LAN.']];
    }
    if (count(array_filter($q, fn($p) => $p['status'] === 'pendente')) >= 60) return [503, ['erro' => 'Muitas inscrições aguardando. Tente mais tarde.']];
    $pr = $prof['profile'];
    if ($teste && empty($ev['teste'])) return [200, ['ok' => true, 'teste' => true, 'nomeSteam' => $pr['personaname'] ?? '']];
    $q[] = ['id' => $id, 'apelido' => $nick, 'nomeSteam' => $pr['personaname'] ?? '', 'avatar' => $pr['avatarfull'] ?? '',
        'medalha' => $prof['rank_tier'] ?? null, 'privadoOpenDota' => !empty($pr['fh_unavailable']),
        'status' => 'pendente', 'criado' => $now, 'ipHash' => $ipHash];
    write_json($f, $q);
    if (!empty($ev['teste'])) lan_aprovar($ev, $id);  // evento de teste: aprova sozinho
    return [200, ['ok' => true]];
});
json_out($resp, $code);
