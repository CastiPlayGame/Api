<?php

use Ramsey\Uuid\Uuid;

class ItemManager
{
    private $db;

    function __construct()
    {

        $this->db = Flight::db();
    }


    private function all_item_filterAndCheck(array $array, int $stock)
    {
        $filteredStock = [500, 2500, 5000, 10000, true];
        foreach ($array as &$item) {

            $total = (new ItemUtils())->item_quantity((new ItemUtils())->mergePackets(json_decode($item['quantity'], true)));
            if (
                $total <= 0 or
                (
                    !is_bool($filteredStock[$stock])
                    and $stock != count($filteredStock)
                    and $total > $filteredStock[$stock]
                )
            ) {
                $item = null;
                continue;
            }

            $item['quantity'] = $total;
            $item['price'] = round($item['price'], 3);

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

    private function get_item_filterAndCheck(array &$array)
    {
        if (empty($array)) {
            return [];
        }

        $array['quantity'] = (new ItemUtils())->mergePackets(json_decode($array['quantity'], true));

        $total = (new ItemUtils())->item_quantity($array['quantity']);
        if ($total <= 0) {
            return [];
        }
        $array['quantity'] = $total;
        $array['photo'] = json_decode($array['photo'], true);
        $array['price'] = round($array['price'], 3);


        if ($array['discount'] != null) {
            $array['discount'] = json_decode($array['discount'], true);
            if (!empty($array['discount'][0])) {
                $array['discountNum'] = $array['discount'][1] ? intval($array['discount'][0]) . "%" : $array['discount'][0] . "$";
                $array['discount'] = $array['discount'][1] ? $array['price'] - ($array['price'] * ($array['discount'][0] / 100)) : $array['price'] - $array['discount'][0];
                $array['discount'] = round($array['discount'], 3);
            } else {
                unset($array['discount']);
            }
        } else {
            unset($array['discount']);
        }


        return $array;
    }

    private function get_item_quantity_filterAndCheck(array $array)
    {
        if (empty($array)) {
            return [];
        }

        $array['quantity'] = (new ItemUtils())->mergePackets(json_decode($array['quantity'], true));
        $total = (new ItemUtils())->item_quantity($array['quantity']);
        if ($total <= 0) {
            return [];
        }

        return $array;
    }

    private function get_item_quantity_admin_filterAndCheck($array)
    {
        $array['quantity'] = json_decode($array['quantity'], true);
        if (empty($array['quantity'])) {
            return [];
        }
        $total = (new ItemUtils())->item_quantity_for_all($array['quantity']);
        if ($total <= 0) {
            return [];
        }

        return $array['quantity'][1];
    }

    public function all_item()
    {

        $token = Flight::get('token');

        $filterData = Flight::request()->data->filter;

        if (is_array($filterData)) {
            $filter = $filterData;
        } elseif (is_string($filterData)) {
            $filter = json_decode($filterData, true);
        } else {
            Flight::halt(400, json_encode([
                "response" => "Ups hubo un problema con el formBody"
            ]));
        }
        try {
            // Imprime en el log php
            error_log(json_encode($filter['filter']));
            $query = $this->db->prepare("
            SELECT 
                `items`.`uuid`, 
                `items`.`id`, 
                quantity, 
                JSON_VALUE(`items`.`info`, '$.brand') AS brand, 
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
                AND `items`.`id` LIKE :search
                AND CAST(
                    JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(`items`.`prices`, :aes) AS CHAR), 
                    CONCAT('$[', a.price_index, ']'))) AS DECIMAL(10,2)
                ) BETWEEN :priceFrom AND :priceTo
                AND
                CASE 
                    WHEN :depas IS NOT NULL AND TRIM(:depas) <> '' THEN 
                        JSON_VALUE(`items`.`info`, '$.departament') IN (:depas)
                    ELSE TRUE
                END
            ORDER BY 
                `items`.`id` ASC;
            ");

            $query2 = $this->db->prepare("
            SELECT uuid,name FROM `departments` WHERE JSON_EXTRACT(`advanced`, '$.hide') = false
                AND JSON_SEARCH(JSON_VALUE(`advanced`, '$.blackList'), 'one', :client_id) IS NULL
            ");


            $params = [
                ":aes" => $_ENV['AES_KEY'],
                ":client_id" => $token->data[0],
                ":search" => "%" . $filter['search'] . "%",
                ":priceFrom" => $filter['filter']['price'][0],
                ":priceTo" => $filter['filter']['price'][1],
                ":depas" => implode("','", $filter['filter']['depa'])
            ];
            

            $query->execute($params);

            $items = $query->fetchAll(PDO::FETCH_ASSOC);


            error_log("Items found: " . count($items));

            $query2->execute([":client_id" => $token->data[0]]);
            $depa = $query2->fetchAll(PDO::FETCH_ASSOC);

            $tempStdClass = $this->all_item_filterAndCheck($items, $filter['filter']['stock']);


            if (count($items) > 0 and count($depa) > 0 and !empty((array)$tempStdClass)) {
                Flight::halt(message: json_encode([
                    "response" => [
                        "products" => $tempStdClass,
                        "departaments" => $depa
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
        $token = Flight::get('token');
        try {
            $query = $this->db->prepare("
            SELECT 
                quantity,
                d.name AS depa,
                JSON_VALUE(i.info, '$.brand') AS brand,
                JSON_VALUE(i.info, '$.desc') AS descp,
                JSON_VALUE(i.info, '$.model') AS model,
                JSON_UNQUOTE(JSON_EXTRACT(i.`photos`, '$[*].name')) AS `photo`,
                JSON_UNQUOTE(JSON_EXTRACT(
                    CAST(AES_DECRYPT(i.prices, :aes) AS CHAR),
                    CONCAT('$[', a.price_index, ']')
                )) AS `price`,
                JSON_EXTRACT(d.`advanced`, CONCAT('$.discount.\"', :client_id, '\"')) AS discount
            FROM 
                `items` i
                JOIN departments d ON JSON_EXTRACT(i.info,'$.departament') = d.uuid
                CROSS JOIN 
                    (SELECT JSON_VALUE(acctAdvanced, '$.price') AS price_index FROM `users` WHERE `id` = :client_id) AS a
            WHERE 
                i.`uuid` = :uuid
                AND JSON_VALUE(i.info, '$.departament') IS NOT NULL AND TRIM(JSON_VALUE(i.info, '$.departament')) <> ''
                AND JSON_EXTRACT(d.`advanced`, '$.hide') = false
                AND JSON_SEARCH(JSON_VALUE(d.`advanced`, '$.blackList'), 'one', :client_id) IS NULL
                AND JSON_EXTRACT(CAST(AES_DECRYPT(i.`advanced`, :aes) AS CHAR), '$.hide') = false
                AND JSON_SEARCH(JSON_VALUE(CAST(AES_DECRYPT(i.`advanced`, :aes) AS CHAR), '$.views'), 'one', :client_id) IS NULL
                AND JSON_LENGTH(i.`photos`) > 0 
                AND JSON_LENGTH(i.`quantity`) > 0 
                AND JSON_UNQUOTE(JSON_EXTRACT(
                    CAST(AES_DECRYPT(i.prices, :aes) AS CHAR),
                    CONCAT('$[', a.price_index, ']')
                )) IS NOT NULL AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(
                    CAST(AES_DECRYPT(i.prices, :aes) AS CHAR),
                    CONCAT('$[', a.price_index, ']')
                ))) <> '';
        ");

            $query->execute([":aes" => $_ENV['AES_KEY'], ":client_id" => $token->data[0], ":uuid" => $uuid]);
            $item = $query->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                Flight::halt(404, json_encode([
                    "response" => "Item Agotado"
                ]));
            }
            $tempStdClass = $this->get_item_filterAndCheck($item);

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

    public function get_item_quantity($uuid)
    {
        $token = Flight::get('token');
        try {
            $query = $this->db->prepare("
            SELECT 
                quantity
            FROM 
                `items` i
                JOIN departments d ON JSON_EXTRACT(i.info,'$.departament') = d.uuid
                CROSS JOIN 
                    (SELECT JSON_VALUE(acctAdvanced, '$.price') AS price_index FROM `users` WHERE `id` = :client_id) AS a
            WHERE 
                i.`uuid` = :uuid
                AND JSON_VALUE(i.info, '$.departament') IS NOT NULL AND TRIM(JSON_VALUE(i.info, '$.departament')) <> ''
                AND JSON_EXTRACT(d.`advanced`, '$.hide') = false
                AND JSON_SEARCH(JSON_VALUE(d.`advanced`, '$.blackList'), 'one', :client_id) IS NULL
                AND JSON_EXTRACT(CAST(AES_DECRYPT(i.`advanced`, :aes) AS CHAR), '$.hide') = false
                AND JSON_SEARCH(JSON_VALUE(CAST(AES_DECRYPT(i.`advanced`, :aes) AS CHAR), '$.views'), 'one', :client_id) IS NULL
                AND JSON_LENGTH(i.`photos`) > 0 
                AND JSON_LENGTH(i.`quantity`) > 0 
                AND JSON_UNQUOTE(JSON_EXTRACT(
                    CAST(AES_DECRYPT(i.prices, :aes) AS CHAR),
                    CONCAT('$[', a.price_index, ']')
                )) IS NOT NULL AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(
                    CAST(AES_DECRYPT(i.prices, :aes) AS CHAR),
                    CONCAT('$[', a.price_index, ']')
                ))) <> '';
        ");

            $query->execute([":aes" => $_ENV['AES_KEY'], ":client_id" => $token->data[0], ":uuid" => $uuid]);
            $item = $query->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                Flight::halt(404, json_encode([
                    "response" => "Item Agotado"
                ]));
            }
            $tempStdClass = $this->get_item_quantity_filterAndCheck($item);
            if (!empty((array)$tempStdClass)) {
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

    public function get_item_quantity_admin($id)
    {
        try {
            $query = $this->db->prepare("
            SELECT 
                quantity
            FROM 
                `items` i
            WHERE 
                i.`id` = :id;
        ");

            $query->execute([":id" => $id]);
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
    public function get_img($id, $img)
    {
        $imagePath = 'data/pic/' . $id . '/' . $img;

        $imageData = file_get_contents($imagePath);
        if ($imageData !== false) {
            header('Content-Type: image/png');
            Flight::map('send', function () use ($imageData) {
                echo $imageData;
            });
            Flight::send();
        } else {
            Flight::halt(404, 'Image not found');
        }
    }

    public function get_by_code_img($id)
    {
        try {
            $query = $this->db->prepare("
            SELECT 
                i.uuid,
                i.photos
            FROM 
                `items` i
            WHERE 
                i.`id` = :id;
        ");

            $query->execute([":id" => $id]);
            $item = $query->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                Flight::halt(404, json_encode([
                    "response" => "Item No Existe"
                ]));
            }

            $photo = json_decode($item["photos"], true);
            if (empty($photo)) {
                Flight::halt(404, json_encode([
                    "response" => "No Photos Found"
                ]));
            }
            $photo = $photo[0]["name"];

            $imagePath = 'data/pic/' . $item["uuid"] . '/' . $photo;

            $imageData = file_get_contents($imagePath);
            if ($imageData !== false) {
                header('Content-Type: image/png');
                Flight::map('send', function () use ($imageData) {
                    echo $imageData;
                });
                Flight::send();
            } else {
                Flight::halt(404, 'Image not found');
            }
        } catch (PDOException $e) {
            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

    public function upload_img()
    {
        $uuid = Flight::request()->data['uuid'];
        $file = Flight::request()->files['file'];

        // Check file size and type
        if ($file['size'] > 10 * 1024 * 1024) {
            Flight::halt(400, 'File is too large');
        }
        $allowed_types = ['image/jpeg', 'image/png'];
        if (!in_array($file['type'], $allowed_types)) {
            Flight::halt(400, 'Invalid file type');
        }

        // Move the file to a permanent location
        $target_dir = 'data/pic/' . $uuid . '/';
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $newName = 'PIC-' . Uuid::uuid4()->toString() . '.webp';
        $target_file = $target_dir . $newName;

        // Convert the image to WebP format
        switch ($file['type']) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($file['tmp_name']);
                imagewebp($image, $target_file, 85);
                break;
            case 'image/png':
                $image = imagecreatefrompng($file['tmp_name']);
                imagepalettetotruecolor($image);
                imagewebp($image, $target_file, 85);
                break;
        }

        $stmt = $this->db->prepare("SELECT id, photos FROM items WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $result = $stmt->fetch();
        if (!$result) {
            Flight::halt(404, 'Item not found');
        }
        $photos = json_decode($result['photos'], true);
        if (!$photos) {
            $photos = [];
        }
        $currentDateTime = new DateTime();
        $formattedDateTime = $currentDateTime->format("Y/m/d H:i:s");
        $photos[] = [
            'name' => $newName,
            'size' => filesize($target_file),
            'type' => mime_content_type($target_file),
            'date' => $formattedDateTime
        ];
        $photos_json = json_encode($photos);
        $stmt = $this->db->prepare("UPDATE items SET photos = ? WHERE uuid = ?");
        $stmt->execute([$photos_json, $uuid]);

        Flight::halt(
            message: json_encode([
                'success' => true,
                'file' => $newName
            ])
        );
    }

    public function del_img()
    {
        $uuid = Flight::request()->data['uuid'];
        $pic = Flight::request()->data['img'];

        $stmt = $this->db->prepare("SELECT id, photos FROM items WHERE uuid = :uuid");
        $stmt->bindParam(':uuid', $uuid);
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result) {
            Flight::halt(404, json_encode(['error' => '!Ups. Item No Existe']));
        }

        $photos = json_decode($result['photos'], true);
        unlink('data/pic/' . $uuid . '/' . $photos[$pic]['name']);
        unset($photos[$pic]);

        $_photos = json_encode($photos);
        $stmt = $this->db->prepare("UPDATE items SET photos = :photos WHERE uuid = :uuid");
        $stmt->bindParam(':photos', $_photos);
        $stmt->bindParam(':uuid', $uuid);
        $stmt->execute();

        Flight::halt(
            message: json_encode([
                'success' => true
            ])
        );
    }

    public function quantity_packets($id)
    {
        try {
            // Función para validar UUID
            $isUuid = function($uuid) {
                return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid);
            };
    
            // Determinar la columna a usar en el WHERE
            $whereColumn = $isUuid($id) ? 'uuid' : 'id';
    
            $query = $this->db->prepare("
                SELECT 
                    quantity,
                    id
                FROM 
                    `items`
                WHERE 
                    " . $whereColumn . " = :id
            ");

            $query->execute([":id" => $id]);
            $item = $query->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                Flight::halt(404, json_encode([
                    "response" => "Item No Existe"
                ]));
            }               
            $quantity = json_decode($item["quantity"],true);

            $depo = Flight::request()->data->deposit ?? 1;
            $pack = Flight::request()->data->packet_id;
            $num = intval(Flight::request()->data->quantity) ?? null;


            if (!array_key_exists($depo, $quantity)) {
                $quantity[$depo] = array("Packets" => array(), "Pcs" => 0);
            }
            $old = $quantity;

            $data = [
                "type" => "log.inv.item",
                "id" => $item["id"],
                "message" => "",
                "message_send" => "",
                "old" => "",
                "new" => "",
                "depo" => $depo,
                "total" => ""
            ];

            switch (Flight::request()->data->operation_type) {
                case 'create_packet':
                    if (array_key_exists($pack, $quantity[$depo]['Packets'])) {
                        Flight::halt(409, message: json_encode([
                            "response" => "Paquete Ya Existe"
                        ]));
                    }
                    $quantity[$depo]['Packets'][$pack] = 0;
                    $data["message"] = "Se Creo un Paquete Nuevo";
                    $data["message_send"] = "Se Creo un Nuevo Paquete de [$pack]";
                    $data["total"] = 0;

                    break;
                case 'delete_packet':
                    if (!array_key_exists($pack, $quantity[$depo]['Packets'])) {
                        Flight::halt(409, message: json_encode([
                            "response" => "Este Paquete No Existe"
                        ]));
                    }
                    
                    $data["message"] = "Se Elimino un Paquete";
                    $data["message_send"] = "Se Elimino un Paquete de [$pack]";
                    $data["total"] = $pack * $quantity[$depo]['Packets'][$pack];

                    unset($quantity[$depo]['Packets'][$pack]);
                    break;
                case 'add_packs':
                    if (!array_key_exists($pack, $quantity[$depo]['Packets'])) {
                        Flight::halt(409, message: json_encode([
                            "response" => "Este Paquete No Existe ".json_encode($quantity)
                        ]));
                    }
                    $quantity[$depo]['Packets'][$pack] = intval($quantity[$depo]['Packets'][$pack]) + $num;
                    $data["message"] = "Se Agregaron Paquetes";
                    $data["message_send"] = "Se Agregaron Paquetes de [$pack]. Ahora son: " . $quantity[$depo]['Packets'][$pack];
                    $data["total"] = ($pack * $num);

                    break;
                case 'subtract_packs':
                    if (!array_key_exists($pack, $quantity[$depo]['Packets'])) {
                        Flight::halt(409, message: json_encode([
                            "response" => "Este Paquete No Existe"
                        ]));
                    }

                    $t = 0;
                    $remainingPackets = $quantity[$depo]['Packets'][$pack] - $num;
                    $quantity[$depo]['Packets'][$pack] = max(0, $remainingPackets);
                    $t = ($remainingPackets >= 0) ? $pack * $num : 0;

                    $data["message"] = "Se Restaron Paquetes";
                    $data["message_send"] = "Se Restaron Paquetes de [$pack]. Ahora son: " . $quantity[$depo]['Packets'][$pack];
                    $data["total"] = $t;
                    break;
                case 'establish_packs':
                    if (!array_key_exists($pack, $quantity[$depo]['Packets'])) {
                        Flight::halt(409, message: json_encode([
                            "response" => "Este Paquete No Existe"
                        ]));
                    }

                    $quantity[$depo]['Packets'][$pack] = $num;

                    $data["message"] = "Se Establecio un Paquete";
                    $data["message_send"] = "Se Establecio un Paquete de [$pack]. Ahora son: " . $quantity[$depo]['Packets'][$pack];
                    $data["total"] = ($pack * $num);
                    break;
                case 'add_pcs':
                    $quantity[$depo]['Pcs'] = intval($quantity[$depo]['Pcs']) + $num;
                    $data["message"] = "Se Agregaron Unidades";
                    $data["message_send"] = "Se Agregaron Unidades. Ahora son: " . $quantity[$depo]['Pcs'];
                    $data["total"] = $num;
                    break;
                case 'subtract_pcs':
                    $quantity[$depo]['Pcs'] = intval($quantity[$depo]['Pcs']) - $num;
                    $data["message"] = "Se Restaron Unidades";
                    $data["message_send"] = "Se Restaron Unidades. Ahora son: " . $quantity[$depo]['Pcs'];
                    $data["total"] = $num;
                    break;
                case 'establish_pcs':
                    $quantity[$depo]['Pcs'] = $num;
                    $data["message"] = "Se Establecieron Unidades";
                    $data["message_send"] = "Se Establecieron Unidades. Ahora son: " . $quantity[$depo]['Pcs'];
                    $data["total"] = $num;
                    break;  
                case 'add_samples':
                    if (!isset($quantity[$depo]['Samples'])) {
                        $quantity[$depo]['Samples'] = 0;
                    }
                    $quantity[$depo]['Samples'] = intval($quantity[$depo]['Samples']) + $num;
                    $data["message"] = "Se Añadieron Muestras";
                    $data["message_send"] = "Se Añadieron Muestras. Ahora son: " . $quantity[$depo]['Samples'];
                    $data["total"] = $num;
                    break;
                case 'subtract_samples':
                    if (!isset($quantity[$depo]['Samples'])) {
                        $quantity[$depo]['Samples'] = 0;
                    }
                    $quantity[$depo]['Samples'] = intval($quantity[$depo]['Samples']) - $num;
                    $data["message"] = "Se Eliminaron Muestras";
                    $data["message_send"] = "Se Eliminaron Muestras. Ahora son: " . $quantity[$depo]['Samples'];
                    $data["total"] = $num;
                    break;
                case 'establish_samples':
                    $quantity[$depo]['Samples'] = $num;
                    $data["message"] = "Se Establecio un valor de Muestras";
                    $data["message_send"] = "Se Cambio las Muestras. Ahora son: " . $quantity[$depo]['Samples'];
                    $data["total"] = $num;
                    break;
            }

            $update_query = $this->db->prepare("UPDATE items SET quantity=:quantity WHERE " . $whereColumn . " =:id");
            $new_quantity = json_encode($quantity);
            $update_query->execute([":id" => $id, ":quantity" => $new_quantity]);

            $data["old"] = $old[$depo];
            $data["new"] = $quantity[$depo];

            // Calcular suma total old y new
            $sum_old = intval($data["old"]["Pcs"] ?? 0);
            foreach (($data["old"]["Packets"] ?? []) as $k => $v) {
                $sum_old += intval($k) * intval($v);
            }
            $sum_new = intval($data["new"]["Pcs"] ?? 0);
            foreach (($data["new"]["Packets"] ?? []) as $k => $v) {
                $sum_new += intval($k) * intval($v);
            }

            $diff = $sum_new - $sum_old;

            if ($diff !== 0) {
                (new BatchJobController())->create_or_update_job($item["id"], $diff);
            }

            (new ApiManager())->log_inventory($data);
            
            $msg = $data["message_send"];
            unset($data["message_send"]);


            Flight::halt(message:json_encode([
                "response" => $msg,
            ]));

        } catch (PDOException $e) {
            error_log($e->getMessage());

            Flight::halt(503, json_encode([
                "response" => $e->getMessage()
            ]));
        }
    }

    // Pines SYS
}
