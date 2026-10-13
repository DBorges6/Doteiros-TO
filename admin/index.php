<?php
// Área de aprovação dos pedidos de entrada (doteiros.com.br/admin).
// Primeiro acesso: define a senha usando o código de configuração (ADMIN_SETUP_CODE.txt no PC).
// A senha fica guardada só como hash, fora da pasta pública do site.
require dirname(__DIR__) . '/api/_lib.php';
require __DIR__ . '/_config.php';

header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

session_name('doteiros_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sessão expirada. Volte e recarregue a página.');
    }
}
function fails_key(): string { return hash('sha256', client_ip() . '|admin'); }
function too_many_fails(): bool {
    $f = read_json('login_falhas.json', []);
    return count(array_filter($f[fails_key()] ?? [], fn($t) => time() - $t < 900)) >= 8;
}
function add_fail(): void {
    with_lock(function () {
        $f = read_json('login_falhas.json', []);
        $k = fails_key();
        $f[$k] = array_values(array_filter($f[$k] ?? [], fn($t) => time() - $t < 900));
        $f[$k][] = time();
        write_json('login_falhas.json', $f);
    });
    usleep(700000);
}

const RANKS = ['Herald', 'Guardian', 'Crusader', 'Archon', 'Legend', 'Ancient', 'Divine', 'Immortal'];
function medal($t): string {
    if (!$t) return 'sem medalha';
    $tier = intdiv((int) $t, 10); $star = (int) $t % 10;
    return (RANKS[$tier - 1] ?? '?') . ($star ? ' ' . ['', 'I', 'II', 'III', 'IV', 'V'][$star] : '');
}
function ago($ts): string {
    $d = time() - (int) $ts;
    if ($d < 3600) return max(1, intdiv($d, 60)) . ' min atrás';
    if ($d < 86400) return intdiv($d, 3600) . ' h atrás';
    return intdiv($d, 86400) . ' dias atrás';
}

function page(string $title, string $body): void {
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' · Taverna do Ancient</title><style>'
        . ':root{color-scheme:dark;--bg:#0d1112;--panel:#141b1c;--panel2:#1a2324;--line:#263133;--ink:#ece5d3;--ink2:#aab1a9;--mute:#728079;--gold:#e2a93b;--gold2:#f4cd6e;--rad:#7dbf5a;--dire:#d4523e}'
        . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.45 "Segoe UI",system-ui,sans-serif;padding:24px 16px 60px}'
        . '.wrap{max-width:920px;margin:0 auto}h1{font-family:Georgia,serif;font-size:26px;margin:0 0 4px;color:var(--gold2)}h2{font-size:15px;text-transform:uppercase;letter-spacing:.12em;color:var(--gold);margin:28px 0 10px}'
        . '.sub{color:var(--mute);font-size:13px}.card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:14px 16px}'
        . '.row{display:grid;grid-template-columns:56px minmax(0,1fr) auto;gap:14px;align-items:center;padding:12px 14px;border:1px solid var(--line);border-radius:10px;background:var(--panel);margin-bottom:8px}'
        . '.row img{width:56px;height:56px;border-radius:50%;object-fit:cover;background:var(--panel2)}.row b{font-size:16px}'
        . '.acts{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end}form.inline{display:inline-flex;gap:8px;align-items:center;margin:0}'
        . 'input[type=text],input[type=password]{background:var(--panel2);border:1px solid var(--line);color:var(--ink);border-radius:8px;padding:8px 10px;font:inherit;min-width:0}'
        . 'button{font:inherit;font-weight:600;border-radius:8px;padding:8px 14px;border:1px solid var(--line);background:var(--panel2);color:var(--ink);cursor:pointer}'
        . 'button.ok{background:#284a1d;border-color:#7dbf5a88;color:#d8f0c9}button.no{background:#4a1f19;border-color:#d4523e88;color:#f6d3cc}button.gold{background:var(--gold);color:#1a1405;border-color:var(--gold)}'
        . 'a{color:var(--gold2)}.tag{font-size:12px;padding:2px 8px;border-radius:999px;border:1px solid var(--line);color:var(--ink2)}.tag.warn{color:#f0b38a;border-color:#d4523e66}.tag.good{color:#bfe3a8;border-color:#7dbf5a66}'
        . '.msg{padding:10px 14px;border-radius:8px;margin:14px 0}.msg.err{background:#4a1f19;color:#f6d3cc}.msg.ok{background:#284a1d;color:#d8f0c9}'
        . '.top{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap}.stack{display:grid;gap:10px;max-width:360px}'
        . '@media(max-width:600px){.row{grid-template-columns:44px minmax(0,1fr)}.row img{width:44px;height:44px}.acts{grid-column:1/-1;justify-content:flex-start}}'
        . '</style><div class="wrap">' . $body . '</div></html>';
    exit;
}

