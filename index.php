<?php
// Load the .env file
use Dotenv\Dotenv;

require 'vendor/autoload.php';
// Controllers
require 'controllers/AuthController.php';
require 'controllers/ItemController.php';
require 'controllers/SaleController.php';
require 'controllers/StoreController.php';
require 'controllers/DocumentController.php';
require 'controllers/ApiController.php';
require 'controllers/SystemController.php';


// Pines Sys
require 'controllers/pineSys/ItemController.php';
require 'controllers/pineSys/DepartamentController.php';




// Utils
require 'utils/ApiUtils.php';
require 'utils/ItemUtils.php';
require 'utils/SaleUtils.php';
require 'utils/TemplateUtils.php';
require 'utils/DocumentUtils.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$dbHost = $_ENV['DB_HOST'];
$dbName = $_ENV['DB_NAME'];
$dbUser = $_ENV['DB_USER'];
$dbPass = $_ENV['DB_PASS'];

Flight::register('db', 'PDO', array("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass));

// ITEMS
Flight::route('POST /item/all', ['ItemManager', 'all_item']);
Flight::route('POST /item/@uuid', ['ItemManager', 'get_item']);
Flight::route('POST /item/quantity/code/@id', ['ItemManager', 'get_item_quantity_admin']);

Flight::route('POST /item/packets/quantity/@id', ['ItemManager', 'quantity_packets']);
Flight::route('POST /item/quantity/@uuid', ['ItemManager', 'get_item_quantity']);

Flight::route('POST /item/img/upload', ['ItemManager', 'upload_img']);
Flight::route('POST /item/img/delete', ['ItemManager', 'del_img']);
Flight::route('GET /item/img/code/@id', ['ItemManager', 'get_by_code_img']);
Flight::route('GET /item/img/@id/@img', ['ItemManager', 'get_img']);



// ITEMS PINES SYS

Flight::route('GET /s/item/all', ['PinesSys_ItemManager', 'all_item']);
Flight::route('GET /s/item/@uuid', ['PinesSys_ItemManager', 'get_item']);
Flight::route('PATCH /s/item/@uuid', ['PinesSys_ItemManager', 'update_item_info']);
Flight::route('DELETE /s/item/@uuid', ['PinesSys_ItemManager', 'delete_item']);
Flight::route('GET /s/item/quantity/@uuid', ['PinesSys_ItemManager', 'get_item_quantity']);

Flight::route('GET /s/item/@uuid/prices', ['PinesSys_ItemManager', 'get_item_prices']);
Flight::route('PATCH /s/item/@uuid/prices', ['PinesSys_ItemManager', 'update_item_prices']);

Flight::route('GET /s/item/@uuid/provider', ['PinesSys_ItemManager', 'get_item_provider']);
Flight::route('PATCH /s/item/@uuid/provider', ['PinesSys_ItemManager', 'update_item_provider']);


Flight::route('GET /s/item/@uuid/black_list', ['PinesSys_ItemManager', 'get_item_black_list']);
Flight::route('PATCH /s/item/@uuid/black_list', ['PinesSys_ItemManager', 'update_item_blacklist']);

// DEPARTAMENT PINES SYS
Flight::route('GET /s/departament/all', ['PinesSys_DepartamentManager', 'all_item']);



// AUTENTICATION
Flight::route('POST /auth', ['AuthManager', 'newAuth']);

// STORE
Flight::route('POST /store/push', ['StoreController', 'push_buy']);
Flight::route('POST /store/info', ['StoreController', 'get_info']);

// SALES
Flight::route('POST /sale/all', ['SaleController', 'all_sales']);
Flight::route('POST /sale/@type/@uuid', ['SaleController', 'get_sale']);

// DOCUMENT
Flight::route('POST /document/view/@format/@type', ['DocumentController', 'init']);

//API
Flight::route('GET /health', ['ApiManager', 'health']);
Flight::route('GET /health/resource/@type', ['ApiManager', 'resources']);
Flight::route('GET /logs/inv', ['ApiManager', 'get_logs_inventory']);
Flight::route('POST /logs/inv', ['ApiManager', 'log_inventory']);

//ADMIN 
Flight::route('GET /system/order', ['SystemManager', 'get_order']);


Flight::before('start', function () {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");

    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        http_response_code(200);
        exit();
    }    
    
    $currentPath = Flight::request()->url;
    $adminPaths = [
        '/health',
        '/health/resource',
        '/logs/inv',
        '/item/img/upload',
        '/item/img/delete',
        '/system/order'
    ];

    if ($currentPath !== '/auth') {
        $tokenInfo = (new AuthManager())->validateToken();
        Flight::set('token', $tokenInfo);
    }

    foreach ($adminPaths as $excludedPath) {
        if (strpos($currentPath, $excludedPath) === 0) {
            return;
        }
    }

    // Verificar si el usuario es administrador
    if (in_array($currentPath, $adminPaths) && $tokenInfo->data[3] !== 'admin') {
        Flight::halt(403, json_encode(["response" => 'Acceso denegado']));
    }

    (new ApiManager())->log_request();
});

Flight::start();
