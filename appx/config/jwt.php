<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$config['jwt_key'] = 'holaMundo2025'; // usa una clave fuerte
$config['jwt_algorithm'] = 'HS256';
$config['jwt_token_expire'] = 3600; // 1 hora
