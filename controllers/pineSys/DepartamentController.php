<?php

class PinesSys_DepartamentManager
{
    private $db;

    function __construct()
    {

        $this->db = Flight::db();
    }

    public function all_item()
    {

        try {
            $query2 = $this->db->prepare("SELECT uuid, name, JSON_EXTRACT(`advanced`, '$.hide') as hide FROM `departments` ");

            $query2->execute([]);
            $depa = $query2->fetchAll(PDO::FETCH_ASSOC);

            if (count($depa) > 0) {
                Flight::halt(message: json_encode([
                    "response" => $depa
                ]));
            } else {
                Flight::halt(404, json_encode([
                    "response" => "No Hay Departamentos"
                ]));
            }
        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

}
