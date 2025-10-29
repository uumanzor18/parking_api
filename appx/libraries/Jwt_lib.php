<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Jwt_lib {

    protected $key;
    protected $algorithm;
    protected $expire;

    public function __construct() {
        $CI =& get_instance();
        $CI->load->config('jwt');
        $this->key = $CI->config->item('jwt_key');
        $this->algorithm = $CI->config->item('jwt_algorithm');
        $this->expire = $CI->config->item('jwt_token_expire');
    }

    public function generate_token($data) {
        $payload = [
            'iss' => base_url(),
            'iat' => time(),
            'exp' => time() + $this->expire,
            'data' => $data
        ];
        return JWT::encode($payload, $this->key, $this->algorithm);
    }

    public function decode_token($token) {
        return JWT::decode($token, new Key($this->key, $this->algorithm));
    }
}
