<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User extends CI_Model
{
    private $table = 'users';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function validate_credentials($username, $password){
         $username = trim($username);
        $password = trim($password);

        $query = $this->db->select('id, username, password_hash, role_id, full_name')
                        ->from($this->table)
                        ->where('username', $username)
                        ->limit(1)
                        ->get();

        if ($query->num_rows() !== 1) {
            return false;
        }

        $user = $query->row();

        // Comparar el texto ingresado vs el hash guardado
        if (!password_verify($password, $user->password_hash)) {
            return false;
        }

        // (Opcional) Rehash si cambió el coste por configuración
        if (password_needs_rehash($user->password_hash, PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $this->db->where('id', $user->id)->update($this->table, ['password_hash' => $newHash]);
        }

        return $user;
    }

    public function getUsuarios(){
        $exists = $this->db->select('1', false)
            ->from($this->table)
            ->where('username', $data['user'])
            ->limit(1)
            ->get()
            ->num_rows() === 1;

        if ($exists) {
            return ['status' => 'conflict'];
        }

        $q = $this->db->select('id, username, full_name, role_id, created_at')
            ->from($this->table)
            ->order_by('id', 'desc')
            ->get();

        return $q->result();
    }


    public function createUsuario(array $data): array{
        // 1) Evitar duplicado
        $exists = $this->db->select('1', false)
            ->from($this->table)
            ->where('username', $data['user'])
            ->limit(1)
            ->get()
            ->num_rows() === 1;

        if ($exists) {
            return ['status' => 'conflict'];
        }

        // 2) Insert transaccional
        $this->db->trans_start();

        $passwordToStore = password_hash($data['pass'], PASSWORD_DEFAULT);
        
        $this->db->insert($this->table, [
            'username'     => $data['user'],
            'password_hash'=> $passwordToStore, // ver nota
            'role_id'      => $data['role'],
            'full_name'    => $data['name'],
            'created_at'   => date('Y-m-d H:i:s')
        ]);

        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            $dbError = $this->db->error(); // ['code'=>..., 'message'=>...]
            return [
                'status' => 'error',
                'error'  => $dbError['message'] ?? 'DB transaction failed'
            ];
        }

        return [
            'status' => 'ok',
            'id'     => $this->db->insert_id()
        ];
    }

    public function blockUser($user_id){
        $query = $this->db->select('id, username, role_id, is_blocked')
                        ->from($this->table)
                        ->where('id', $user_id)
                        ->limit(1)
                        ->get();

        if ($query->num_rows() !== 1) {
            return ['status' => 'not_found'];
        }

        $user = $query->row();

        // Solo se pueden bloquear usuarios con rol_id = 2
        if ((int)$user->role_id !== 2) {
            return ['status' => 'forbidden'];
        }

        // Ya bloqueado
        if ((int)$user->is_blocked === 1) {
            return ['status' => 'ok', 'message' => 'El usuario ya estaba bloqueado'];
        }

        // Bloquear
        $this->db->where('id', $user_id)
                ->update($this->table, [
                    'is_blocked' => 1
                ]);

        if ($this->db->affected_rows() > 0) {
            return ['status' => 'ok'];
        } else {
            return ['status' => 'error'];
        }
    }

    public function updateOwnName($username, $new_name){
        // Validar existencia del usuario
        $query = $this->db->select('id, full_name')
                        ->from($this->table)
                        ->where('username', $username)
                        ->limit(1)
                        ->get();

        if ($query->num_rows() !== 1) {
            return ['status' => 'not_found'];
        }

        // Actualizar solo su propio nombre
        $this->db->where('username', $username)
                ->update($this->table, [
                    'full_name'   => $new_name
                ]);

        if ($this->db->affected_rows() > 0) {
            return ['status' => 'ok'];
        } else {
            return ['status' => 'error'];
        }
    }
}
