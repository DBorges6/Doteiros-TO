<?php
// Registro público de lobby foi desativado: o organizador registra a partir do print da tela final
// (api/lan_registro.php, protegido por chave).
require __DIR__ . '/_lib.php';
json_out(['erro' => 'O resultado dos lobbies agora é registrado pelo organizador a partir do print do fim de jogo.'], 410);
