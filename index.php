<?php
// Load the .env file
use Dotenv\Dotenv;

require 'vendor/autoload.php';
// Controllers
require 'controllers/BatchJobController.php';
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
Flight::route('POST /item/packets/quantity/@id', ['ItemManager', 'quantity_packets']);
Flight::route('POST /item/quantity/@uuid', ['ItemManager', 'get_item_quantity']);

Flight::route('GET /item/img/code/@id', ['ItemManager', 'get_by_code_img']);
Flight::route('GET /item/img/@id/@img', ['ItemManager', 'get_img']);



// ITEMS ADMIN
Flight::route('POST /s/item/img/upload', ['ItemManager', 'upload_img']);
Flight::route('POST /s/item/img/delete', ['ItemManager', 'del_img']);

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

// DEPARTAMENT ADMIN
Flight::route('GET /s/departament/all', ['PinesSys_DepartamentManager', 'all_departaments']);



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

// BATCH JOBS ADMIN
Flight::route('GET /batch_jobs', ['BatchJobController', 'get_all']);
Flight::route('POST /batch_jobs', ['BatchJobController', 'upsert']);
Flight::route('PATCH /batch_jobs/@id', ['BatchJobController', 'update_status']);
Flight::route('DELETE /batch_jobs/@id', ['BatchJobController', 'cancel']);

Flight::before('start', function () {
    // Set CORS headers explicitly
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");



    $headers = array();
    foreach (apache_request_headers() as $key => $value) {
        // Convert header names to standard format: First letter uppercase, dashes preserved, rest lowercase
        $key = strtolower($key);

        $key = ucwords(strtolower($key), '-');
        $headers[$key] = $value;
    }
    Flight::set('headers', $headers);

    // Log request for debugging, including query params, body, and headers
    $method = $_SERVER['REQUEST_METHOD'];
    $url = Flight::request()->url;
    $queryParams = json_encode(Flight::request()->query->getData());
    $body = json_encode(Flight::request()->data->getData());
    $headers = Flight::get('headers');
    
    error_log("Request: $method $url | Query: $queryParams | Body: $body | Headers: " .  json_encode($headers) );

    // Handle OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        error_log("Handling OPTIONS for " . Flight::request()->url);
        http_response_code(204);
        exit();
    }

    $currentPath = Flight::request()->url;

    $adminPaths = [
        '/s/',
        '/system/',
        '/batch_jobs',
        '/health',
        '/health/resource',
        '/logs/inv'
        // Puedes añadir más aquí si tienes otras rutas admin
    ];

    // Skip token validation for /auth
    if ($currentPath !== '/auth') {
        $tokenInfo = (new AuthManager())->validateToken();
        Flight::set('token', $tokenInfo);
    }

    // Proteger rutas admin si el path comienza con alguno de los adminPaths
    foreach ($adminPaths as $adminPath) {
        if (strpos($currentPath, $adminPath) === 0) {
            $tokenInfo = Flight::get('token');
            if (!isset($tokenInfo->data[3]) || $tokenInfo->data[3] !== 'admin') {
                Flight::halt(403, json_encode(["response" => 'Acceso denegado']));
            }
            break;
        }
    }

    (new ApiManager())->log_request();
});

Flight::start();
