<?php

class LabelController
{
    private $db;
    private $AES_KEY;
    private $HMAC_KEY;
    private $CIPHER = 'aes-256-cbc';
    private $IV_LENGTH = 16;

    function __construct()
    {
        $this->db = Flight::db();
        $this->AES_KEY = $_ENV['AES_KEY'];
        $this->HMAC_KEY = $_ENV['HMAC_KEY'];
    }

    /**
     * Derive a 32-byte key from AES_KEY using SHA-256
     */
    private function deriveKey()
    {
        return hash('sha256', $this->AES_KEY, true);
    }

    /**
     * Encrypt data into a signed, URL-safe token
     * Format: base64url(hmac + iv + ciphertext)
     */
    private function encrypt(string $data): string
    {
        $iv = openssl_random_pseudo_bytes($this->IV_LENGTH);
        $key = $this->deriveKey();

        $ciphertext = openssl_encrypt($data, $this->CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        // HMAC over iv+ciphertext — truncated to 10 bytes to match decrypt() and Python
        $hmac = substr(hash_hmac('sha256', $iv . $ciphertext, $this->HMAC_KEY, true), 0, 10);

        $token = $this->base64url_encode($hmac . $iv . $ciphertext);
        return $token;
    }

    /**
     * Decrypt and verify a signed token
     * Returns decrypted data array or false on failure
     */
    private function decrypt(string $token)
    {
        $raw = $this->base64url_decode($token);

        // HMAC truncated to 10 bytes (80-bit) to keep token ~56 chars
        $hmacLen    = 10;
        $hmac       = substr($raw, 0, $hmacLen);
        $iv         = substr($raw, $hmacLen, $this->IV_LENGTH);
        $ciphertext = substr($raw, $hmacLen + $this->IV_LENGTH);

        // Verify truncated HMAC first (prevent tampering)
        $expectedHmac = substr(hash_hmac('sha256', $iv . $ciphertext, $this->HMAC_KEY, true), 0, $hmacLen);
        if (!hash_equals($expectedHmac, $hmac)) {
            return false;
        }

        $key = $this->deriveKey();
        $plain = openssl_decrypt($ciphertext, $this->CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            return false;
        }

        // Plaintext is CSV: id,purchase_number,amount(q),num_packages(n)
        $parts = explode(',', $plain, 4);
        return [
            'id'              => $parts[0] ?? null,
            'purchase_number' => isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null,
            'amount'          => isset($parts[2]) ? (int)$parts[2] : null,
            'n'               => isset($parts[3]) && $parts[3] !== '' ? (int)$parts[3] : null,
        ];
    }

    private function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64url_decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * POST /label
     * Generate and print a label using the Python label_maker script
     */
    public function generate()
    {
        // Parse raw JSON body to reliably detect keys with null values
        $body            = json_decode(Flight::request()->body, true) ?? [];
        $id              = $body['id']     ?? null;
        $amount          = $body['amount'] ?? null;
        $copies          = min(50, max(1, (int)($body['copies'] ?? 1)));
        $number          = max(1, (int)($body['number'] ?? 1));
        $hasPurchase     = array_key_exists('purchase_number', $body);
        $purchase_number = $body['purchase_number'] ?? null;
        $layoutData      = $body['layout'] ?? null;   // optional custom layout

        if ($id === null || $amount === null || !$hasPurchase) {
            Flight::jsonHalt([
                "response" => "Los campos 'id', 'amount' y 'purchase_number' son obligatorios"
            ], 400);
        }

        $query = $this->db->prepare("
            SELECT uuid, id,
                   JSON_VALUE(info, '$.desc')  AS description,
                   JSON_VALUE(info, '$.brand') AS brand
            FROM items WHERE id = :id LIMIT 1
        ");
        $query->execute([':id' => $id]);
        $item = $query->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            Flight::jsonHalt(["response" => "Producto no encontrado"], 404);
        }

        $payload = [
            'uuid'            => $item['uuid'],
            'code'            => $item['id'],
            'description'     => ($item['brand'] ? $item['brand'] . ' ' : '') . ($item['description'] ?? ''),
            'purchase_number' => $purchase_number,
            'amount'          => (int)$amount,
            'n'               => $number,
            'total_copies'    => $copies,
            'content'         => ['quantity' => (int)$amount],
            'charge_id'       => $purchase_number,
        ];

        $projectRoot = realpath(__DIR__ . '/..');
        $scriptPath  = $projectRoot . DIRECTORY_SEPARATOR . 'py' . DIRECTORY_SEPARATOR . 'label_maker.py';
        $pythonBin   = $_ENV['PYTHON_BIN'] ?? 'C:/Python313/python.exe';

        $tmpFile = tempnam(sys_get_temp_dir(), 'label_');
        file_put_contents($tmpFile, json_encode($payload, JSON_UNESCAPED_UNICODE));

        // Write custom layout to tmp file if provided
        $layoutFile = null;
        if ($layoutData !== null) {
            $layoutTmp  = tempnam(sys_get_temp_dir(), 'layout_');
            $layoutFile = $layoutTmp . '.json';
            @unlink($layoutTmp); // elimina el placeholder vacío creado por tempnam
            file_put_contents($layoutFile, json_encode($layoutData, JSON_UNESCAPED_UNICODE));
        }

        $pythonPackages = $_ENV['PYTHON_PACKAGES'] ?? '';
        $envPrefix      = $pythonPackages
            ? 'set "PYTHONPATH=' . $pythonPackages . '" && '
            : '';

        $results = [];
        for ($i = 0; $i < $copies; $i++) {
            // --copy-num makes each QR unique even for identical copies
            $layoutArg = $layoutFile ? ' --layout-file ' . escapeshellarg($layoutFile) : '';
            $cmd = 'cmd /c "' . $envPrefix
                . str_replace('/', '\\', $pythonBin) . ' '
                . escapeshellarg($scriptPath)
                . ' --payload-file ' . escapeshellarg($tmpFile)
                . $layoutArg
                . ' --copy-num ' . ($i + 1)
                . ' --total-copies ' . $copies
                . ' --print" 2>&1';

            $output     = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            $outputStr = implode("\n", $output);
            $result    = json_decode($outputStr, true);

            if ($returnCode !== 0 || $result === null) {
                @unlink($tmpFile);
                if ($layoutFile) @unlink($layoutFile);
                Flight::jsonHalt([
                    "response" => "Error al imprimir etiqueta #" . ($i + 1),
                    "detail"   => $outputStr
                ], 500);
            }

            $results[] = $result;
        }

        @unlink($tmpFile);
        if ($layoutFile) @unlink($layoutFile);

        Flight::jsonHalt([
            "response" => "ok",
            "copies"   => $copies,
            "results"  => $results
        ]);
    }

    /**
     * POST /label/verify
     * Verify and decrypt a label token
     */
    public function verify()
    {
        $token = Flight::request()->data->token ?? null;

        if ($token === null) {
            Flight::jsonHalt([
                "response" => "El campo 'token' es obligatorio"
            ], 400);
        }

        $data = $this->decrypt($token);

        if ($data === false) {
            Flight::jsonHalt([
                "response" => "Token inválido o manipulado"
            ], 400);
        }

        Flight::jsonHalt([
            "response" => $data
        ]);
    }
}
