<?php

class SaleController
{
    private $db;

    function __construct()
    {
        $this->db = Flight::db();
    }

    private function format_all_retained($array)
    {
        foreach ($array as &$item) {
            $item["buy"] = json_decode($item["buy"], true);
            $item["items"] = count($item["buy"]);
            $item["price"] = (new ItemUtils())->item_price($item["buy"]);
            if ($item["price"][1] != 0){
                $item["price"] = $item["price"][1];
            }else{
                $item["price"] = $item["price"][0];
            }

            $item["event"] = intval($item["event"]);
            unset($item["buy"]);
        }
        return $array;
    }

    private function get_event($array)
    {
        $Paid = 0;
        foreach (json_decode($array["paid"], true) as $value) $Paid += floatval($value["ammount"]);

        if (intval($array["event"]) == 3) {
            return 6;
        } else if (intval($array["event"]) < 2) {
            return $array["event"];
        } else if (intval($array["event"]) == 4) {
            return 5;
        }
        if ($Paid >= $array["price"]) {
            return 2;
        }

        $summary = (new SaleUtils())->isOverdue($array['date'], $array['credit']);
        $event = ($summary['isOverdue']) ?  3 : 4;
        return $event;
    }

    private function get_is_over_due($array)
    {
        if ($array["event"] <= 3 and $array["event"] != 2) {
            return false;
        }
        if ($array["paid"] >= $array["cost"]) {
            return true;
        }
        $summary = (new SaleUtils())->isOverdue($array['date'], $array['credit']);
        $summaryReamining = (new SaleUtils())->getRemainingTimeString($summary);

        $result = [];
        $result[0] = ($summary['isOverdue']) ? "Vencida Hace " . $summaryReamining : 'Credito de ' . $summaryReamining;

        $result[1] = number_format(($array["cost"] - $array["paid"]), 2, '.', ',') . "$";
        return $result;
    }


    private function format_all_sale($array)
    {
        foreach ($array as &$item) {
            $item["buy"] = json_decode($item["buy"], true);
            $item["price"] = (new ItemUtils())->item_price($item["buy"]);
            $item["event"] = intval($this->get_event($item));
            $item["items"] = count($item["buy"]);

            $item["price"] = (new ItemUtils())->item_price($item["buy"]);
            if ($item["price"][1] != 0){
                $item["price"] = $item["price"][1];
            }else{
                $item["price"] = $item["price"][0];
            }
    
            $discount = json_decode($item["discount"], true);
            if (!empty($discount[0])) {
                if ($discount[1] == "true") {
    
    
                    $item["price"] -= ($item["price"] * $discount[0]) / 100;
                    $item["discount"] = $discount[0] . "%";
                } else {
                    $item["price"] -= $discount[0];
                    $item["discount"] = $discount[0] . "$";
                }
            } else {
                unset($item["discount"]);
            }

            unset($item["buy"]);
            unset($item["paid"]);
            unset($item["credit"]);
        }
        return $array;
    }

    private function format_sale($array)
    {
        $sales = array("En Almacen", "En Transito", "Entregada", "Anulada", "Bloqueado");

        $array["buy"] = array_values(array_map(function ($v) {
            return array_filter($v, function ($k) {
                return $k !== 'depo';
            }, ARRAY_FILTER_USE_KEY);
        }, json_decode($array["buy"], true)));


        $array["event"] = $sales[(int) $array["event"]];
        $array["credit"] = (int) $array["credit"];
        $array["paid"] = array_sum(array_column(json_decode($array["paid"], true), "ammount"));
        $array["cost"] = (new ItemUtils())->item_price($array["buy"]);
        if ($array["cost"][1] != 0){
            $array["cost"] = (float) number_format($array["cost"][1], 2, '.', ',');
        }else{
            $array["cost"] = (float) number_format($array["cost"][0], 2, '.', ',');
        }

        $discount = json_decode($array["discount"], true);
        if (!empty($discount[0])) {
            if ($discount[1] == true) {
                $array["cost"] -= ($array["cost"] * $discount[0]) / 100;
            } else {
                $array["cost"] -= $discount[0];
            }
            $array["discount"] = $discount;
        } else {
            unset($array["discount"]);
        }
        $array["cost"] = (float) number_format($array["cost"], 2, '.', ',');

        if (empty($array["info"])) {
            unset($array["info"]);
        }
        if (empty($array["coment"])) {
            unset($array["coment"]);
        }
        if (empty($array["paid"])) {
            unset($array["paid"]);
            $array["over"] = false;
        }else{
            $array["over"] = $this->get_is_over_due($array);
        }


        return $array;
    }

