<?php

use Jenssegers\Agent\Agent;

class ApiManager
{
    private $db;

    private function checkDatabase()
    {
        try {
            $this->db = Flight::db();
            $this->db->query("SELECT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function health()
    {
        $status = 'Online';
        $code = 1;
        $services = [];

        if (!$this->checkDatabase()) {
            $code = 0;
            $services["bd"] = ['status' => 'Offline', 'code' => -1];
        } else {
            $services["bd"] = ['status' => 'Online', 'code' => 1];
        }
        Flight::json([
            'status' => $status,
            'code' => $code,
            'services' => $services,
            'timestamp' => time()
        ]);
    }
    public function resources($type)
    {
        $json = [];
        switch ($type) {
            case 1:
                $json = $this->resources_simple();
                break;
        }
        Flight::json($json);
    }

    private function resources_simple()
    {
        $args = '--cpu core --ram use --disk use --bandwidth';
        $command = escapeshellcmd("python py/getsystem_info.py $args");
        $output = json_decode(shell_exec($command), true);
        $output["request_today"] = $this->request_today(); 
        return $output;
    }

    private function request_today(){
        $logFile = 'logs/request/' . date('Y-m-d') . '.log';
        $requestsToday = 0;
        
        if (file_exists($logFile)) {
            $logEntries = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $requestsToday = count($logEntries);
        }
        return $requestsToday; // Cambiar $request_today a $requestsToday
    }
    

    public function log_request() {
        $logDir = 'logs/request/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $todayDate = date('Y-m-d');
        
        $files = glob($logDir . '*.log');
        foreach ($files as $file) {
            $fileDate = basename($file, '.log');
            if ($fileDate !== $todayDate) {
                unlink($file);
            }
        }
        $logFile = $logDir . $todayDate . '.log';
        $requestMethod = Flight::request()->method;
        $requestUri = Flight::request()->url;
        $timestamp = date('Y-m-d H:i:s');
        $clientIp = Flight::request()->ip;
        $headers = apache_request_headers();
    
        $essentialHeaders = ['User-Agent', 'Accept'];
        $headersArray = [];
        foreach ($essentialHeaders as $header) {
            if (isset($headers[$header])) {
                $headersArray[$header] = $headers[$header];
            }
        }
    
        $logEntry = json_encode([
            'ttmp' => $timestamp,
            'mthd' => $requestMethod,
            'uri' => $requestUri,
            'ip' => $clientIp,
            'hd' => $headersArray
        ]) . PHP_EOL;
    
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    public function log_inventory($data = null) {
        $logDir = 'logs/inventory/';
        $todayDate = date('Y-m-d');
        $logFile = $logDir . $todayDate . '.log';
        $timestamp = date('Y-m-d H:i:s');
    
        $requestMethod = Flight::request()->method;
        $requestUri = Flight::request()->url;
        $clientIp = Flight::request()->ip;
        $userAgent = (new ApiUtils())->getHeader("User-Agent");
    
        if ($data === null) {
            $xDeviceBy = ApiUtils::getHeader('X-Device-By');
            $requestData = Flight::request()->data;
        } else {
            $xDeviceBy = $data['dvc'] ?? '';
            $requestData = $data;
    
            if (array_key_exists('ip', $data)) {
                $clientIp = $data['ip']; 
            }
    
            if (array_key_exists('agent', $data)) {
                $userAgent = $data['agent']; 
            }
        }
    
        $logEntry = json_encode([
            'ttmp' => $timestamp,
            'ip' => $clientIp,
            'dvc' => $xDeviceBy,
            'mthd' => $requestMethod,
            'url' => $requestUri,
            'agent' => $userAgent,
            ...$requestData
        ]) . PHP_EOL;
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    
        if ($data === null) {
            Flight::json([
                'response' => true
            ]);
        } else {
            return true;
        }
    }

    public function get_logs_inventory() {
        $logDir = 'logs/inventory/';
        $logs = [];
        $agent = new Agent();
    
        if (is_dir($logDir)) {
            if ($handle = opendir($logDir)) {
                while (false !== ($entry = readdir($handle))) {
                    if ($entry != "." && $entry != "..") {
                        $logFilePath = $logDir . $entry;
                        if (is_file($logFilePath)) {
                            $date = pathinfo($entry, PATHINFO_FILENAME);
                            $fileContents = file($logFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                            $contentArray = [];
    
                            foreach ($fileContents as $line) {
                                // Primero, decodifica el JSON
                                $lineData = json_decode($line, true);
                                
                                // Verifica si la decodificación fue exitosa y contiene la clave 'agent'
                                if ($lineData && isset($lineData['agent'])) {
                                    // Establece el agente
                                    $agent->setUserAgent($lineData['agent']);
                                    
                                    // Obtiene la plataforma
                                    $lineData['agent'] = $agent->platform();
                                    
                                    // Agrega al inicio del array de contenido
                                    array_unshift($contentArray, $lineData);
                                }
                            }
                            $logs[$date] = $contentArray;
                        }
                    }
                }
                closedir($handle);
            }
        } else {
            return Flight::json([
                'response' => false,
                'message' => 'Log directory does not exist.'
            ]);
        }
    
        krsort($logs);
    
        return Flight::json([
            'response' => true,
            'logs' => $logs
        ]);
    }
}
