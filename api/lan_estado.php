<?php
// Tudo o que a página da LAN precisa: evento, inscritos confirmados e partidas (lobby + matchmaking).
// As partidas de matchmaking vêm do OpenDota e ficam em cache (atualiza no máximo a cada 10 min).
require __DIR__ . '/_lan.php';

$ev = lan_evento($_GET['evento'] ?? null);
$inscritos = lan_inscritos($ev);
$ids = array_map(fn($p) => (int) $p['id'], $inscritos);

$montar = function () use ($ev, $inscritos, $ids) {
    $cache = read_json(lan_file($ev, 'cache'), ['atualizado' => 0]);
    $pend = read_json(lan_file($ev, 'partidas'), []);
    return [
        'evento' => ['id' => $ev['id'], 'nome' => $ev['nome'], 'inicio' => $ev['ini'], 'fim' => $ev['end']],
        'agora' => time(),
        'participantes' => array_map(fn($p) => ['id' => (int) $p['id'], 'apelido' => $p['apelido'], 'nomeSteam' => $p['nomeSteam'] ?? '',
            'avatar' => $p['avatar'] ?? '', 'medalha' => $p['medalha'] ?? null, 'privado' => !empty($p['privadoOpenDota'])], $inscritos),
        'inscricoesPendentes' => count(lan_inscritos($ev, 'pendente')),
        'partidasPendentes' => count(array_filter($pend, fn($m) => ($m['status'] ?? '') === 'pendente')),
        'partidas' => lan_partidas($ev, $ids),
        'cacheAtualizado' => $cache['atualizado'] ?? 0,
    ];
};

// Se o cache está velho, atualiza ANTES de responder só na primeira vez (sem dados);
// nas outras, responde na hora e atualiza depois de enviar a resposta.
$cache = read_json(lan_file($ev, 'cache'), ['atualizado' => 0]);
if (empty($cache['atualizado']) && $ids) lan_atualizar_cache($ev, $ids);

$body = json_encode($montar(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Content-Length: ' . strlen($body));
echo $body;
if (function_exists('litespeed_finish_request')) litespeed_finish_request();
elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else { @ob_end_flush(); flush(); }
ignore_user_abort(true);
if ($ids) lan_atualizar_cache($ev, $ids);
