<?php
// Lista pública dos jogadores aprovados no /admin. A atualização diária (sync_roster.py)
// lê esta lista e inclui esses jogadores no ranking.
require __DIR__ . '/_lib.php';

$q = read_json('pedidos.json', []);
$out = [];
foreach ($q as $p) {
    if (($p['status'] ?? '') === 'aprovado') {
        $out[] = ['id' => (int) $p['id'], 'nick' => $p['apelido'], 'aprovadoEm' => $p['decidido'] ?? null];
    }
}
json_out(['aprovados' => $out, 'gerado' => time()]);
