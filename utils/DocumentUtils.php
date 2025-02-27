<?php

use PhpOffice\PhpSpreadsheet\Calculation\TextData\Search;

class DocumentComplements
{
    public function adjustTitles($arr)
    {
        $nuevo_arr = [];
        foreach ($arr as $elem) {
            if (strpos($elem, '*') !== false) {
                list($palabra, $repeticiones) = explode('*', $elem, 2);
                $conIndice = substr($repeticiones, -1) === '@';
                $repeticiones = (int) rtrim($repeticiones, '@');

                for ($i = 1; $i <= $repeticiones; $i++) {
                    $nuevo_arr[] = $conIndice ? "$palabra $i" : $palabra;
                }
            } else {
                $nuevo_arr[] = $elem;
            }
        }
        return $nuevo_arr;
    }

    public function adjustColumns($data, $activeWorksheet)
    {
        $columnCount = count($data);
        foreach (range(1, $columnCount) as $columnIndex) {
            $activeWorksheet->getColumnDimensionByColumn($columnIndex)->setAutoSize(true);
        }
    }
}



class ExcelControllerData
{
    private $itemUtils;
    private $document;

    public function __construct()
    {
        $this->itemUtils = new ItemUtils();
        $this->document = new DocumentController();
    }

    public function INV_001($items)
    {
        foreach ($items as &$item) {
            $item["amount"] = $this->itemUtils->item_quantity_for_warehouse(json_decode($item['quantity'], true));
            $total = $this->itemUtils->item_quantity($this->itemUtils->mergePackets(json_decode($item['quantity'], true), true));
            $item["amount"] = array_values(array_replace(array_fill(1, 4, ""), $item["amount"]));

            $item["prices"] = json_decode($item["prices"], true);
            $item["prices"] = array_values(array_replace(array_fill(0, 4, ""), $item["prices"]));

            $item = [
                $item["id"],
                $item["descp"],
                $item["brand"],
                $item["model"],
                $item["depa"],
                $item["hide"],
                $item["numview"],
                ...$item["amount"],
                $total,
                ...$item["prices"]
            ];
        }
        return $items;
    }
    public function FNZ_001($code, $sales)
    {
        $newList = [];
        foreach ($sales as &$item) {
            $item["buy"] =  json_decode($item["buy"], true);

            foreach ($item["buy"] as $pd) {
                if ($pd["code"] == $code) {
                    $newPacks = [];
                    $nr =  0;
                    foreach ($pd["packs"] as $key => $value) {
                        $newPacks[] = "pq[$key]: $value";
                        $nr += $key * $value;
                    }

                    $newList[] = [
                        $item["uuid"],
                        $this->document->typeDoc[$item["type"]],
                        $item["nr"],
                        $item["name"],
                        $pd["code"],
                        implode(", ", $newPacks),
                        $nr,
                        $pd["depo"],
                        $pd["price"],
                        empty($pd["discount"][0]) ? "-" : $pd["discount"][0] . ($pd["discount"][1] ? "%" : "$"),
                        $item["date"],
                        $this->document->typeSales[$item["event"]]
                    ];
                }
            }
        }
        return $newList;
    }
    private function sort_items_by_total($items)
    {
        uasort($items, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });
        return $items;
    }

    private function sort_items_by_price($items)
    {
        uasort($items, function ($a, $b) {
            return $b['total_price'] <=> $a['total_price'];
        });
        return $items;
    }

    public function STS_001($Priorityyear, $items, $sales)
    {
        $totalSales = 0;
        $totalPrices = 0;

        $list_template_items = [];
        $output = [
            "fnz" => [],
            "inv" => []
        ];


        foreach ($items as $item) {
            $total = (new ItemUtils())->item_quantity_for_all(json_decode($item['quantity'], true));

            $list_template_items[$item["id"]] = [
                "code" => $item["id"],
                "name" => $item["name"],
                "amounts" => array_fill(0, 12, 0),
                "prices" => array_fill(0, 12, 0),
                "total" => 0,
                "total_price" => 0,
                "provider" => $item["provider"],
                "provider_price" => $item["provider_price"],
                "id_provider" => $item["id_provider"],
                "stock" => $total
            ];
        }

        foreach ($sales as $sale) {

            
            $buyData = json_decode($sale["buy"], true);
            $eventData = json_decode($sale["event"], true);
            $date = null;

            foreach ($eventData as $a) {
                if ($a["event"] == 0) {
                    $date = $a["date"];
                }
                if ($a["event"] == 3) {
                    $date = null;
                    break;
                }
            }

            if ($date === null) {
                continue;
            }

            $timestamp = strtotime($date);
            $month = date("n", $timestamp) - 1;
            $year = date("Y", $timestamp);

            if ($Priorityyear != $year) {
                continue;
            }

            foreach ($buyData as $c) {
                if (isset($list_template_items[$c["code"]])) {
                    $price = $c['price'];
                    $tempTotal = 0;

                    $cont = $list_template_items[$c["code"]];

                    foreach ($c["packs"] as $k => $v) {
                        if ($v == 0) {
                            continue;
                        }
                        $cont["amounts"][$month] += ($k * $v);
                        $tempTotal += ($k * $v);
                    }
    
                    if (!empty($c["discount"][0])) {
                        if ($c["discount"][1]) {
                            $price -=  $c['price'] * ($c["discount"][0] / 100);
                        } else {
                            $price -= $c["discount"][0];
                        }
                        $cont["prices"][$month] += $tempTotal * $price;
                    } else {
                        $cont["prices"][$month] += $tempTotal * $c['price'];
                    }
                    $list_template_items[$c["code"]] = $cont;
                }
            }
        }

        foreach ($list_template_items as $cli => $val) {
            $list_template_items[$cli]["total"] = array_sum($val["amounts"]);
            $list_template_items[$cli]["total_price"] = array_sum($val["prices"]);

            $totalPrices += $list_template_items[$cli]["total_price"];
            $totalSales += $list_template_items[$cli]["total"];
        }

        foreach ($list_template_items as $cli => $val) {
            $percentage = $totalSales > 0 ? ($val["total"] / $totalSales) * 100 : 0;
            $list_template_items[$cli]["percentage_total"] = round($percentage, 2);

            $percentage = $totalPrices > 0 ? ($val["total_price"] / $totalPrices) * 100 : 0;
            $list_template_items[$cli]["percentage_price"] = round($percentage, 2);
        }


        // Sort the items by total
        $list_template_items = $this->sort_items_by_total($list_template_items);

        // Encabezados de la salida
        $output["inv"][] = ["Proveedor", "Referencia", "Precio", "|", "Codigo", "Departamento", "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre", "Total", "Stock", "Porcentaje"];

        // Agregar los datos de cada cliente a la salida
        foreach ($list_template_items as $cli => $val) {
            $output["inv"][] = [$val["provider"], $val["id_provider"], $val["provider_price"], "",  $val["code"], $val["name"], ...$val["amounts"], $val["total"], $val["stock"], $val["percentage_total"]];
        }

        $list_template_items = $this->sort_items_by_price($list_template_items);

        // Encabezados de la salida
        $output["fnz"][] = ["Proveedor", "Referencia", "Precio", "|", "Codigo", "Departamento", "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre", "Total", "Stock", "Porcentaje"];

        // Agregar los datos de cada cliente a la salida
        foreach ($list_template_items as $cli => $val) {
            $output["fnz"][] = [$val["provider"], $val["id_provider"], $val["provider_price"], "",  $val["code"], $val["name"], ...$val["prices"], $val["total_price"], $val["stock"], $val["percentage_price"]];
        }





        return $output;
    }

    public function STS_002($Priorityyear, $sales)
    {
        $output = [
            "inv" => [],
            "fnz" => []
        ];
        $list_template_items = [];
        $states = array('Nacional', 'Amazonas', 'Anzoátegui', 'Apure', 'Aragua', 'Barinas', 'Bolívar', 'Carabobo', 'Cojedes', 'Delta Amacuro', 'Falcón', 'Guárico', 'Lara', 'Mérida', 'Miranda', 'Monagas', 'Nueva Esparta', 'Portuguesa', 'Sucre', 'Táchira', 'Trujillo', 'Vargas', 'Yaracuy', 'Zulia', 'Distrito Capital');

        // Variable para acumular el total de ventas
        $totalSales = 0;
        $totalPrices = 0;

        foreach ($sales as $sale) {
            $buyData = json_decode($sale["buy"], true);
            $eventData = json_decode($sale["event"], true);
            $infoData = json_decode($sale["info"], true);
            $personalInfo = json_decode($sale["personalInfo"], true);
            $date = null;

            foreach ($eventData as $a) {
                if ($a["event"] == 0) {
                    $date = $a["date"];
                }
                if ($a["event"] == 3) {
                    $date = null;
                    break;
                }
            }

            if ($date === null) {
                continue;
            }

            $timestamp = strtotime($date);
            $month = date("n", $timestamp) - 1;
            $year = date("Y", $timestamp);

            if ($Priorityyear != $year) {
                continue;
            }


            foreach ($buyData as $c) {
                $price = $c['price'];
                $tempTotal = 0;

                if (!key_exists($infoData[0], $list_template_items)) {
                    $list_template_items[$infoData[0]] = [
                        "name" => $infoData[2],
                        "state" => isset($personalInfo["state"]) ? $states[$personalInfo["state"]] : "N/A",
                        "isUp" => isset($personalInfo["isUp"]) ? ($personalInfo["isUp"] ? "Mayor" : "Detal") : "N/A",
                        "notes" => 0,
                        "amounts" => array_fill(0, 12, 0),
                        "prices" => array_fill(0, 12, 0),
                        "total" => 0,
                        "total_price" => 0
                    ];
                }

                foreach ($c["packs"] as $k => $v) {
                    $list_template_items[$infoData[0]]["amounts"][$month] += ($k * $v);
                    $tempTotal += ($k * $v);
                }

                if (!empty($c["discount"][0] )) {
                    if ($c["discount"][1]) {
                        $price -=  floatval($c['price']) * ($c["discount"][0] / 100);
                    } else {
                        $price -= $c["discount"][0];
                    }
                    $list_template_items[$infoData[0]]["prices"][$month] += $tempTotal * $price;
                    continue;
                }
                $list_template_items[$infoData[0]]["prices"][$month] += $tempTotal * $c['price'];
            }
            $list_template_items[$infoData[0]]["notes"] += 1;
        }

        // Calculate the total for each client after processing all buyData entries
        foreach ($list_template_items as $cli => $val) {
            $list_template_items[$cli]["total"] = array_sum($val["amounts"]);
            $list_template_items[$cli]["total_price"] = array_sum($val["prices"]);

            $totalPrices += $list_template_items[$cli]["total_price"];
            $totalSales += $list_template_items[$cli]["total"];
        }

        // Calculate the percentage for each client
        foreach ($list_template_items as $cli => $val) {
            $percentage = $totalSales > 0 ? ($val["total"] / $totalSales) * 100 : 0;
            $list_template_items[$cli]["percentage_total"] = round($percentage, 2);

            $percentage = $totalPrices > 0 ? ($val["total_price"] / $totalPrices) * 100 : 0;
            $list_template_items[$cli]["percentage_price"] = round($percentage, 2);
        }

        // Sort the items by total
        $list_template_items = $this->sort_items_by_total($list_template_items);

        // Encabezados de la salida
        $output["inv"][] = ["Cliente", "Ubicacion", "Tipo", "Notas", "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre", "Total", "Porcentaje"];

        // Agregar los datos de cada cliente a la salida
        foreach ($list_template_items as $cli => $val) {
            $output["inv"][] = [$val["name"], $val["state"], $val["isUp"], $val["notes"], ...$val["amounts"], $val["total"], $val["percentage_total"]];
        }

        $list_template_items = $this->sort_items_by_price($list_template_items);

        // Encabezados de la salida
        $output["fnz"][] = ["Cliente", "Ubicacion", "Tipo", "Notas", "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre", "Total", "Porcentaje"];

        // Agregar los datos de cada cliente a la salida
        foreach ($list_template_items as $cli => $val) {
            $output["fnz"][] = [$val["name"], $val["state"], $val["isUp"], $val["notes"], ...$val["prices"], $val["total_price"], $val["percentage_price"]];
        }


        return $output;
    }

    public function STS_003($Prioritymonth, $items, $sales)
    {
        $index = [];
        $list_template_items = [];
        $output = [];
        $outputtb = [];
        $packets = [];



        // Get Packets
        foreach ($sales as $sale) {
            $buyData = json_decode($sale["buy"], true);
            $eventData = json_decode($sale["event"], true);
            $date = null;

            foreach ($eventData as $a) {
                if ($a["event"] == 0) {
                    $date = $a["date"];
                }
                if ($a["event"] == 3) {
                    $date = null;
                    break;
                }
            }

            if ($date === null) {
                continue;
            }

            $timestamp = strtotime($date);
            $month = date("n", $timestamp);

            if ($Prioritymonth != $month) {
                continue;
            }

            foreach ($buyData as $c) {
                foreach ($c["packs"] as $k => $v) {
                    if (!in_array($k, $packets)) {
                        array_push($packets, $k);
                    }
                }
            }
        }

        rsort($packets);

        foreach ($items as $item) {
            $index[$item['id']] = $item['depa'];
        }

        foreach ($items as $item) {
            if (!isset($list_template_items[$item["depa"]])) {
                $list_template_items[$item["depa"]] = [
                    "name" => $item["name"],
                    "items" => []
                ];
            }

            $list_template_items[$item["depa"]]["items"][$item["id"]] = [
                "packets" => array_fill(0, count($packets), 0),
                "total" => 0
            ];
        }


        foreach ($sales as $sale) {
            $buyData = json_decode($sale["buy"], true);
            $eventData = json_decode($sale["event"], true);
            $date = null;

            foreach ($eventData as $a) {
                if ($a["event"] == 0) {
                    $date = $a["date"];
                }
                if ($a["event"] == 3) {
                    $date = null;
                    break;
                }
            }

            if ($date === null) {
                continue;
            }

            $timestamp = strtotime($date);
            $month = date("n", $timestamp);

            if ($Prioritymonth != $month) {
                continue;
            }

            foreach ($buyData as $c) {
                $uuid = $this->search_code($c["code"], $index);
                if ($uuid !== null) {
                    $cont = $list_template_items[$uuid]["items"][$c["code"]];
                    foreach ($c["packs"] as $k => $v) {
                        if ($v == 0) {
                            continue;
                        }
                        $indexPacket = array_search($k, $packets);

                        $cont["packets"][$indexPacket] += $v;
                        $cont["total"] += ($k * $v);
                    }
                    $list_template_items[$uuid]["items"][$c["code"]] = $cont;
                }
            }
        }


        foreach ($list_template_items as $uuid => $value) {
            $sort = $this->sort_items_by_total($value["items"]);
            $output[] = [$value["name"]];
            $output[] = [];

            $formattedPackets = array_map(function ($num) {
                return "Paquete de $num";
            }, $packets);

            $outputtb[$uuid] = [["Codigo", ...$formattedPackets, "Total"]];

            foreach ($sort as $code => $item) {
                $outputtb[$uuid][] = [$code, ...$item["packets"], $item["total"]];
            }
            $output[] = $uuid;
            $output[] = [];
        }


        return [
            "output" => $output,
            "tables" => $outputtb
        ];
    }
}