$auth = read_json('admin.json', []);
$acao = (string) ($_POST['acao'] ?? '');
$err = '';

// ---------- Primeiro acesso: definir a senha ----------
if (empty($auth['hash'])) {
    if ($acao === 'definir') {
        check_csrf();
        $senha = (string) ($_POST['senha'] ?? '');
        if (too_many_fails()) $err = 'Muitas tentativas. Espere 15 minutos.';
        elseif (!hash_equals(SETUP_CODE_SHA256, hash('sha256', trim((string) ($_POST['codigo'] ?? ''))))) { add_fail(); $err = 'Código de configuração incorreto.'; }
        elseif (strlen($senha) < 10) $err = 'A senha precisa ter pelo menos 10 caracteres.';
        elseif ($senha !== (string) ($_POST['senha2'] ?? '')) $err = 'As duas senhas não são iguais.';
        else {
            write_json('admin.json', ['hash' => password_hash($senha, PASSWORD_DEFAULT), 'definidaEm' => time()]);
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            header('Location: ./');
            exit;
        }
    }
    page('Configurar acesso', '<h1>Configurar a área de aprovação</h1><p class="sub">Primeiro acesso. Digite o código de configuração (arquivo <b>ADMIN_SETUP_CODE.txt</b> na pasta do projeto no seu PC) e escolha a sua senha.</p>'
        . ($err ? '<div class="msg err">' . h($err) . '</div>' : '')
        . '<form method="post" class="card stack"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="acao" value="definir">'
        . '<label>Código de configuração<br><input type="text" name="codigo" autocomplete="off" required></label>'
        . '<label>Nova senha (mín. 10 caracteres)<br><input type="password" name="senha" autocomplete="new-password" required minlength="10"></label>'
        . '<label>Repita a senha<br><input type="password" name="senha2" autocomplete="new-password" required minlength="10"></label>'
        . '<button class="gold" type="submit">Salvar senha e entrar</button></form>');
}

// ---------- Login ----------
if (empty($_SESSION['ok'])) {
    if ($acao === 'entrar') {
        check_csrf();
        if (too_many_fails()) $err = 'Muitas tentativas erradas. Espere 15 minutos.';
        elseif (password_verify((string) ($_POST['senha'] ?? ''), $auth['hash'])) {
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            header('Location: ./');
            exit;
        } else { add_fail(); $err = 'Senha incorreta.'; }
    }
    page('Entrar', '<h1>Área de aprovação</h1><p class="sub">Taverna do Ancient · pedidos de entrada no ranking</p>'
        . ($err ? '<div class="msg err">' . h($err) . '</div>' : '')
        . '<form method="post" class="card stack"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="acao" value="entrar">'
        . '<label>Senha<br><input type="password" name="senha" autocomplete="current-password" required autofocus></label>'
        . '<button class="gold" type="submit">Entrar</button></form>');
}

// ---------- Ações (logado) ----------
if ($acao === 'sair') { check_csrf(); session_destroy(); header('Location: ./'); exit; }
if (in_array($acao, ['aprovar', 'recusar', 'remover', 'reabrir'], true)) {
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $nick = clean_nick($_POST['apelido'] ?? '');
    with_lock(function () use ($acao, $id, $nick) {
        $q = read_json('pedidos.json', []);
        foreach ($q as &$p) {
            if ((int) $p['id'] !== $id) continue;
            if ($acao === 'aprovar') { $p['status'] = 'aprovado'; if (mb_strlen($nick) >= 2) $p['apelido'] = $nick; }
            elseif ($acao === 'recusar') $p['status'] = 'recusado';
            elseif ($acao === 'remover') $p['status'] = 'removido';
            else $p['status'] = 'pendente';
            $p['decidido'] = time();
        }
        unset($p);
        write_json('pedidos.json', $q);
    });
    header('Location: ./?feito=' . $acao);
    exit;
}

