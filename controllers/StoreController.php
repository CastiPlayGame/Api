<?php

use Ramsey\Uuid\Uuid;


class StoreController
{
    private $db;

    function __construct()
    {

        $this->db = Flight::db();
    }

    public function get_info()
    {
        $token = Flight::get('token');

        $cartData = Flight::request()->data->cart;
        if (is_array($cartData)) {
            $cart = $cartData;
        } elseif (is_string($cartData)) {
            $cart = json_decode($cartData);
        } else {
            Flight::halt(400, json_encode([
                "response" => "Ups hubo un problema con el formBody"
            ]));
        }

        Flight::halt(message: json_encode([
            "response" => $this->get_cart_items($cart)
        ]));
    }

    public function push_buy()
    {
        $this->db->setAttribute(PDO::ATTR_AUTOCOMMIT, 0);

        $token = Flight::get('token');

        $cartData = Flight::request()->data->cart;
        $namePedding = Flight::request()->data->name;

        if (is_array($cartData)) {
            $cart = $cartData;
        } elseif (is_string($cartData)) {
            $cart = json_decode($cartData, true);
        } else {
            Flight::halt(503, json_encode([
                "response" => "!Ups. hubo un problema con el formBody"
            ]));
        }

        if (empty($cart)) {
            Flight::halt(404, json_encode([
                "response" => "!Ups. el carrito esta vacio¡"
            ]));
        }
        
        if ($token->data[4] == "true") {
            Flight::halt(402, json_encode([
                "response" => "!Ups. No Tienes Acceso Para Hacer Compras¡"
            ]));
        }

        try {
            $this->db->beginTransaction();
            $warehouse = $this->seacrh_warehouses_and_sub($token, $cart);

            if (empty($warehouse[2])) {
                $this->db->rollBack();
                Flight::halt(404, json_encode([
                    "response" => "!Todos Los Productos Agotados¡"
                ]));
            }
            $get_errors = $this->check_if_items_all_pass($warehouse[2], $warehouse[1]);
            $query = $this->db->prepare(
                "INSERT INTO `retainedpurchases` (
                    `id`,
                    `info`,
                    `buy`,
                    `status`,
                    `advanced`
                )
                VALUES (
                    :uuid,
                    AES_ENCRYPT(
                        JSON_ARRAY(
                            :client_id,
                            JSON_ARRAY(
                                (SELECT JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(`acctPersonal`, :aes) AS CHAR), '$.persId.type')) AS type FROM users WHERE `id` = :client_id),
                                (SELECT JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(`acctPersonal`, :aes) AS CHAR), '$.persId.idenfication')) AS ident FROM users WHERE `id` = :client_id)
                            ),
                            (SELECT CONCAT(
                                CASE WHEN JSON_VALUE(CAST(AES_DECRYPT(acctPersonal, :aes) AS CHAR), '$.user[0]') IS NOT NULL THEN JSON_VALUE(CAST(AES_DECRYPT(acctPersonal, :aes) AS CHAR), '$.user[0]') ELSE '' END,
                                ' ',
                                CASE WHEN JSON_VALUE(CAST(AES_DECRYPT(acctPersonal, :aes) AS CHAR), '$.user[1]') IS NOT NULL THEN JSON_VALUE(CAST(AES_DECRYPT(acctPersonal, :aes) AS CHAR), '$.user[1]') ELSE '' END
                            ) AS users FROM users WHERE `id` = :client_id)
                        ),
                        :aes
                    ),
                    :buy,
                    JSON_ARRAY(
                        JSON_OBJECT('event', 0, 'date', NOW(), 'coment', 'Compra creada y apartada. En espera de análisis')
                    ),
                    JSON_OBJECT('name', :npedding)
                );
            "
            );
            $uuid = Uuid::uuid4();

            $_warehouse = json_encode($warehouse[0]);
            $query->execute([":aes" => $_ENV["AES_KEY"], ":client_id" => $token->data[0], ":uuid" => $uuid->toString(), ":buy" => $_warehouse, ":npedding" => $namePedding]);
            $this->db->commit(); // Commit the transaction here

            (new ApiManager())->log_inventory(
                [
                    "dvc" => "App Pines",
                    "user" => $token->data[0],
                    "type" => "log.inv.buy",
                    "id" => "Retained ($uuid)",
                    "message" => "Salida De Inventario",
                    ...$warehouse[3]
                ]
            );
            Flight::halt(message: json_encode([
                "response" => ["id" => $uuid, "error" => $get_errors]
            ]));
        } catch (PDOException $e) {
            $this->db->rollBack();
            Flight::halt(503, json_encode([
                "response" => "Error al crear el pedido: " . $e->getMessage()
            ]));
        }
    }

    private function get_cart_items($uuids)
    {
        function format_filter($array): array
        {

            foreach ($array as &$item) {
                $item['quantity'] = (new ItemUtils())->mergePackets(json_decode($item['quantity'], true));
                $item['price'] = round($item['price'], 3);

                $total = (new ItemUtils())->item_quantity($item['quantity']);
                if ($total <= 0) {
                    $item = null;
                }

                if ($item['discount'] != null) {
                    $item['discount'] = json_decode($item['discount'], true);
                    if (!empty($item['discount'][0])) {
                        $item['discount'] = $item['discount'][1] ? $item['price'] - ($item['price'] * ($item['discount'][0] / 100)) : $item['price'] - $item['discount'][0];
                        $item['discount'] = round($item['discount'], 3);
                    } else {
                        unset($item['discount']);
                    }
                } else {
                    unset($item['discount']);
                }
            }
            return array_values(array_filter($array));
        }
        $token = Flight::get('token');
        try {
            $placeholders = implode(',', array_map(function ($uuid, $index) {
                return ":uuid{$index}";
            }, $uuids, array_keys($uuids)));

            $query = $this->db->prepare("
            SELECT 
                `items`.`uuid`,
                `items`.`id`, 
                quantity, 
                JSON_UNQUOTE(JSON_EXTRACT(`items`.`photos`, '$[0].name')) AS `photo`,
                JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(`items`.`prices`, :aes) AS CHAR), 
                    CONCAT('$[', a.price_index, ']')
                )) AS `price`,
                JSON_EXTRACT(d.`advanced`, CONCAT('$.discount.\"', :client_id, '\"')) AS discount
            FROM 
                `items`
            JOIN 
                `departments` AS d ON d.`uuid` = JSON_VALUE(`items`.`info`, '$.departament')
            CROSS JOIN 
                (SELECT JSON_VALUE(acctAdvanced, '$.price') AS price_index FROM `users` WHERE `id` = :client_id) AS a
            WHERE 
                JSON_VALUE(`items`.`info`, '$.departament') IS NOT NULL AND TRIM(JSON_VALUE(`items`.`info`, '$.departament')) <> ''

                AND JSON_EXTRACT(d.`advanced`, '$.hide') = false
                AND JSON_SEARCH(JSON_VALUE(d.`advanced`, '$.blackList'), 'one', :client_id) IS NULL
                
                AND JSON_EXTRACT(CAST(AES_DECRYPT(`items`.`advanced`, :aes) AS CHAR), '$.hide') = false
                AND JSON_SEARCH(JSON_VALUE(CAST(AES_DECRYPT(`items`.`advanced`, :aes) AS CHAR), '$.views'), 'one', :client_id) IS NULL
                
                AND JSON_LENGTH(`items`.`photos`) > 0 
                AND JSON_LENGTH(`items`.`quantity`) > 0 
                AND JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(`items`.`prices`, :aes) AS CHAR), 
                    CONCAT('$[', a.price_index, ']')
                )) IS NOT NULL AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(`items`.`prices`, :aes) AS CHAR), 
                    CONCAT('$[', a.price_index, ']')
                ))) <> ''

                AND `items`.`uuid` IN ($placeholders);
            ");
            $params = array_merge([":aes" => $_ENV['AES_KEY'], ":client_id" => $token->data[0]], array_combine(explode(',', $placeholders), $uuids));

            $query->execute($params);
            $cart = $query->fetchAll(PDO::FETCH_ASSOC);
            $tempStdClass = format_filter($cart);

            if (count($cart) > 0 and !empty((array)$tempStdClass)) {
                return $tempStdClass;
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

    private function seacrh_warehouses_and_sub($token, $cartClient)
    {
        $cart = $cartClient;
        $buyList = [];
        $whiteList = [];
        $playLoadHistory = [
            "old" => [],
            "new" => [],
            "total" => 0
        ];
        foreach ($cart as $uuid => &$uuidArray) {
            try {
                $query = $this->db->prepare("
                    SELECT 
                        id,
                        quantity,
                        JSON_UNQUOTE(JSON_EXTRACT(
                            CAST(AES_DECRYPT(prices, :aes) AS CHAR),
                            CONCAT('$[', a.price_index, ']')
                        )) AS `price`,
                        IFNULL(
                            JSON_EXTRACT(d.`advanced`, CONCAT('$.discount.\"', :client_id, '\"')),
                            JSON_ARRAY(\"\", false)
                        ) AS discount
                    FROM 
                        `items`
                        JOIN departments d ON JSON_EXTRACT(`items`.info,'$.departament') = d.uuid
                        CROSS JOIN 
                            (SELECT JSON_VALUE(acctAdvanced, '$.price') AS price_index FROM `users` WHERE `id` = :client_id) AS a
                    WHERE`items`.uuid = :uuid
                ");
                $query->execute([":aes" => $_ENV["AES_KEY"], ":client_id" => $token->data[0], ":uuid" => $uuid]);

                $item = $query->fetch();

                if ($item) {
                    $itemQuantity_Server = array_reverse(json_decode($item["quantity"], true), true);
                    foreach ($itemQuantity_Server as $warehouseID => &$warehouse) {
                        if (count($warehouse['Packets']) == 0 || array_sum($warehouse['Packets']) === 0) {
                            continue;
                        }
                        $verified = false;
                        $temp = [];
                        foreach ($warehouse['Packets'] as $packet => &$q) {
                            if (array_key_exists($packet, $uuidArray['quantity']) && $q != 0 && $uuidArray['quantity'][$packet] != 0) {
                                $intent = min($q, $uuidArray['quantity'][$packet]);
                                $q -= $intent;
                                $uuidArray['quantity'][$packet] -= $intent;
                                $temp[$packet] = $intent;
                                $playLoadHistory["total"] += $intent * $packet;
                                $verified = true;
                            }
                        }
                        if ($verified) {
                            $currentId = count($buyList) + 1;
                            
                            $buyList[$currentId] = [
                                "code" => $uuidArray["id"],
                                "depo" => "$warehouseID",
                                "packs" => $temp,
                                "price" => $item["price"],
                                "discount" => json_decode($item["discount"])
                            ];

                            $playLoadHistory["old"][] = [
                                "code" => $uuidArray["id"],
                                "depo" => "$warehouseID",
                                "packs" => json_decode($item["quantity"], true)[$warehouseID]["Packets"]
                            ];

                            $playLoadHistory["new"][] = [
                                "code" => $uuidArray["id"],
                                "depo" => "$warehouseID",
                                "packs" => $itemQuantity_Server[$warehouseID]["Packets"]
                            ];

                        }
                    }
                    array_push($whiteList, $uuid);
                    $query = $this->db->prepare("
                        UPDATE `items`
                        SET `quantity` = :new_quantity
                        WHERE `uuid` = :uuid
                    ");
                    $query->execute([
                        ":new_quantity" => json_encode($itemQuantity_Server),
                        ":uuid" => $uuid
                    ]);
                }
            } catch (PDOException $e) {
                $this->db->rollBack();
                Flight::halt(503, json_encode([
                    "response" => "Error con el pedido: " . $e->getMessage()
                ]));
            }
        }

        return [$buyList, $whiteList, $cart, $playLoadHistory];
    }

    private function check_if_items_all_pass($cart, $whiteList)
    {

        $msg = [];
        foreach ($cart as $uuid => $uuidArray) {
            if (!in_array($uuid, $whiteList)) {
                $msg[] = $uuidArray["id"] . ": Agotado";
                continue;
            }
            foreach ($uuidArray["quantity"] as $p => $v) {
                if ($v != 0) {
                    $msg[] = $uuidArray["id"] . ": Paquetes escasos";
                    break;
                }
            }
        }
        return $msg;
    }
}
