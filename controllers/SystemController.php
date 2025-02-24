<?php


class SystemManager
{
    private $db;
    private $orderStatus;

    function __construct()
    {
        $this->db = Flight::db();
        $this->orderStatus = [
            "En Espera", 
            "En Stanby", 
            "Analizando", 
            "Procesada", 
            "Anulada"
        ];

    }
    private function format_order(array &$array){
        foreach ($array as &$item) {
            $item["buy"] = json_decode($item["buy"], true);
            $item["info"] = json_decode($item["info"], true);
            $item["status"] = intval($item["status"]);
            $item["statusName"] = $this->orderStatus[$item["status"]];
        }
        return $array;
    }

    function get_order(){
        try {
            $query = $this->db->prepare("
                SELECT 
                    id,
                    buy, 
                    CAST(AES_DECRYPT(info, :aes) AS CHAR) AS info, 
                    JSON_UNQUOTE(JSON_EXTRACT(advanced,'$.name')) AS name,
                    JSON_EXTRACT(`status`, '$[last].event') as status
                FROM 
                    retainedpurchases
                WHERE
                    NOT JSON_EXTRACT(`status`, '$[last].event') IN (3,4)
                ");

            $query->execute([":aes" => $_ENV["AES_KEY"]]);
            $item = $query->fetchAll(PDO::FETCH_ASSOC);

            if (!$item) {
                Flight::halt(404, json_encode([
                    "response" => "No hay pedidos"
                ]));
            }
            Flight::halt(message: json_encode([
                "response" => $this->format_order($item)
            ]));
        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }
}