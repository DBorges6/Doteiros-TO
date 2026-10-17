<?php
// Hash SHA-256 da chave do organizador (a chave em si fica só no PC, em LAN_TOKEN.txt).
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit; }
const LAN_TOKEN_SHA256 = 'd166a8621b4faf1981a8bbb5594a23ab63a5b54395df01c5a6b61ca63d74d267';
