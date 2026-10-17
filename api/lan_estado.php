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

// Atualiza ANTES de responder quando o cache passou de 10 min (a Hostinger encerra o processo
// logo depois da resposta, então não dá para atualizar em segundo plano). Só uma visita a cada
// 10 min espera alguns segundos; se outra visita já está atualizando, responde com o que tem.
ignore_user_abort(true);
set_time_limit(90);
if ($ids) lan_atualizar_cache($ev, $ids);
json_out($montar());
