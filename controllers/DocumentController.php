<?php

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\IOFactory;

class DocumentController
{
    private $db;
    public $enabled_formats;
    private $typeIdent;
    private $typeTlf;
    public $typeSales;
    public $typeDoc;

    function __construct()
    {
        $this->db = Flight::db();
        $this->typeIdent = ['V', 'E', 'P', 'J', 'R', 'G'];
        $this->typeTlf = [412, 414, 424, 416, 426, 244, 246, 247, 286];
        $this->enabled_formats = [
            "xlsx" => ["engine" => "Excel", "allowed_types" => ["inv", "fnz", "sts"]],
            "pdf" => ["engine" => "Pdf", "allowed_types" => ["fnz"]]
        ];
        $this->typeSales = [
            "En Almacen",
            "En Transito",
            "Entregada",
            "Anulada",
            "Bloqueado"
        ];
        $this->typeDoc = [
            "Presupuesto",
            "Nota"
        ];
    }

    public function init(string $format, string $type)
    {
        if (!isset($this->enabled_formats[$format])) {
            Flight::halt(405, json_encode([
                "response" => "Format not supported"
            ]));
        }
        if (!in_array($type, $this->enabled_formats[$format]["allowed_types"])) {
            Flight::halt(405, json_encode([
                "response" => "Type not supported"
            ]));
        }

        call_user_func([$this, $this->enabled_formats[$format]["engine"]], $type);
        /*
        try {
            call_user_func([$this, $this->enabled_formats[$format]["engine"]], $type);
        } catch (Exception $e) {
            Flight::halt(500, json_encode([
                "response" => "An error occurred: " . $e->getMessage()
            ]));
        }*/
    }

