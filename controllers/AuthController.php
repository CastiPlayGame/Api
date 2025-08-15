<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;


class AuthManager
{
    private $db;
    private $SECRET_KEY = 'djnky57bnqtQLKAnokYtMDRY7TVdQsNILbxX0xbFvDCakbcSn3mu7mfgSMDmWfN0vMcCbwDOA9BvDzOBRKhHrqrjsePmoR8J57LO';
    private $TOKEN_EXPIRATION_TIME = 3600*1;


    function __construct()
    {

        $this->db = Flight::db();
        
    }

    private function getToken()
    {
        $headers = Flight::get('headers');

        try {
            if (!isset($headers["Authorization"])) {
                Flight::jsonHalt([
                    "response" => "No se encontró el header Authorization"
                ], 403);
            }

            $authorizationArray = explode(" ", $headers["Authorization"]);
            $token = $authorizationArray[1] ?? null;

            if($_ENV['API_KEY_ADMIN'] == $token){
                return (object)[
                    'data' => ['','','','admin']
                ];
            }

            return JWT::decode($token, new Key($this->SECRET_KEY, 'HS256'));
        } catch (\Throwable $th) {
            Flight::jsonHalt([
                "response" => $th->getMessage()
            ], 403);
        }
    }

    public function validateToken()
    {
        $headers = Flight::get('headers');

        $info = $this->getToken();

        if ($info->data[3] === 'admin'){
            return $info;
        }
        
        if (!isset($headers["X-App-Version"])) {
            Flight::jsonHalt([
                "response" => "No se encontró el header X-App-Version"
            ], 403);
        }
        $appVersion = $headers["X-App-Version"];

        if ($appVersion != '1.0.0'){
            Flight::jsonHalt([
                "response" => 'Hay una Nueva Actualizacion'
            ], 401);
        }
        
        $query = $this->db->prepare("
        SELECT 
            username,
            password,
            JSON_UNQUOTE(JSON_EXTRACT(acctAdvanced,'$.banned.canBuy')) AS canBuy
        FROM 
            users 
        WHERE 
            id = :id AND
            JSON_EXTRACT(acctAdvanced,'$.banned.ban') = false AND
            JSON_EXTRACT(acctAdvanced,'$.banned.canLogin') = false
        ");

        if ($query->execute([":id" => $info->data[0]])) {
            $user = $query->fetch();

            if (!$user){
                Flight::jsonHalt([
                    "response" => 'No autorizado'
                ], 403);
            }
            if ($info->data[1] != $user['username']) {
                Flight::jsonHalt([
                    "response" => 'No autorizado'
                ], 403);
            }
            if (!password_verify($info->data[2], $user['password'])) {
                Flight::jsonHalt([
                    "response" => 'No autorizado'
                ], 403);
            }
            array_push($info->data, $user["canBuy"]);
            return $info;
        } else {
            Flight::jsonHalt([
                "response" => "No autorizado"
            ], 401);
        }
    }

    public function generateToken($userId, $user, $pass)
    {
        $now = strtotime("now");
        $payload = [
            'exp' => $now + $this->TOKEN_EXPIRATION_TIME,
            'data' => [$userId, $user, $pass, 'user']
        ];
        return JWT::encode($payload, $this->SECRET_KEY, 'HS256');
    }

    public function newAuth()
    {
        $headers = Flight::get('headers');

        if (!isset($headers["X-App-Version"])) {
            Flight::jsonHalt([
                "response" => "No se encontró el header X-App-Version"
            ], 403);
        }
        $appVersion = $headers["X-App-Version"];

        if ($appVersion != '1.0.0'){
            Flight::jsonHalt([
                "response" => 'Hay una Nueva Actualizacion'
            ], 401);
        }

        $password = str_replace(" ", "", Flight::request()->data->password);
        $username = str_replace(" ", "", Flight::request()->data->username);
        $query = $this->db->prepare("
        SELECT 
            id,
            password,
            JSON_UNQUOTE(JSON_EXTRACT(acctAdvanced,'$.banned.canLogin')) AS canLogin,
            JSON_UNQUOTE(JSON_EXTRACT(acctAdvanced,'$.banned.ban')) AS ban
        FROM 
            users 
        WHERE 
            username = :username
        ");



        if ($query->execute([":username" => $username])) {
            $user = $query->fetch();

            if (!$user) {
                Flight::jsonHalt([
                    "response" => 'Usuario No Existe'
                ], 404);
            }

            if ($user["canLogin"] == "true" || $user["ban"] == "true") {
                Flight::jsonHalt([
                    "response" => 'Usuario Baneado'
                ], 404);
                
            }

            if (!password_verify($password, $user['password'])) {
                Flight::jsonHalt([
                    "response" => 'Datos Incorrectos'
                ], 401);
            }

            $token = $this->generateToken($user['id'], $username, $password);

            Flight::jsonHalt([
                "response" => $token
            ]);
        } else {
            Flight::jsonHalt([
                "response" => "No Se Pudo Validar El Usuario, Porfavor Intentelo Mas Tarde",
            ], 503);
        }
    }
}