// ---------- Painel ----------
$q = read_json('pedidos.json', []);
usort($q, fn($a, $b) => ($b['criado'] ?? 0) <=> ($a['criado'] ?? 0));
$noRanking = [];
foreach (current_roster() as $r) $noRanking[(int) $r['id']] = true;
$csrf = h(csrf());
$links = fn($id) => '<a href="https://pt.dotabuff.com/players/' . $id . '" target="_blank" rel="noopener noreferrer">Dotabuff</a> · <a href="https://www.opendota.com/players/' . $id . '" target="_blank" rel="noopener noreferrer">OpenDota</a>';
$who = fn($p) => '<img src="' . h($p['avatar'] ?: 'data:,') . '" alt=""><div><b>' . h($p['apelido']) . '</b> <span class="sub">(' . h($p['nomeSteam']) . ')</span><br><span class="sub">'
    . h(medal($p['medalha'] ?? null)) . ' · ID ' . (int) $p['id'] . ' · ' . $links((int) $p['id']) . ' · pedido ' . h(ago($p['criado'] ?? 0)) . '</span>'
    . (!empty($p['privadoOpenDota']) ? ' <span class="tag warn" title="O histórico do OpenDota é privado; o Dotabuff pode estar público">histórico privado no OpenDota</span>' : '') . '</div>';
$btn = fn($acao, $id, $label, $cls = '') => '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="acao" value="' . $acao . '"><input type="hidden" name="id" value="' . (int) $id . '"><button class="' . $cls . '" type="submit">' . $label . '</button></form>';

$pend = array_filter($q, fn($p) => $p['status'] === 'pendente');
$apr = array_filter($q, fn($p) => $p['status'] === 'aprovado');
$out = array_filter($q, fn($p) => in_array($p['status'], ['recusado', 'removido'], true));
$feito = ['aprovar' => 'Aprovado. Entra no ranking na próxima atualização.', 'recusar' => 'Pedido recusado.', 'remover' => 'Removido. Sai do ranking na próxima atualização.', 'reabrir' => 'Pedido voltou para a fila.'][$_GET['feito'] ?? ''] ?? '';

$html = '<div class="top"><div><h1>Pedidos de entrada</h1><p class="sub">' . count($pend) . ' aguardando · ' . count($apr) . ' aprovados · a atualização diária inclui os aprovados no ranking</p></div>'
    . '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="acao" value="sair"><button type="submit">Sair</button></form></div>'
    . ($feito ? '<div class="msg ok">' . h($feito) . '</div>' : '');

$html .= '<h2>Aguardando aprovação</h2>';
if (!$pend) $html .= '<p class="sub">Nenhum pedido novo.</p>';
foreach ($pend as $p) {
    $html .= '<div class="row">' . $who($p) . '<div class="acts">'
        . '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="acao" value="aprovar"><input type="hidden" name="id" value="' . (int) $p['id'] . '">'
        . '<input type="text" name="apelido" value="' . h($p['apelido']) . '" maxlength="24" size="12" aria-label="Apelido no ranking"><button class="ok" type="submit">Aprovar</button></form>'
        . $btn('recusar', $p['id'], 'Recusar', 'no') . '</div></div>';
}

$html .= '<h2>Aprovados</h2>';
if (!$apr) $html .= '<p class="sub">Ninguém aprovado por aqui ainda.</p>';
foreach ($apr as $p) {
    $st = isset($noRanking[(int) $p['id']]) ? '<span class="tag good">no ranking</span>' : '<span class="tag">entra na próxima atualização</span>';
    $html .= '<div class="row">' . $who($p) . '<div class="acts">' . $st . $btn('remover', $p['id'], 'Remover do ranking', 'no') . '</div></div>';
}

if ($out) {
    $html .= '<h2>Recusados e removidos</h2>';
    foreach ($out as $p) {
        $html .= '<div class="row">' . $who($p) . '<div class="acts"><span class="tag">' . h($p['status']) . '</span>' . $btn('reabrir', $p['id'], 'Voltar para a fila') . '</div></div>';
    }
}
page('Pedidos', $html);
