<?php
// Hash SHA-256 do código de configuração do primeiro acesso ao /admin.
// O código em si fica só no PC do dono (ADMIN_SETUP_CODE.txt, fora do Git). Depois que a senha
// é definida, este código não serve para mais nada.
const SETUP_CODE_SHA256 = '04e8d33bf92b7b8d6ed56d467cc978e3583b5f13f889d52fb7511090081bfa0d';