    private function Excel($type)
    {
        $spreadsheet = new Spreadsheet();
        $activeWorksheet = $spreadsheet->getActiveSheet();

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

        $typefnz = Flight::request()->data->type;
        switch ($type) {
            case 'inv':
                if ($typefnz == "INV_001") {
                    // Iventario Completo Con Todos Los Precios
                    $query = $this->db->prepare("
                    SELECT 
                        `items`.`id`, 
                        quantity,
                        d.`name` as depa, 
                        JSON_VALUE(`items`.`info`, '$.desc') AS descp,
                        JSON_VALUE(`items`.`info`, '$.brand') AS brand,
                        JSON_VALUE(`items`.`info`, '$.model') AS model, 
                        JSON_VALUE(CAST(AES_DECRYPT(`items`.`advanced`, :aes) AS CHAR), '$.hide') AS hide,
                        JSON_VALUE(CAST(AES_DECRYPT(`items`.`advanced`, :aes) AS CHAR), '$.views') numview,                        JSON_TYPE(JSON_VALUE(CAST(AES_DECRYPT(`items`.`advanced`, :aes) AS CHAR), '$.views')) AS tipo,
                        JSON_VALUE(CAST(AES_DECRYPT(`items`.`advanced`, :aes) AS CHAR), '$.views') AS valor,
                        JSON_LENGTH(JSON_VALUE(CAST(AES_DECRYPT(`items`.`advanced`, :aes) AS CHAR), '$.views')) AS longitud,
                        JSON_UNQUOTE(CAST(AES_DECRYPT(`items`.`prices`, :aes) AS CHAR)) AS prices
                    FROM 
                        `items`
                    INNER JOIN 
                        `departments` AS d 
                        ON d.`uuid` = JSON_VALUE(`items`.`info`, '$.departament')
                    WHERE 
                        JSON_VALUE(`items`.`info`, '$.departament') IS NOT NULL AND TRIM(JSON_VALUE(`items`.`info`, '$.departament')) <> ''

                        AND JSON_EXTRACT(d.`advanced`, '$.hide') = false                    
                        AND JSON_EXTRACT(CAST(AES_DECRYPT(`items`.`advanced`, :aes) AS CHAR), '$.hide') = false
                        AND
                        CASE 
                            WHEN :depas IS NOT NULL AND TRIM(:depas) <> '' THEN 
                                JSON_VALUE(`items`.`info`, '$.departament') IN (:depas)
                            ELSE TRUE
                        END
                    ORDER BY 
                        `items`.`id` ASC;
                    ");

                    $query->execute([
                        ":aes" => $_ENV['AES_KEY'],
                        ":depas" =>  implode("','", $filter["departaments"])
                    ]);



                    $items = (new ExcelControllerData())->INV_001($query->fetchAll(PDO::FETCH_ASSOC));

                    $data = [
                        (new DocumentComplements())->adjustTitles(["Codigo", "Descripcion", "Marca", "Modelo", "Departamento", "Oculto", "Lista Negra", "Deposito*4@", "Total", "Precio*4@"]),
                        ...$items
                    ];
                    $activeWorksheet->fromArray($data, NULL, 'A1');

                    $table = new Table('A1:' . chr(64 + count($data[0])) . count($data), 'Table1');
                }
                
                if ($typefnz == "INV_002") {
                    // Inventario Completo Para Clientes Especificos
                }
                if ($typefnz == "INV_003") {
                    // Inventario Stock De Paquetes o Completo
                }


                $tableStyle = new TableStyle();
                $tableStyle->setTheme(TableStyle::TABLE_STYLE_LIGHT1);
                $tableStyle->setShowRowStripes(true);
                $table->setStyle($tableStyle);

                $activeWorksheet->addTable($table);
                (new DocumentComplements())->adjustColumns($data[0], $activeWorksheet);

                break;
            case 'fnz':
                if ($typefnz == "FNZ_001") {
                    // Movimientos de Productos Especificos
                    $query = $this->db->prepare("
                        SELECT 
                            uuid,
                            type,
                            nr,
                            JSON_VALUE(CAST(AES_DECRYPT(`info`, :aes) AS CHAR), '$[2]') as name,
                            buy,
                            JSON_UNQUOTE(JSON_EXTRACT(`event`, CONCAT(\"$[\", JSON_LENGTH(`event`)-1, \"].event\"))) as event,
                            DATE_FORMAT(JSON_UNQUOTE(JSON_EXTRACT(`event`, CONCAT(\"$[\", JSON_LENGTH(`event`)-1, \"].date\"))), '%m/%d/%Y %h:%i %p') AS date 
                        FROM 
                            sales 
                        WHERE 
                            NOT JSON_EXTRACT(`event`,CONCAT(\"$[\",JSON_LENGTH(`event`)-1,\"].event\")) IN (3,4)
                            AND (
                                (:startDate IS NOT NULL AND TRIM(:startDate) <> '' AND 
                                :endDate IS NOT NULL AND TRIM(:endDate) <> '' AND 
                                DATE_FORMAT(JSON_UNQUOTE(JSON_EXTRACT(`event`, CONCAT(\"$[\", JSON_LENGTH(`event`)-1, \"].date\"))), '%Y-%m-%d') BETWEEN :startDate AND :endDate)
                                OR 
                                (:startDate IS NULL OR TRIM(:startDate) = '' OR 
                                :endDate IS NULL OR TRIM(:endDate) = '')
                            )
                    ");

                    $query->execute([
                        ":aes" => $_ENV['AES_KEY'],
                        ":startDate" => $filter["startDate"],
                        ":endDate" => $filter["endDate"],
                    ]);
                    $list = (new ExcelControllerData())->FNZ_001($filter["code"], $query->fetchAll(PDO::FETCH_ASSOC));


                    $data = [
                        ["UUID", "Tipo", "Numero", "Cliente", "Codigo", "Paquetes", "Total", "Deposito", "Precio", "DTO. x Codigo", "Fecha", "Status"],
                        ...$list
                    ];

                    $activeWorksheet->fromArray($data, NULL, 'A1');
                    $table = new Table('A1:' . chr(64 + count($data[0])) . count($data), 'Table1');
                }
                if ($typefnz == "FNZ_002") {
                }
                $tableStyle = new TableStyle();
                $tableStyle->setTheme(TableStyle::TABLE_STYLE_LIGHT1);
                $tableStyle->setShowRowStripes(true);
                $table->setStyle($tableStyle);

                $activeWorksheet->addTable($table);
                (new DocumentComplements())->adjustColumns($data[0], $activeWorksheet);
                break;
            case 'sts':
                if ($typefnz == "STS_001") {
                    $activeWorksheet->setTitle("Inventario");
                    $items = $this->db->prepare("
                        SELECT 
                            `items`.`id` AS id, 
                            COALESCE(JSON_VALUE(CAST(AES_DECRYPT(`items`.`advanced`, :aes) AS CHAR), '$.provider'), '') AS provider,
                            COALESCE(JSON_VALUE(CAST(AES_DECRYPT(`items`.`advanced`, :aes) AS CHAR), '$.provider_price'), '') AS provider_price,
                            COALESCE(`items`.`id_provider`, '') AS id_provider,
                            quantity, 
                            JSON_VALUE(`items`.`info`, '$.departament') AS depa,
                            d.name AS name
                        FROM 
                            `items`
                        JOIN 
                            `departments` AS d ON d.`uuid` = JSON_VALUE(`items`.`info`, '$.departament')
                        WHERE 
                            JSON_VALUE(`items`.`info`, '$.departament') IS NOT NULL AND TRIM(JSON_VALUE(`items`.`info`, '$.departament')) <> ''
                        ORDER BY 
                            d.name ASC;
                        ");


                    $sales = $this->db->prepare("
                        SELECT 
                            buy, 
                            event 
                        FROM 
                            `sales`
                        ");

                    $items->execute([
                        ":aes" => $_ENV['AES_KEY']
                    ]);
                    $sales->execute();
                    $list = (new ExcelControllerData())->STS_001($filter["year"], $items->fetchAll(PDO::FETCH_ASSOC), $sales->fetchAll(PDO::FETCH_ASSOC));

                    $activeWorksheet->setTitle("Inventario");
                    $activeWorksheet->fromArray($list["inv"], NULL, 'A1');
                    $table = new Table('A1:' . chr(64 + count($list["inv"][0])) . count($list["inv"]), 'Table1');
                    $tableStyle = new TableStyle();
                    $tableStyle->setTheme(TableStyle::TABLE_STYLE_LIGHT1);
                    $tableStyle->setShowRowStripes(true);
                    $table->setStyle($tableStyle);
                    $activeWorksheet->addTable($table);
                    (new DocumentComplements())->adjustColumns($list["inv"][0], $activeWorksheet);

                    // crear nueva sheet
                    $newWorksheet = $spreadsheet->createSheet();
                    $newWorksheet->setTitle('Finanzas'); 

                    $newWorksheet->fromArray($list["fnz"], NULL, 'A1');
                    $table = new Table('A1:' . chr(64 + count($list["fnz"][0])) . count($list["fnz"]), 'Table2');
                    $tableStyle = new TableStyle();
                    $tableStyle->setTheme(TableStyle::TABLE_STYLE_LIGHT1);
                    $tableStyle->setShowRowStripes(true);
                    $table->setStyle($tableStyle);
                    $newWorksheet->addTable($table);
                    (new DocumentComplements())->adjustColumns($list["fnz"][0], $newWorksheet);

                }
                if ($typefnz == "STS_003") {
                    $activeWorksheet->setTitle("Resumen");
                    $items = $this->db->prepare("
                        SELECT 
                            `items`.`id` AS id, 
                            JSON_VALUE(`items`.`info`, '$.departament') AS depa,
                            d.name AS name
                        FROM 
                            `items`
                        JOIN 
                            `departments` AS d ON d.`uuid` = JSON_VALUE(`items`.`info`, '$.departament')
                        WHERE 
                            JSON_VALUE(`items`.`info`, '$.departament') IS NOT NULL AND TRIM(JSON_VALUE(`items`.`info`, '$.departament')) <> ''
                        ORDER BY 
                            d.name ASC;
                        ");


                    $sales = $this->db->prepare("
                        SELECT 
                            buy, 
                            event 
                        FROM 
                            `sales`
                        ");

                    $items->execute();
                    $sales->execute();
                    $list = (new ExcelControllerData())->STS_003($filter["month"], $items->fetchAll(PDO::FETCH_ASSOC), $sales->fetchAll(PDO::FETCH_ASSOC));

                    $output = $list["output"];
                    $newTable = [];
                    
                    $offset = 0;

                    foreach ($output as $key => $value) {
                        if (is_string($value) && isset($list["tables"][$value])) {
                            $startRow = $key + 1 + $offset;
                            $endRow = $startRow + count($list["tables"][$value]) - 1;
                            $endColumn = chr(64 + count($list["tables"][$value][0]));
                            
                            $newTable[] = "A{$startRow}:{$endColumn}{$endRow}";
                    
                            $output = array_merge(
                                array_slice($output, 0, $key + $offset),
                                $list["tables"][$value],
                                array_slice($output, $key + $offset + 1)
                            );
                    
                            $offset += count($list["tables"][$value]) - 1;
                        }
                    }


                    $activeWorksheet->fromArray($output, NULL, 'A1');
                    


                    foreach ($newTable as $id => $text) {
                        $table = new Table($text, "Table".$id + 1);
                        $tableStyle = new TableStyle();
                        $tableStyle->setTheme(TableStyle::TABLE_STYLE_LIGHT1);
                        $tableStyle->setShowRowStripes(true);
                        $table->setStyle($tableStyle);
                    
                        $activeWorksheet->addTable($table);
                    }
                    $activeWorksheet->getStyle("A")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                    (new DocumentComplements())->adjustColumns($output[2], $activeWorksheet);

                }
                if ($typefnz == "STS_002") {

                    $sales = $this->db->prepare("
                        SELECT 
                            s.buy,
                            CAST(AES_DECRYPT(s.info, :aes) AS CHAR) AS info,
                            s.event,
                            CAST(AES_DECRYPT(u.acctPersonal, :aes) AS CHAR) as personalInfo
                        FROM 
                            `sales` as s
                        JOIN 
                            `users` AS u ON u.`id` = JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(s.info, :aes) AS CHAR), '$[0]'))
                    ");
                    $sales->execute([":aes" => $_ENV['AES_KEY']]);
                    $datas = $sales->fetchAll(PDO::FETCH_ASSOC);

                    
                    $list = (new ExcelControllerData())->STS_002($filter["year"], $datas);

                    $activeWorksheet->setTitle("Inventario");
                    $activeWorksheet->fromArray($list["inv"], NULL, 'A1');
                    $table = new Table('A1:' . chr(64 + count($list["inv"][0])) . count($list["inv"]), 'Table1');
                    $tableStyle = new TableStyle();
                    $tableStyle->setTheme(TableStyle::TABLE_STYLE_LIGHT1);
                    $tableStyle->setShowRowStripes(true);
                    $table->setStyle($tableStyle);
                    $activeWorksheet->addTable($table);
                    (new DocumentComplements())->adjustColumns($list["inv"][0], $activeWorksheet);

                    // crear nueva sheet
                    $newWorksheet = $spreadsheet->createSheet();
                    $newWorksheet->setTitle('Finanzas'); 

                    $newWorksheet->fromArray($list["fnz"], NULL, 'A1');
                    $table = new Table('A1:' . chr(64 + count($list["fnz"][0])) . count($list["fnz"]), 'Table2');
                    $tableStyle = new TableStyle();
                    $tableStyle->setTheme(TableStyle::TABLE_STYLE_LIGHT1);
                    $tableStyle->setShowRowStripes(true);
                    $table->setStyle($tableStyle);
                    $newWorksheet->addTable($table);
                    (new DocumentComplements())->adjustColumns($list["fnz"][0], $newWorksheet);

                }

                break;
        }



        $writer = new Xlsx($spreadsheet);


        header('Content-Disposition: attachment;filename="' . urlencode('data.xlsx') . '"');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Cache-Control: max-age=0');
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
    }

    private function Pdf($type)
    {

        $template = Null;
        $documentType =  ['PRESUPUESTO', 'NOTA DE ENTREGA'];

        $uuid = Flight::request()->data->uuid;
        $namefile = 'data/doc/pdf/' . $uuid . '_' . Flight::request()->data->type . '.pdf';
        if (file_exists($namefile) && Flight::request()->data->update == false) {
            $pdfContent = file_get_contents($namefile);
            $pdfBlob = base64_encode($pdfContent);
            Flight::halt(message: json_encode([
                "response" => $pdfBlob,
                "status" => "recovered"
            ]));
        }

        switch (Flight::request()->data->type) {
            case 'note':
                //case 'prss':
                $query = $this->db->prepare("SELECT    
                    s.nr as nr, 
                    s.type as type,
                    JSON_VALUE(CAST(AES_DECRYPT(s.info,:aes) AS CHAR), '$[2]') as client, 
                    JSON_VALUE(CAST(AES_DECRYPT(s.info,:aes) AS CHAR), '$[1][0]') as identtype,
                    JSON_VALUE(CAST(AES_DECRYPT(s.info,:aes) AS CHAR), '$[1][1]') as ident,
                    s.buy as buy,
                    JSON_UNQUOTE(JSON_EXTRACT(s.`event`,CONCAT(\"$[\",JSON_LENGTH(s.`event`)-1,\"].event\"))) as status,
                    JSON_UNQUOTE(JSON_EXTRACT(s.`event`,CONCAT(\"$[\",JSON_LENGTH(s.`event`)-1,\"].date\"))) as date,
                    JSON_VALUE(s.advanced, '$.additionals.coment') as coment,
                    JSON_VALUE(s.advanced, '$.additionals.name') as name,
                    JSON_VALUE(s.advanced, '$.additionals.credit') as credit,
                    JSON_VALUE(s.advanced, '$.additionals.discount') as discount,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(c.acctAddresses,:aes) AS CHAR),'$[0].address')), '') AS address,
                    JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(c.acctPersonal,:aes) AS CHAR),'$.tlf.number')) AS tlfnumber,
                    JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(c.acctPersonal,:aes) AS CHAR),'$.tlf.operator')) AS tlfoperator
                    FROM 
                        sales s 
                    LEFT JOIN 
                        users c 
                    ON 
                        c.id = JSON_VALUE(CAST(AES_DECRYPT(s.info,:aes) AS CHAR), '$[0]')
                    WHERE 
                        s.uuid = :uuid;");
                $query->execute([":aes" => $_ENV['AES_KEY'], ":uuid" => $uuid]);
                $item = $query->fetch(PDO::FETCH_ASSOC);

                [
                    'costBase' => $Cost,
                    'total' => $Total,
                    'buys' => $buyContent,
                    'costWithProductDiscounts' => $costWithProductDiscounts,
                    'costWithGeneralDiscount' => $costWithGeneralDiscount,
                    'generalDiscount' => $generalDiscount,
                    'discpercent' => $discPercent
                ] = (new ItemUtils)->calculatePrices($item);

                $template = new Template("src/doc/pdf/note_pres_pend.html", array('include_css' => true));
                $data = array(
                    'companyLogo' => "src/img/logo.jpg",
                    'companyAddress' => "CALLE 3 CASA NRO 11 SECTOR 2 VALLE LINDO,<br> TURMERO - ARAGUA",
                    'companyPhone1' => '0424-3779974',
                    'companyPhone2' => '0424-3015738',
                    'companyEmail1' => 'ventas.multipartesjm@gmail.com',
                    'companyEmail2' => 'creditoycobranzasmultipartesjm@gmail.com',
                    'isDiscount' => true,
                    'typeDocument' => $documentType[$item['type']],
                    'nrDocument' => sprintf("%010d", $item['nr']),
                    'typeCurrency' => 'Moneda $ USD',
                    'dateIssueDoc' => date("d/m/Y", strtotime($item["date"])),
                    'timeIssueDoc' => date("h:i A", strtotime($item["date"])),
                    'creditIssueDoc' => $item['type'] == 1 ? date("d/m/Y", strtotime($item["date"] . " + " . $item["credit"] . " days")) : 'NO APLICA',
                    'nameClientInfo' => $item['client'],
                    'indentClientInfo' => $this->typeIdent[$item['identtype']] . " - " . $item['ident'],
                    'phoneClientInfo' => "(" . $this->typeTlf[$item['tlfoperator']] . ") - " . $item['tlfnumber'],
                    'addresClientInfo' => $item['address'],
                    'buys' => $buyContent,
                    'totalInfoDoc' => number_format($costWithProductDiscounts, 2, ',', '.'),
                    'discountInfoDoc' => number_format($generalDiscount, 2, ',', '.') . "$<space class='sp-1'>" . implode("", $discPercent),
                    'totalOperatingInfoDoc' => number_format($costWithGeneralDiscount, 2, ',', '.'),
                    'nameInfoFooterImportant' => 'MULTIPARTES JM &, C.A.',
                    'conditionInfoFooterImportant' => 'SIN DERECHO A CREDITO FISCAL',
                    'observationInfoDoc' => $item['name'] . "<br>" . $item['coment']
                );
                break;
            case 'fact':
                // Código a ejecutar para 'fact'
                break;
            case 'null':
                // Código a ejecutar para 'null'
                break;
            case 'pend':
                $query = $this->db->prepare("SELECT    
                    JSON_VALUE(CAST(AES_DECRYPT(s.info,:aes) AS CHAR), '$[2]') as client, 
                    JSON_VALUE(CAST(AES_DECRYPT(s.info,:aes) AS CHAR), '$[1][0]') as identtype,
                    JSON_VALUE(CAST(AES_DECRYPT(s.info,:aes) AS CHAR), '$[1][1]') as ident,
                    s.buy as buy,
                    JSON_UNQUOTE(JSON_EXTRACT(s.`status`,CONCAT(\"$[\",JSON_LENGTH(s.`status`)-1,\"].event\"))) as status,
                    JSON_UNQUOTE(JSON_EXTRACT(s.`status`,CONCAT(\"$[\",JSON_LENGTH(s.`status`)-1,\"].date\"))) as date,
                    JSON_VALUE(s.advanced, '$.additionals.name') as name,
                    '' AS coment,
                    0 AS credit, 
                    JSON_ARRAY('', FALSE) as discount,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(c.acctAddresses,:aes) AS CHAR),'$[0].address')), '') AS address,
                    JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(c.acctPersonal,:aes) AS CHAR),'$.tlf.number')) AS tlfnumber,
                    JSON_UNQUOTE(JSON_EXTRACT(CAST(AES_DECRYPT(c.acctPersonal,:aes) AS CHAR),'$.tlf.operator')) AS tlfoperator
                    FROM 
                        retainedpurchases s 
                    LEFT JOIN 
                        users c 
                    ON 
                        c.id = JSON_VALUE(CAST(AES_DECRYPT(s.info,:aes) AS CHAR), '$[0]')
                    WHERE 
                        s.id = :uuid;");
                $query->execute([":aes" => $_ENV['AES_KEY'], ":uuid" => $uuid]);
                $item = $query->fetch(PDO::FETCH_ASSOC);

                [
                    'costBase' => $Cost,
                    'total' => $Total,
                    'buys' => $buyContent,
                    'costWithProductDiscounts' => $costWithProductDiscounts,
                    'costWithGeneralDiscount' => $costWithGeneralDiscount,
                    'generalDiscount' => $generalDiscount,
                    'discpercent' => $discPercent
                ] = (new ItemUtils)->calculatePrices($item);

                $template = new Template("src/doc/pdf/note_pres_pend.html", array('include_css' => true));
                $data = array(
                    'companyLogo' => "src/img/logo.jpg",
                    'companyAddress' => "CALLE 3 CASA NRO 11 SECTOR 2 VALLE LINDO,<br> TURMERO - ARAGUA",
                    'companyPhone1' => '0424-3779974',
                    'companyPhone2' => '0424-3015738',
                    'companyEmail1' => 'ventas.multipartesjm@gmail.com',
                    'companyEmail2' => 'creditoycobranzasmultipartesjm@gmail.com',
                    'isDiscount' => false,
                    'typeDocument' => 'PRESUPUESTO',
                    'nrDocument' => substr(str_replace('-', '', $uuid), 0, 16),
                    'typeCurrency' => 'Moneda $ USD',
                    'dateIssueDoc' => date("d/m/Y", strtotime($item["date"])),
                    'timeIssueDoc' => date("h:i A", strtotime($item["date"])),
                    'creditIssueDoc' => 'NO APLICA',
                    'nameClientInfo' => $item['client'],
                    'indentClientInfo' => $this->typeIdent[$item['identtype']] . " - " . $item['ident'],
                    'phoneClientInfo' => "(" . $this->typeTlf[$item['tlfoperator']] . ") - " . $item['tlfnumber'],
                    'addresClientInfo' => $item['address'],
                    'buys' => $buyContent,
                    'totalInfoDoc' => number_format($costWithProductDiscounts, 2, ',', '.'),
                    'discountInfoDoc' => number_format($generalDiscount, 2, ',', '.') . "$<space class='sp-1'>" . implode("", $discPercent),
                    'totalOperatingInfoDoc' => number_format($costWithGeneralDiscount, 2, ',', '.'),
                    'nameInfoFooterImportant' => 'MULTIPARTES JM &, C.A.',
                    'conditionInfoFooterImportant' => 'SIN DERECHO A CREDITO FISCAL',
                    'observationInfoDoc' => $item['name'] . "<br>" . $item['coment']
                );
                break;
            case 'retained':
                $query = $this->db->prepare("SELECT    
                    JSON_VALUE(CAST(AES_DECRYPT(s.info,:aes) AS CHAR), '$[2]') as client, 
                    s.buy as buy,
                    JSON_UNQUOTE(JSON_EXTRACT(s.`status`,CONCAT(\"$[\",JSON_LENGTH(s.`status`)-1,\"].event\"))) as status,
                    JSON_UNQUOTE(JSON_EXTRACT(s.`status`,CONCAT(\"$[\",JSON_LENGTH(s.`status`)-1,\"].date\"))) as date,
                    JSON_VALUE(s.advanced, '$.additionals.name') as name
                    FROM 
                        retainedpurchases s
                    WHERE 
                    s.id = :uuid;");
                $query->execute([":aes" => $_ENV['AES_KEY'], ":uuid" => $uuid]);
                $item = $query->fetch(PDO::FETCH_ASSOC);
                $buy = json_decode($item["buy"], true);

                foreach ($buy as $k => &$v) {
                    $query1 = $this->db->prepare("
                        SELECT 
                            IF(JSON_UNQUOTE(JSON_EXTRACT(i.info, '$.departament')) = '', '', d.name) AS depa
                        FROM 
                            `items` i
                            LEFT JOIN departments d ON JSON_UNQUOTE(JSON_EXTRACT(i.info, '$.departament')) = d.uuid
                        WHERE 
                            i.`id` = :code
                    ");
                    $query1->execute([":code" => $v["code"]]);
                    $code = $query1->fetch(PDO::FETCH_ASSOC);
                    
                    $v["total"] = 0;
                    $v["depa"] = ($code && isset($code["depa"])) ? $code["depa"] : "No tiene departamento";
                    $tempPacks = [];
                    $tempVerify = [];

                    foreach ($v["packs"] as $i => $p) {
                        $v["total"] += $i * $p;
                        $tempPacks[] = "Pq($i). => $p";
                        $tempVerify[] = "Pq($i). => ____";
                        if (count($tempPacks) % 2 == 0) {
                            $tempPacks[] = "<br>";
                            $tempVerify[] = "<br>";
                        }
                    }
                    $v["packs"] = implode(" ", $tempPacks);
                    $v["verify"] = implode(" ", $tempVerify);
                }

                $template = new Template("src/doc/pdf/retained.html", array('include_css' => true));
                $data = array(
                    "buys" => $buy,
                    "uuid" => $uuid,
                    "date" => $item["date"],
                    "nameClient" => $item["client"],
                    "namePedding" => $item["name"]
                );
                break;
        }

        $html = $template->with($data)->render();
        $options = new Options();

        $options->set('isFontSubsettingEnabled', true);
        $options->set('chroot', realpath(''));
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->loadHtml($html);
        $dompdf->render();
        file_put_contents($namefile, $dompdf->output());
        $pdfContent = file_get_contents($namefile);
        $pdfBlob = base64_encode($pdfContent);
        Flight::halt(message: json_encode([
            "response" => $pdfBlob,
            "status" => "created"
        ]));
    }
}
