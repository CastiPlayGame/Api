<?php
class ItemUtils
{
    private $db;
    function __construct()
    {
        $this->db = Flight::db();
    }

    public function mergePackets($quantity,$pcs = false)
    {
        $result = [];
        foreach ($quantity as $depo) {
            if($pcs){
                $result["pcs"] = $depo["Pcs"];
            }
            foreach ($depo['Packets'] as $pack => $nr) {
                if (isset($result[$pack])) {
                    $result[$pack] += $nr;
                } else {
                    $result[$pack] = $nr;
                }
            }
        }
        return array_filter($result, function ($value) {
            return $value > 0;
        });
    }

    public function item_quantity($quantity)
    {
        $total = 0;
        if(array_key_exists("pcs", $quantity)){
            $total = $quantity["pcs"];
            unset($quantity["pcs"]);
        }
        foreach ($quantity as $pack => $nr) {
            $total += $pack * $nr;
        }
        return $total;
    }

    public function item_quantity_for_all($quantity)
    {
        $i = 0; 
        foreach ($quantity as $nrDepo => $depo) {
            $total = $depo["Pcs"];
            foreach ($depo["Packets"] as $pack => $nr) {
                $total += $pack * $nr;
            }
            $i += $total;
        }
        return $i;
    }



    public function item_quantity_for_warehouse($quantity)
    {
        $result = [];
        foreach ($quantity as $nrDepo => $depo) {
            $total = $depo["Pcs"];
            foreach ($depo["Packets"] as $pack => $nr) {
                $total += $pack * $nr;
            }
            $result[$nrDepo] = $total; 
        }
        return $result;
    }

    public function item_price($quantity)
    {
        $total = 0;
        $discount = 0;
        foreach ($quantity as $item) {
            $price = $item["price"];
            $temp = 0;
            foreach ($item["packs"] as $pack => $nr) {
                $temp += $pack * $nr;
            }

            if (!empty($item["discount"][0])) {
                if ($item["discount"][1]) {
                    $price -= $item['price'] * ($item["discount"][0] / 100);
                } else {
                    $price -= $item["discount"][0];
                }
            }
            $discount += $temp * $price;
            $total += $temp * $item['price'];


            $total += $temp * $item["price"];
        }
        return [round($total, 2), round($discount, 2)];
    }

    public function calculatePrices($row)
    {
        $total = 0;
        $costBase = 0; // Costo total sin ningún descuento
        $costWithProductDiscounts = 0; // Costo total con descuentos de productos aplicados
        $discountRow = json_decode($row['discount']);
        $buys = [];
    
        foreach (json_decode($row['buy'], true) as $x => $y) {
            $price = $y['price'];
            $discount = "0%";
    
            // Apply product-specific discounts
            if (!empty($y["discount"][0])) {
                $discount = $y["discount"][0] ? number_format($y["discount"][0], 0, ',', '.') . "%" : "$";
                $price -= $y["discount"][1] ? $price * ($y["discount"][0] / 100) : $y["discount"][0];
            }
    
            // Calculate total and cost
            $itemTotal = 0;
            $itemCostBase = 0; // Costo del producto sin descuentos
            $itemCostWithDiscount = 0; // Costo del producto con descuento aplicado
            $packss = [];
    
            foreach ($y['packs'] as $quantity => $packs) {
                $itemTotal += $quantity * $packs;
                $itemCostBase += $quantity * $packs * $y['price']; // Costo sin descuentos
                $itemCostWithDiscount += $quantity * $packs * $price; // Costo con descuentos de productos
                $packss[] = 'Pack [' . $quantity . ']: ' . $packs;
            }
    
            // Add item to buys array
            $query = $this->db->prepare("           
                SELECT 
                    COALESCE(JSON_VALUE(i.info, '$.desc'), '') AS descp,
                    COALESCE(d.name, '') AS depa
                FROM 
                    `items` i
                    LEFT JOIN departments d ON JSON_VALUE(i.info, '$.departament') = d.uuid
                WHERE 
                    i.`id` = :code");
    
            $query->execute([":code" => $y['code']]);
            $item = $query->fetch(PDO::FETCH_ASSOC);
    
            $buys[] = [
                'code' => $y['code'],
                'depo' => $y['depo'],
                'unitBase' => $y['price'],
                'unitDisc' => $price,
                'disc' => $discount,
                'total' => $itemTotal,
                'descp' => $item['descp'],
                'depa' => $item['depa'],
                'cost' => $itemCostWithDiscount,
                'costBase' => $itemCostBase,
                'costDisc' => $itemCostBase - $itemCostWithDiscount,
                'packs' => $packss
            ];
    
            $total += $itemTotal;
            $costBase += $itemCostBase;
            $costWithProductDiscounts += $itemCostWithDiscount;
        }
    
        // Apply general discount at the end
        $generalDiscount = 0;
        if (!empty($discountRow[0])) {
            $generalDiscount = $discountRow[1] ? ($costWithProductDiscounts * ($discountRow[0] / 100)) : $discountRow[0];
        }
        $costWithGeneralDiscount = $costWithProductDiscounts - $generalDiscount;
    
        $sign = empty($discountRow[0]) ? "" : ($discountRow[1] ? "%" : "$");
    
        $result = [
            'total' => $total,
            'costBase' => $costBase, // Costo total sin descuentos
            'costWithProductDiscounts' => $costWithProductDiscounts, // Costo con descuentos de productos
            'costWithGeneralDiscount' => $costWithGeneralDiscount, // Costo con descuento general aplicado
            'generalDiscount' => $generalDiscount, // Valor del descuento general
            'discpercent' => [$discountRow[0], $sign], // Descuento general y su tipo
            'buys' => $buys
        ];
    
        return $result;
    }
    
}
