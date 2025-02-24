<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;


class AuthManager
{
    private $db;
    private $SECRET_KEY = 'your_secret_key_here';
    private $TOKEN_EXPIRATION_TIME = 3600*4;


    function __construct()
    {

        $this->db = Flight::db();
        
    }

    private function getToken()
    {
        $headers = apache_request_headers();

        try {
            $authorization = $headers["Authorization"];
            $authorizationArray = explode(" ", $authorization);
            $token = $authorizationArray[1];

            if($_ENV['API_KEY_ADMIN'] == $token){
                return (object)[
                    'data' => ['','','','admin']
                ];
            }

            return JWT::decode($token, new Key($this->SECRET_KEY, 'HS256'));
        } catch (\Throwable $th) {
            Flight::halt(403, json_encode([
                "response" => $th->getMessage()
            ]));
        }
    }

    public function validateToken()
    {
        $info = $this->getToken();

        if ($info->data[3] === 'admin'){
            return $info;
        }

        $query = $this->db->prepare("SELECT username,password FROM users WHERE id = :id");

        if ($query->execute([":id" => $info->data[0]])) {
            $user = $query->fetch();
            if ($info->data[1] != $user['username']) {
                Flight::halt(403, json_encode([
                    "response" => 'No autorizado'
                ]));
            }
            if (!password_verify($info->data[2], $user['password'])) {
                Flight::halt(403, json_encode([
                    "response" => 'No autorizado'
                ]));
            }

            return $info;
        } else {
            Flight::halt(401, json_encode([
                "response" => "No autorizado"
            ]));
        }
    }

    public function generateToken($userId,$user,$pass)
    {
        $now = strtotime("now");
        $payload = [
            'exp' => $now + $this->TOKEN_EXPIRATION_TIME,
            'data' => [$userId,$user,$pass,'user']
        ];
        return JWT::encode($payload, $this->SECRET_KEY, 'HS256');
    }

    public function newAuth()
    {
        $password = str_replace(" ", "", Flight::request()->data->password);
        $username = str_replace(" ", "", Flight::request()->data->username);
        $query = $this->db->prepare("SELECT id,password FROM users WHERE username = :username");



        if ($query->execute([":username" => $username])) {
            $user = $query->fetch();

            if (!$user) {
                Flight::halt(404, json_encode([
                    "response" => 'Usuario No Existe'
                ]));
            }
            if (!password_verify($password, $user['password'])) {
                Flight::halt(401, json_encode([
                    "response" => 'Datos Incorrectos'
                ]));
            }


            $token = $this->generateToken($user['id'], $username, $password);

            Flight::halt(message: json_encode([
                "response" => $token
            ]));
        } else {
            Flight::halt(503, json_encode([
                "response" => "No Se Pudo Validar El Usuario, Porfavor Intentelo Mas Tarde",
            ]));
        }
    }
}