    private function format_retained($array)
    {
        $retained = array("En Espera", "En Stanby", "Analizando");

        $array["buy"] = array_values(array_map(function ($v) {
            return array_filter($v, function ($k) {
                return $k !== 'depo';
            }, ARRAY_FILTER_USE_KEY);
        }, json_decode($array["buy"], true)));



        $array["cost"] = (new ItemUtils())->item_price($array["buy"]);
        if ($array["cost"][1] != 0){
            $array["cost"] = (float) number_format($array["cost"][1], 2, '.', ',');
        }else{
            $array["cost"] = (float) number_format($array["cost"][0], 2, '.', ',');
        }


        $array["event"] = $retained[(int) $array["event"]];

        if (empty($array["coment"])) {
            unset($array["coment"]);
        }
        return $array;
    }

    private function filter_all_sales($token, $type)
    {
        $r = [[], []];
        try {
            $queries = [
                0 => [
                    "node" => "AND NOT JSON_EXTRACT(`status`,CONCAT(\"$[\",JSON_LENGTH(`status`)-1,\"].event\")) IN (3,4)",
                    "table" => "retainedpurchases",
                    "event" => "status",
                    "columns" => "`id`, `buy`, JSON_VALUE(`advanced`, '$.name') as name, JSON_UNQUOTE(JSON_EXTRACT(`status`,CONCAT(\"$[\",JSON_LENGTH(`status`)-1,\"].event\"))) as event, DATE_FORMAT(JSON_UNQUOTE(JSON_EXTRACT(`status`,CONCAT(\"$[\",JSON_LENGTH(`status`)-1,\"].date\"))), '%m/%d/%Y %h:%i %p') AS date",
                    "format" => "format_all_retained"
                ],
                1 => [
                    "node" => "AND NOT JSON_EXTRACT(`event`,CONCAT(\"$[\",JSON_LENGTH(`event`)-1,\"].event\")) IN (4,3)",
                    "table" => "sales",
                    "event" => "event",
                    "columns" => "`uuid`, `nr`, CAST(AES_DECRYPT(`paids`, :aes) AS CHAR) as paid, JSON_VALUE(`advanced`, '$.additionals.credit') as credit, JSON_VALUE(`advanced`, '$.additionals.name') as name, JSON_VALUE(`advanced`, '$.additionals.discount') as discount, `buy`, JSON_UNQUOTE(JSON_EXTRACT(`event`,CONCAT(\"$[\",JSON_LENGTH(`event`)-1,\"].event\"))) as event, DATE_FORMAT(JSON_UNQUOTE(JSON_EXTRACT(`event`,CONCAT(\"$[\",JSON_LENGTH(`event`)-1,\"].date\"))), '%m/%d/%Y %h:%i %p') AS date",
                    "format" => "format_all_sale"
                ],
                -1 => [
                    "node" => "AND JSON_EXTRACT(`event`,CONCAT(\"$[\",JSON_LENGTH(`event`)-1,\"].event\")) IN (3)",
                    "table" => "sales",
                    "event" => "event",
                    "columns" => "`uuid`, `nr`, CAST(AES_DECRYPT(`paids`, :aes) AS CHAR) as paid, JSON_VALUE(`advanced`, '$.additionals.credit') as credit, JSON_VALUE(`advanced`, '$.additionals.name') as name, JSON_VALUE(`advanced`, '$.additionals.discount') as discount, `buy`, JSON_UNQUOTE(JSON_EXTRACT(`event`,CONCAT(\"$[\",JSON_LENGTH(`event`)-1,\"].event\"))) as event, DATE_FORMAT(JSON_UNQUOTE(JSON_EXTRACT(`event`,CONCAT(\"$[\",JSON_LENGTH(`event`)-1,\"].date\"))), '%m/%d/%Y %h:%i %p') AS date",
                    "format" => "format_all_sale"
                ]
            ];

            if (isset($queries[$type])) {
                $queryData = $queries[$type];
                $query = $this->db->prepare("
                    SELECT 
                        ".$queryData['columns']."
                    FROM 
                        `".$queryData['table']."`
                    WHERE 
                        JSON_VALUE(CAST(AES_DECRYPT(`info`, :aes) AS CHAR), '$[0]') = :client_id
                        ".$queryData['node']."
                        AND DATE_SUB(CURDATE(), INTERVAL 60 DAY) <= JSON_UNQUOTE(JSON_EXTRACT(`".$queryData["event"]."`,\"$[0].date\"));
                ");
                $query->execute([":aes" => $_ENV['AES_KEY'], ":client_id" => $token->data[0]]);
                $r[0] = $this->{$queryData['format']}($query->fetchAll(PDO::FETCH_ASSOC));
            }

            return $r;

        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

    function all_sales()
    {
        $token = Flight::get('token');

        $filt_type_sales = Flight::request()->data->type_sales;

        try {
            $filts = $this->filter_all_sales($token, $filt_type_sales);
            $result = $filts[0];

            if (count($result) > 0) {
                Flight::halt(message: json_encode([
                    "response" => [
                        "result" => $result,
                    ]
                ]));
            } else {
                Flight::halt(404, json_encode([
                    "response" => "No Hay Compras.",
                ]));
            }
        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

    function get_sale($type, $uuid)
    {
        $token = Flight::get('token');
        try {
            switch ($type) {
                case 'r':
                    $query = $this->db->prepare("
                        SELECT 
                            `id`,
                            `buy`,
                            JSON_VALUE(`advanced`, '$.name') as name,
                            DATE_FORMAT(JSON_UNQUOTE(JSON_EXTRACT(`status`,'$[0].date')), '%m/%d/%Y %h:%i %p') AS create_at,
                            JSON_UNQUOTE(JSON_EXTRACT(`status`,CONCAT(\"$[\",JSON_LENGTH(`status`)-1,\"].event\"))) as event,
                            JSON_UNQUOTE(JSON_EXTRACT(`status`,CONCAT(\"$[\",JSON_LENGTH(`status`)-1,\"].coment\"))) as coment,
                            DATE_FORMAT(JSON_UNQUOTE(JSON_EXTRACT(`status`,CONCAT(\"$[\",JSON_LENGTH(`status`)-1,\"].date\"))), '%m/%d/%Y %h:%i %p') AS date
                        FROM 
                            `retainedpurchases`
                        WHERE 
                            JSON_VALUE(CAST(AES_DECRYPT(`info`, :aes) AS CHAR), '$[0]') = :client_id
                            AND `id` = :uuid
                            AND NOT JSON_EXTRACT(`status`,CONCAT(\"$[\",JSON_LENGTH(`status`)-1,\"].event\")) IN (3,4);
                    ");
                    break;
                case 's':
                    $query = $this->db->prepare("
                    SELECT 
                        `uuid`,
                        `nr`,
                        `type`,
                        CAST(AES_DECRYPT(`paids`, :aes) AS CHAR) as paid,
                        JSON_VALUE(`advanced`, '$.additionals.credit') as credit,
                        JSON_VALUE(`advanced`, '$.additionals.coment') as info,
                        JSON_VALUE(`advanced`, '$.additionals.name') as name,
                        JSON_VALUE(`advanced`, '$.additionals.discount') as discount,
                        `buy`,
                        DATE_FORMAT(JSON_UNQUOTE(JSON_EXTRACT(`event`,'$[0].date')), '%m/%d/%Y %h:%i %p') AS create_at,
                        JSON_UNQUOTE(JSON_EXTRACT(`event`,CONCAT(\"$[\",JSON_LENGTH(`event`)-1,\"].event\"))) as event,
                        JSON_UNQUOTE(JSON_EXTRACT(`event`,CONCAT(\"$[\",JSON_LENGTH(`event`)-1,\"].coment\"))) as coment,
                        DATE_FORMAT(JSON_UNQUOTE(JSON_EXTRACT(`event`,CONCAT(\"$[\",JSON_LENGTH(`event`)-1,\"].date\"))), '%m/%d/%Y %h:%i %p') AS date
                    FROM 
                        `sales`
                    WHERE 
                        JSON_VALUE(CAST(AES_DECRYPT(`info`, :aes) AS CHAR), '$[0]') = :client_id
                        AND `uuid` = :uuid;
                    ");
                    break;
            }



            $query->execute([":aes" => $_ENV['AES_KEY'], ":client_id" => $token->data[0], ":uuid" => $uuid]);
            $sales = $query->fetch(PDO::FETCH_ASSOC);

            if ($sales) {
                switch ($type) {
                    case 'r':
                        $reponse = $this->format_retained($sales);
                        break;
                    case 's':
                        $reponse = $this->format_sale($sales);
                        break;
                }
                Flight::halt(message: json_encode([
                    "response" => $reponse
                ]));
            } else {
                Flight::halt(404, json_encode([
                    "response" => "No Hay Compras.",
                ]));
            }
        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }
}
