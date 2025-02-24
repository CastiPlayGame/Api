<?php

class PinesSys_ItemManager
{
    private $db;

    function __construct()
    {

        $this->db = Flight::db();
    }

    private function all_item_format(array $array, int $stock_min, int $stock_max)
    {
        foreach ($array as &$item) {
            $total = (new ItemUtils())->item_quantity_for_all(json_decode($item['quantity'], true));
            $item['quantity'] = $total;

            if ($stock_min != null && $total < $stock_min) {
                $item = null;
            }
    
            if ($stock_max != null && $total > $stock_max) {
                $item = null;
            }
        }
        return array_values(array_filter($array));
    }

    private function get_item_format(array &$array)
    {
        if (empty($array)) {
            return [];
        }

        $array['quantity'] = (new ItemUtils())->item_quantity_for_all(json_decode($array['quantity'], true));
        $array['photo'] = ($array['photo'] !== "null" && $array['photo'] !== null) ? json_decode($array['photo'], true) : [];
        $array['prices'] = json_decode($array['prices'], true);

        foreach ($array['prices'] as &$value) {
            $value = floatval($value);
        }

        $array['blacklist'] = json_decode($array['blacklist'], true);

        return $array;
    }

    public function get_item_quantity($uuid)
    {
        try {
            $query = $this->db->prepare("
            SELECT 
                quantity
            FROM 
                `items` i
            WHERE 
                i.`uuid` = :uuid;
        ");

            $query->execute([":uuid" => $uuid]);
            $item = $query->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                Flight::halt(404, json_encode([
                    "response" => "Item No Existe"
                ]));
            }
            $tempStdClass = json_decode($item["quantity"], true);
            if (!empty($tempStdClass)) {
                Flight::halt(message: json_encode([
                    "response" => $tempStdClass[1]
                ]));
            } else {
                Flight::halt(404, json_encode([
                    "response" => "Item Agotado"
                ]));
            }
        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

    private function calculateMostSell()
    {
        $query = $this->db->prepare("SELECT buy, event FROM `sales`");
        $query->execute();
        $result = $query->fetchAll(PDO::FETCH_ASSOC);

        $output = [];
        $sales_totals = [];

        foreach ($result as $row) {
            $buyData = json_decode($row["buy"], true);
            $eventData = json_decode($row["event"], true);

            $date = null;
            foreach ($eventData as $a) {
                if ($a["event"] == 0) $date = $a["date"];
                if ($a["event"] == 3) {
                    $date = null;
                    break;
                }
            }
            if (!$date || "2024" != date("Y", strtotime($date))) continue;

            $month = date("n", strtotime($date)) - 1;

            foreach ($buyData as $c) {
                $code = $c["code"];
                if (!isset($output[$code])) $output[$code] = array_fill(0, 12, 0);

                foreach ($c["packs"] as $k => $v) {
                    $output[$code][$month] += $k * $v;
                }
            }
        }

        // Calcular totales y ordenar
        foreach ($output as $code => $monthly_sales) {
            $total_sales = array_sum($monthly_sales);
            if ($total_sales > 0) $sales_totals[$code] = $total_sales;
        }

        $top_base = array_slice(arsort($sales_totals) ? $sales_totals : [], 0, 20, true);
        return $top_base;
    }

    public function all_item()
    {


        $search = Flight::request()->query->search ?? "";
        $departaments = Flight::request()->query->departaments ?? [];
        if (!empty($departaments)) {
            $departaments = json_decode($departaments, true);
        }


        $stock_min = intval(Flight::request()->query->stock_min ?? null);
        $stock_max = intval(Flight::request()->query->stock_max ?? null);

        try {
            $query = $this->db->prepare("
            SELECT 
                `items`.`uuid`, 
                `items`.`id`, 
                quantity, 
                JSON_VALUE(`items`.`info`, '$.desc') AS `description`,  -- Cambié 'desc' a 'description'
                JSON_UNQUOTE(JSON_EXTRACT(`items`.`photos`, '$[0].name')) AS `photo`
            FROM 
                `items`
            JOIN 
                `departments` AS d ON d.`uuid` = JSON_VALUE(`items`.`info`, '$.departament')
            WHERE 
                `items`.`id` LIKE :search
                AND (
                    CASE 
                        WHEN :depas IS NOT NULL AND TRIM(:depas) <> '' THEN 
                            JSON_VALUE(`items`.`info`, '$.departament') IN (:depas)
                        ELSE TRUE
                    END
                )
            ORDER BY 
                `items`.`id` ASC;
            ");



            $query->execute([
                ":aes" => $_ENV['AES_KEY'],
                ":search" => "%" . $search . "%",
                ":depas" => implode("','", $departaments)
            ]);

            $items = $query->fetchAll(PDO::FETCH_ASSOC);


            $tempStdClass = $this->all_item_format($items, $stock_min, $stock_max);
            $most_sales = array_keys($this->calculateMostSell());

            if (count($items) > 0 and !empty((array)$tempStdClass)) {
                Flight::halt(message: json_encode([
                    "response" => [
                        "products" => $tempStdClass,
                        "products_most_hot" => $most_sales
                    ]
                ]));
            } else {
                Flight::halt(404, json_encode([
                    "response" => "No Hay Productos Disponibles"
                ]));
            }
        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

    public function get_item($uuid)
    {
        try {
            $query = $this->db->prepare("
            SELECT 
                quantity,
                d.name AS depa,
                JSON_VALUE(i.info, '$.brand') AS brand,
                JSON_VALUE(i.info, '$.desc') AS descp,
                JSON_VALUE(i.info, '$.model') AS model,
                JSON_VALUE(CAST(AES_DECRYPT(i.advanced,  :aes) AS CHAR), '$.hide') AS hide,
                JSON_VALUE(CAST(AES_DECRYPT(i.advanced,  :aes) AS CHAR), '$.views') AS blacklist,
                JSON_UNQUOTE(JSON_EXTRACT(i.`photos`, '$[*].name')) AS `photo`,
                CAST(AES_DECRYPT(i.prices, :aes) AS CHAR) as prices
            FROM 
                `items` i
            JOIN 
                departments d ON JSON_EXTRACT(i.info,'$.departament') = d.uuid
            WHERE 
                i.`uuid` = :uuid
        ");

            $query->execute([":aes" => $_ENV['AES_KEY'], ":uuid" => $uuid]);
            $item = $query->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                Flight::halt(404, json_encode([
                    "response" => "Item Agotado"
                ]));
            }
            $tempStdClass = $this->get_item_format($item);

            if (!empty($tempStdClass)) {
                Flight::halt(message: json_encode([
                    "response" => $tempStdClass
                ]));
            } else {
                Flight::halt(404, json_encode([
                    "response" => "Item Agotado"
                ]));
            }
        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

    public function get_item_prices($uuid)
    {
        try {
            $query = $this->db->prepare("
            SELECT 
                CAST(AES_DECRYPT(i.prices, :aes) AS CHAR) as prices
            FROM 
                `items` i
            WHERE 
                i.`uuid` = :uuid
        ");

            $query->execute([":aes" => $_ENV['AES_KEY'], ":uuid" => $uuid]);
            $item = $query->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                Flight::halt(404, json_encode([
                    "response" => "Item Agotado"
                ]));
            }

            $item = json_decode($item["prices"], true);
            foreach ($item as &$value) {
                $value = floatval($value);
            }

            Flight::halt(message: json_encode([
                "response" => $item
            ]));

        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

    public function get_item_provider($uuid)
    {
        try {
            $query = $this->db->prepare("
            SELECT
                COALESCE(`id_provider`, '') as id,
                COALESCE(JSON_VALUE(CAST(AES_DECRYPT(i.advanced, :aes) AS CHAR), \"$.provider\"), '') as provider,
                COALESCE(JSON_VALUE(CAST(AES_DECRYPT(i.advanced, :aes) AS CHAR), \"$.provider_price\"), '') as price
            FROM 
                `items` i
            WHERE 
                i.`uuid` = :uuid
        ");

            $query->execute([":aes" => $_ENV['AES_KEY'], ":uuid" => $uuid]);
            $item = $query->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                Flight::halt(404, json_encode([
                    "response" => "Item Agotado"
                ]));
            }

            $item["price"] = floatval($item["price"]);

            Flight::halt(message: json_encode([
                "response" => $item
            ]));

        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

    public function get_item_black_list($uuid)
    {
        try {
            $query = $this->db->prepare("
                SELECT
                    COALESCE(JSON_VALUE(CAST(AES_DECRYPT(i.advanced, :aes) AS CHAR), \"$.views\"), '') as black_list
                FROM 
                    `items` i
                WHERE 
                    i.`uuid` = :uuid
            ");

            $query->execute([":aes" => $_ENV['AES_KEY'], ":uuid" => $uuid]);
            $item = $query->fetch(PDO::FETCH_ASSOC);

            $query_clients = $this->db->prepare("
                SELECT
                    id,
                    JSON_EXTRACT(CAST(AES_DECRYPT(acctPersonal, :aes) AS CHAR), \"$.user\") AS nameAndLastName
                FROM 
                    `users`
                LIMIT 15
            ");

            $query_clients->execute([":aes" => $_ENV['AES_KEY']]);
            $clients = $query_clients->fetchAll(PDO::FETCH_ASSOC);


            $blacklist = json_decode($item['black_list'], true);
            $clientNames = [];

            foreach ($clients as $client) {
                $clientNames[$client["id"]] = implode(' ', json_decode($client['nameAndLastName']));
            }

            if (!$item) {
                Flight::halt(404, json_encode([
                    "response" => "Item Agotado"
                ]));
            }


            Flight::halt(message: json_encode([
                "response" => [
                    "clients" => $clientNames,
                    "black_list" => $blacklist
                ]
            ]));

        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

    public function update_item_info($uuid)
    {
        try {
            $query = $this->db->prepare("
                UPDATE `items` i
                SET 
                    info = JSON_SET(
                        info,
                        '$.departament', :departament,
                        '$.brand', :brand,
                        '$.desc', :desc,
                        '$.model', :model
                    ),
                    advanced = AES_ENCRYPT(
                        JSON_SET(
                            CAST(AES_DECRYPT(advanced, :aes) AS CHAR),
                            '$.hide', :hide
                        ),
                        :aes
                    )
                WHERE 
                    i.`uuid` = :uuid
            ");
            
            $query->execute([
                ":aes" => $_ENV['AES_KEY'],
                ":uuid" => $uuid,
                ":departament" => Flight::request()->data->departament,
                ":brand" => Flight::request()->data->brand,
                ":model" => Flight::request()->data->model,
                ":desc" => Flight::request()->data->desc,
                ":hide" => Flight::request()->data->hide ? "true" : "false"
            ]);

            if ($query->rowCount() > 0) {
                Flight::json([
                    "response" => "Item actualizado correctamente"
                ], 200);
            } else {
                Flight::json([
                    "response" => "No se encontró el item o no se realizaron cambios"
                ], 404);
            }

        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }


    public function update_item_prices($uuid)
    {
        try {
            $query = $this->db->prepare("
                UPDATE `items` i
                SET 
                    prices = AES_ENCRYPT(
                        JSON_ARRAY(:price1, :price2, :price3, :price4),
                        :aes
                    )
                WHERE 
                    i.`uuid` = :uuid
            ");
            
            $query->execute([
                ":aes" => $_ENV['AES_KEY'],
                ":uuid" => $uuid,
                ":price1" => Flight::request()->data->price1,
                ":price2" => Flight::request()->data->price2,
                ":price3" => Flight::request()->data->price3,
                ":price4" => Flight::request()->data->price4
            ]);

            if ($query->rowCount() > 0) {
                Flight::json([
                    "response" => "Item actualizado correctamente"
                ], 200);
            } else {
                Flight::json([
                    "response" => "No se encontró el item o no se realizaron cambios"
                ], 404);
            }

        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }


    public function update_item_provider($uuid)
    {
        try {
            $query = $this->db->prepare("
                UPDATE `items` i
                SET 
                    id_provider = :code, 
                    advanced = AES_ENCRYPT(
                        JSON_SET(
                            CAST(AES_DECRYPT(advanced, :aes) AS CHAR),
                            '$.provider', :provider,
                            '$.provider_price', :price
                        ),
                        :aes
                    )
                WHERE 
                    i.`uuid` = :uuid
            ");
            
            $query->execute([
                ":aes" => $_ENV['AES_KEY'],
                ":uuid" => $uuid,
                ":code" => Flight::request()->data->code,
                ":provider" => Flight::request()->data->provider,
                ":price" => Flight::request()->data->price
            ]);

            if ($query->rowCount() > 0) {
                Flight::json([
                    "response" => "Item actualizado correctamente"
                ], 200);
            } else {
                Flight::json([
                    "response" => "No se encontró el item o no se realizaron cambios"
                ], 404);
            }

        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

    public function update_item_blacklist($uuid)
    {
        try {
            $query = $this->db->prepare("
                UPDATE `items` i
                SET 
                    advanced = AES_ENCRYPT(
                        JSON_SET(
                            CAST(AES_DECRYPT(advanced, :aes) AS CHAR),
                            '$.views', :blacklist
                        ),
                        :aes
                    )
                WHERE 
                    i.`uuid` = :uuid
            ");
            
            $query->execute([
                ":aes" => $_ENV['AES_KEY'],
                ":uuid" => $uuid,
                ":blacklist" => json_encode(Flight::request()->data->blacklist)
            ]);

            if ($query->rowCount() > 0) {
                Flight::json([
                    "response" => "Item actualizado correctamente"
                ], 200);
            } else {
                Flight::json([
                    "response" => "No se encontró el item o no se realizaron cambios"
                ], 404);
            }

        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

    public function delete_item($uuid)
    {
        try {
            $query = $this->db->prepare("
                DELETE FROM 
                    `items`
                WHERE 
                    `uuid` = :uuid
            ");
            
            $query->execute([
                ":uuid" => $uuid
            ]);

            if ($query->rowCount() > 0) {
                Flight::json([
                    "response" => "Item Eliminado correctamente"
                ], 200);
            } else {
                Flight::json([
                    "response" => "No se encontró el item o no se realizaron cambios"
                ], 404);
            }

        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

}
