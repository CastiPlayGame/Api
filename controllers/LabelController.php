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
     * Reverse MD5 hash[:6] back to original number using Python script
     */
    private function reverseChargeHash(string $hashHex): ?int
    {
        $pythonBin = $_ENV['PYTHON_BIN'] ?? 'C:/Python313/python.exe';
        $scriptPath = realpath(__DIR__ . '/../py/md5_reverse.py');

        $cmd = escapeshellarg($pythonBin) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($hashHex) . ' 2>&1';
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return null;
        }

        $result = json_decode(implode("\n", $output), true);
        return ($result['found'] ?? false) ? (int)$result['number'] : null;
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

    /**
     * POST /label/scan
     * Scan a label image with AI vision and broadcast result to Pusher.
     *
     * Request (multipart/form-data):
     *   image   (file, jpeg/png, required, <=10MB)
     *   session (string A-Z0-9, 8-32 chars, required; accepts ?session=... or form field)
     *
     * On success triggers Pusher event 'scan_result' on channel 'session-<SESSION>'.
     * Error responses follow { ok:false, error:"..." } shape and never trigger Pusher.
     */
    public function scan()
    {
        try {
            // ── 1. Validate image upload ────────────────────────────────────
            if (!isset($_FILES['image'])) {
                Flight::jsonHalt(["ok" => false, "error" => "image_missing"], 400);
            }
            $uploadError = (int)$_FILES['image']['error'];
            if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                Flight::jsonHalt(["ok" => false, "error" => "image_too_large"], 413);
            }
            if ($uploadError !== UPLOAD_ERR_OK) {
                Flight::jsonHalt(["ok" => false, "error" => "image_missing"], 400);
            }

            $imageFile = $_FILES['image']['tmp_name'];
            $imageSize = (int)($_FILES['image']['size'] ?? 0);

            // Max 10MB per spec
            if ($imageSize > 10 * 1024 * 1024) {
                @unlink($imageFile);
                Flight::jsonHalt(["ok" => false, "error" => "image_too_large"], 413);
            }

            if (!file_exists($imageFile) || !is_readable($imageFile)) {
                Flight::jsonHalt(["ok" => false, "error" => "image_missing"], 400);
            }

            $mimeType = $_FILES['image']['type'] ?: 'image/jpeg';
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            if (!in_array(strtolower($mimeType), $allowedTypes, true)) {
                @unlink($imageFile);
                Flight::jsonHalt(["ok" => false, "error" => "image_missing"], 400);
            }

            // ── 2. Validate session ─────────────────────────────────────────
            // Accept from query string or multipart field, prefer query string.
            $session = $_GET['session'] ?? ($_POST['session'] ?? '');
            $session = is_string($session) ? trim($session) : '';

            if (!preg_match('/^[A-Z0-9]{8,32}$/', $session)) {
                @unlink($imageFile);
                Flight::jsonHalt(["ok" => false, "error" => "session_invalid"], 400);
            }

            // ── 3. Read image & prepare for AI ──────────────────────────────
            $imageData = file_get_contents($imageFile);
            if ($imageData === false || $imageData === '') {
                @unlink($imageFile);
                Flight::jsonHalt(["ok" => false, "error" => "image_missing"], 400);
            }
            $base64Image = base64_encode($imageData);

            // ── 3b. Decode QR — mandatory, block if not found ───────────────
            $qrData = $this->decodeQrFromImage($imageFile, $mimeType);
            if ($qrData === null || $qrData === '') {
                $this->saveImageLog($imageFile, $mimeType, 'invalid', $session);
                @unlink($imageFile);
                Flight::jsonHalt(["ok" => false, "error" => "qr_not_found"], 422);
            }

            // ── 4. Call AI vision (Groq) ────────────────────────────────────
            $apiKey = $_ENV['GROQ_API_KEY'] ?? '';
            if ($apiKey === '') {
                @unlink($imageFile);
                error_log('[LabelController.scan] GROQ_API_KEY missing');
                Flight::jsonHalt(["ok" => false, "error" => "internal"], 500);
            }

            $prompt = "Analiza esta foto de una etiqueta de producto de inventario.\n"
                . "Extrae EXACTAMENTE estos campos y responde SOLO con JSON válido:\n\n"
                . "{\n"
                . "  \"code\": \"<código de producto tipo GS-NNN o similar>\",\n"
                . "  \"cb\": \"<código de barras único tipo CB-NNNNNN>\",\n"
                . "  \"qty\": <número entero de unidades en el paquete>,\n"
                . "  \"purchase_number\": <número de compra si aparece, null si no>,\n"
                . "  \"description\": \"<descripción breve del producto>\"\n"
                . "}\n\n"
                . "Si no puedes leer la etiqueta con claridad, responde:\n"
                . "{\"error\": \"unreadable\"}\n\n"
                . "NO incluyas texto adicional, solo el JSON.";

            $aiPayload = [
                "model" => "meta-llama/llama-4-scout-17b-16e-instruct",
                "messages" => [[
                    "role" => "user",
                    "content" => [
                        ["type" => "text", "text" => $prompt],
                        [
                            "type" => "image_url",
                            "image_url" => [
                                "url" => sprintf('data:%s;base64,%s', $mimeType, $base64Image)
                            ]
                        ]
                    ]
                ]],
                "temperature" => 0.2,
                "max_completion_tokens" => 512,
                "top_p" => 1,
                "stream" => false
            ];

            $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey
                ],
                CURLOPT_POSTFIELDS => json_encode($aiPayload),
                CURLOPT_TIMEOUT => 25,                  // 25s per spec
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $aiResponse = curl_exec($ch);
            $aiHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $aiError = curl_error($ch);
            curl_close($ch);

            if ($aiError || $aiHttpCode !== 200) {
                @unlink($imageFile);
                error_log('[LabelController.scan] AI call failed: ' . ($aiError ?: "http $aiHttpCode"));
                Flight::jsonHalt(["ok" => false, "error" => "internal"], 500);
            }

            $aiResult = json_decode($aiResponse, true);
            $content  = $aiResult['choices'][0]['message']['content'] ?? '';
            if ($content === '') {
                @unlink($imageFile);
                Flight::jsonHalt(["ok" => false, "error" => "internal"], 500);
            }

            // Extract JSON object from the AI response (may include ``` fences)
            if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
                $jsonStr = $m[0];
            } else {
                $jsonStr = $content;
            }
            $extracted = json_decode($jsonStr, true);

            if (!is_array($extracted) || isset($extracted['error'])) {
                @unlink($imageFile);
                Flight::jsonHalt(["ok" => false, "error" => "unreadable"], 422);
            }

            $code            = $extracted['code'] ?? null;
            $cb              = $extracted['cb']   ?? null;
            $qty             = isset($extracted['qty']) ? (int)$extracted['qty'] : null;
            $aiDescription   = $extracted['description'] ?? null;
            $aiPurchase      = $extracted['purchase_number'] ?? null;

            // ── 5. Lookup item in DB (DB is source of truth for description/uuid) ──
            $item = null;
            if ($code) {
                $query = $this->db->prepare(
                    "SELECT uuid, id, JSON_VALUE(info, '$.desc') AS description
                     FROM items WHERE id = :id LIMIT 1"
                );
                $query->execute([':id' => $code]);
                $item = $query->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            // ── 6. Recover purchase_number from CB-XXXXXX hash (preferred source) ──
            $purchaseNumber = null;
            if ($cb && str_starts_with(strtoupper($cb), 'CB-')) {
                $hashPart = substr(strtoupper($cb), 3);
                $purchaseNumber = $this->reverseChargeHash($hashPart);
            }
            // Fallback to AI's purchase_number if DB lookup failed
            if ($purchaseNumber === null && is_numeric($aiPurchase)) {
                $purchaseNumber = (int)$aiPurchase;
            }

            // ── 7. Build response payload ───────────────────────────────────
            $itemUuid    = $item['uuid'] ?? null;
            $description = $item['description'] ?? $aiDescription ?? '';

            $payload = [
                "ok"              => true,
                "code"            => $code,
                "cb"              => $cb,
                "qty"             => $qty !== null ? (int)$qty : null,
                "purchase_number" => $purchaseNumber,
                "qr_data"         => $qrData,
                "item" => [
                    "uuid"        => $itemUuid,
                    "id"          => $code,
                    "description" => $description,
                ],
            ];

            // ── 8. Clean temp file BEFORE triggering Pusher ─────────────────
            $this->saveImageLog($imageFile, $mimeType, 'valid', $session, $code);
            @unlink($imageFile);

            // ── 9. Trigger Pusher event (never fail the request on error) ───
            PusherService::trigger('session-' . $session, 'scan_result', $payload);

            // ── 10. Respond to mobile ──────────────────────────────────────
            Flight::jsonHalt($payload);

        } catch (\Throwable $e) {
            if (isset($imageFile) && is_string($imageFile)) {
                @unlink($imageFile);
            }
            error_log('[LabelController.scan] unhandled: ' . $e->getMessage());
            Flight::jsonHalt(["ok" => false, "error" => "internal"], 500);
        }
    }

    /**
     * Save a copy of the scanned image to storage/scans/{valid|invalid}/ for audit logging.
     */
    private function saveImageLog(
        string $tmpPath,
        string $mimeType,
        string $bucket,
        string $session = '',
        string $code = ''
    ): void {
        if (!file_exists($tmpPath)) {
            return;
        }
        $ext      = (strtolower($mimeType) === 'image/png') ? 'png' : 'jpg';
        $dir      = realpath(__DIR__ . '/../storage/scans/' . $bucket);
        if (!$dir) {
            return;
        }
        $ts       = date('Ymd_His');
        $suffix   = $code ? '_' . preg_replace('/[^A-Za-z0-9\-]/', '', $code) : '';
        $sesShort = substr($session, 0, 8);
        $filename = $ts . '_' . $sesShort . $suffix . '.' . $ext;
        @copy($tmpPath, $dir . DIRECTORY_SEPARATOR . $filename);
    }

    /**
     * Decode QR code from image using Python script.
     * Passes PHP temp upload file directly — Python uses cv2.imdecode (no extension needed).
     */
    private function decodeQrFromImage(string $imagePath, string $mimeType = 'image/jpeg'): ?string
    {
        $pythonBin  = $_ENV['PYTHON_BIN'] ?? 'C:/Python313/python.exe';
        $scriptPath = realpath(__DIR__ . '/../py/qr_decode.py');
        if (!$scriptPath || !file_exists($imagePath)) {
            return null;
        }

        // Ensure Python can find user-installed packages (cv2, pyzbar) under Apache
        $packages = $_ENV['PYTHON_PACKAGES'] ?? '';
        if ($packages !== '') {
            putenv("PYTHONPATH=$packages");
        }

        // Pass the upload temp file directly — Python uses cv2.imdecode (no extension needed)
        $cmd = escapeshellarg($pythonBin) . ' -W ignore ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($imagePath) . ' 2>NUL';
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        // Find the JSON line (warnings may appear before it)
        foreach (array_reverse($output) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '{') {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    return ($decoded['found'] ?? false) ? $decoded['data'] : null;
                }
            }
        }

        return null;
    }
}
