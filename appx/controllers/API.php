<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// This can be removed if you use __autoload() in config.php OR use Modular Extensions
/** @noinspection PhpIncludeInspection */
require APPPATH . 'libraries/REST_controller.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Api extends REST_Controller{
    function __construct(){
        parent::__construct();
        $this->load->helper('utils');
        $this->load->library('Jwt_lib');
    }

    public function login_post() {
        $this->load->model('User');

        $username = trim((string)$this->post('user'));
        $password = (string)$this->post('pass');

        if ($username === '' || $password === '') {
            return $this->response([
                'status'  => false,
                'message' => 'Usuario y contraseña son requeridos'
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        // Micro delay anti fuerza bruta
        usleep(250000); // 250ms

        $user = $this->User->validate_credentials($username, $password);

        if (!$user) {
            return $this->response([
                'status'  => false,
                'message' => 'Credenciales inválidas'
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $claims = [
            'sub'      => (int)$user->id,
            'username' => $user->username,
            'role_id'  => (int)$user->role_id,
            'full_name'=> $user->full_name ?? null,
            'iat'      => time(),
            'exp'      => time() + 3600, // 1 hora
        ];

        $token = $this->jwt_lib->generate_token($claims);

        return $this->response([
            'status'      => true,
            'token_type'  => 'Bearer',
            'expires_in'  => 3600,
            'access_token'=> $token,
            'user'        => [
                'id'        => (int)$user->id,
                'username'  => $user->username,
                'role_id'   => (int)$user->role_id,
                'full_name' => $user->full_name ?? null,
            ]
        ], REST_Controller::HTTP_OK);
    }

    function users_get(){
        $admin_data = $this->verify_request();

        // Verifica que quien hace la solicitud sea admin
        if ((int)$admin_data->role_id !== 1) {
            return $this->response([
                'status'  => false,
                'message' => 'No tiene permisos para realizar esta acción.'
            ], REST_Controller::HTTP_FORBIDDEN);
        }

        $this->load->model('User');
        $usuarios = $this->User->getUsuarios($user_data);

        if (!empty($usuarios)) {
            $this->response([
                'status' => true,
                'data' => $usuarios
            ], REST_Controller::HTTP_OK);
        } else {
            $this->response([
                'status' => false,
                'message' => 'No se encontraron usuarios o ocurrió un error en la consulta.'
            ], REST_Controller::HTTP_NOT_FOUND);
        }
        
    }

    function user_post(){
        $this->load->model('User');

        $admin_data = $this->verify_request();

        // 1) Sanitización mínima
        $name  = trim($this->post('name'));
        $user  = trim($this->post('username'));
        $pass  = (string)$this->post('pass');
        $role  = $this->post('role');

        // 2) Validación de campos
        if ($name === '' || $user === '' || $pass === '' || $role === null) {
            return $this->response([
                'status'  => false,
                'message' => 'Campos requeridos: name, username, pass, role'
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        // Opcional: fuerza tipo/valores de rol
        if (!ctype_digit((string)$role)) {
            return $this->response([
                'status'  => false,
                'message' => 'role debe ser numérico'
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        // Verifica que quien hace la solicitud sea admin
        if ((int)$admin_data->role_id !== 1) {
            return $this->response([
                'status'  => false,
                'message' => 'No tiene permisos para realizar esta acción.'
            ], REST_Controller::HTTP_FORBIDDEN);
        }

        // 4) Intentar crear
        $result = $this->User->createUsuario([
            'name'    => $name,
            'user'    => $user,
            'pass'    => $pass,
            'role'    => (int)$role
        ]);

        // 5) Respuestas semánticas

        if ($result['status'] === 'conflict') {
            return $this->response([
                'status'  => false,
                'message' => 'El username ya existe'
            ], REST_Controller::HTTP_CONFLICT);
        }

        if ($result['status'] === 'ok') {
            return $this->response([
                'status'  => true,
                'message' => 'Usuario creado',
                'id'      => $result['id']
            ], REST_Controller::HTTP_CREATED);
        }

        // Error interno
        return $this->response([
            'status'  => false,
            'message' => 'Ocurrió un error al crear el usuario',
            'error'   => $result['error'] ?? null
        ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
        
    }

    public function block_user_post(){
        $this->load->model('User');

        $admin_data = $this->verify_request();
        $user_id = (int) $this->post('user_id');

        if (!$user_id) {
            return $this->response([
                'status'  => false,
                'message' => 'Debe enviar el ID del usuario a bloquear.'
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        // Verifica que quien hace la solicitud sea admin
        if ((int)$admin_data->role_id !== 1) {
            return $this->response([
                'status'  => false,
                'message' => 'No tiene permisos para realizar esta acción.'
            ], REST_Controller::HTTP_FORBIDDEN);
        }

        $result = $this->User->blockUser($user_id);

        if ($result['status'] === 'ok') {
            return $this->response([
                'status'  => true,
                'message' => 'Usuario bloqueado correctamente.'
            ], REST_Controller::HTTP_OK);
        } elseif ($result['status'] === 'forbidden') {
            return $this->response([
                'status'  => false,
                'message' => 'Solo se pueden bloquear usuarios con rol_id = 2.'
            ], REST_Controller::HTTP_FORBIDDEN);
        } elseif ($result['status'] === 'not_found') {
            return $this->response([
                'status'  => false,
                'message' => 'Usuario no encontrado.'
            ], REST_Controller::HTTP_NOT_FOUND);
        } else {
            return $this->response([
                'status'  => false,
                'message' => 'Ocurrió un error al intentar bloquear el usuario.'
            ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update_name_patch(){
        $this->load->model('User');

        $user_data = $this->verify_request();

        // Parámetros recibidos
        $new_name = trim($this->patch('new_name'));

        if ($new_name === '') {
            return $this->response([
                'status'  => false,
                'message' => 'El campo new_name es requerido.'
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        // Llamada al modelo
        $result = $this->User->updateOwnName($user_data->username, $new_name);

        // Respuesta
        if ($result['status'] === 'ok') {
            return $this->response([
                'status'  => true,
                'message' => 'Nombre actualizado correctamente.'
            ], REST_Controller::HTTP_OK);
        } elseif ($result['status'] === 'not_found') {
            return $this->response([
                'status'  => false,
                'message' => 'Usuario no encontrado.'
            ], REST_Controller::HTTP_NOT_FOUND);
        } elseif ($result['status'] === 'forbidden') {
            return $this->response([
                'status'  => false,
                'message' => 'No tiene permiso para actualizar otro usuario.'
            ], REST_Controller::HTTP_FORBIDDEN);
        } else {
            return $this->response([
                'status'  => false,
                'message' => 'Error al actualizar el nombre del usuario.'
            ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function register_entry_post(){
        $this->load->model('Parking');

        $auth = $this->verify_request();
        $guard_id = (int)$auth->sub;

        $plate  = strtoupper(trim((string)$this->post('plate')));
        $vtype  = (int)$this->post('vehicle_type');

        if ($plate === '' || $vtype === 0) {
            return $this->response([
                'status'  => false,
                'message' => 'Campos requeridos: plate, vehicle_type'
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        if (!in_array($vtype, [1,2,3], true)) {
            return $this->response([
                'status'  => false,
                'message' => 'vehicle_type inválido.'
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        if (strlen($plate) > 15) {
            return $this->response([
                'status'  => false,
                'message' => 'La placa excede 15 caracteres.'
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        // Registrar
        $result = $this->Parking->registerEntry($guard_id, $plate, $vtype);

        // Respuestas
        if ($result['status'] === 'ok') {
            return $this->response([
                'status'        => true,
                'message'       => 'Entrada registrada.',
                'session_id'    => (int)$result['session_id'],
                'vehicle_id'    => (int)$result['vehicle_id'],
                'entry_time'    => $result['entry_time'],
            ], REST_Controller::HTTP_CREATED);
        }

        if ($result['status'] === 'open_exists') {
            return $this->response([
                'status'      => false,
                'message'     => 'Este vehículo ya tiene una sesión abierta.',
                'session_id'  => (int)$result['session_id'],
                'entry_time'  => $result['entry_time'],
            ], REST_Controller::HTTP_CONFLICT);
        }

        return $this->response([
            'status'  => false,
            'message' => 'No se pudo registrar la entrada.',
            'error'   => $result['error'] ?? null
        ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function register_exit_post(){
        $this->load->model('Parking');

        $auth      = $this->verify_request();
        $closed_by = (int)$auth->sub;

        $plate     = strtoupper(trim((string)$this->post('plate')));

        if ($plate === '') {
            return $this->response([
                'status'  => false,
                'message' => 'El campo plate es requerido.'
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        $result = $this->Parking->registerExit($closed_by, $plate);

        // Respuestas
        if ($result['status'] === 'ok') {
            return $this->response([
                'status'       => true,
                'message'      => 'Salida registrada.',
                'session_id'   => (int)$result['session_id'],
                'vehicle_id'   => (int)$result['vehicle_id'],
                'entry_time'   => $result['entry_time'],
                'exit_time'    => $result['exit_time'],
                'hours_billed' => (int)$result['hours_billed'],
                'amount'       => (float)$result['amount'],
                'rate'         => (float)$result['rate'],
                'vehicle_type' => (int)$result['vehicle_type_id'],
            ], REST_Controller::HTTP_OK);
        }

        if ($result['status'] === 'not_found_vehicle') {
            return $this->response([
                'status'  => false,
                'message' => 'No existe un vehículo con esa placa.'
            ], REST_Controller::HTTP_NOT_FOUND);
        }

        if ($result['status'] === 'no_open_session') {
            return $this->response([
                'status'  => false,
                'message' => 'No hay sesión abierta para esa placa.'
            ], REST_Controller::HTTP_CONFLICT);
        }

        return $this->response([
            'status'  => false,
            'message' => 'No se pudo registrar la salida.',
            'error'   => $result['error'] ?? null
        ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function verify_request() {
        $headers = $this->input->request_headers();
        if (!isset($headers['Authorization'])) {
            $this->response(['mensaje' => 'Token no proporcionado'], 401);
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);

        try {
            $decoded = $this->jwt_lib->decode_token($token);
            return $decoded->data;
        } catch (Exception $e) {
            $this->response(['mensaje' => 'Token inválido o expirado'], 401);
        }
    }
}
