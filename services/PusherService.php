<?php
/**
 * PusherService - singleton wrapper around pusher/pusher-php-server.
 *
 * Reads credentials from environment (.env loaded by Dotenv in index.php).
 * Keeps a single Pusher client instance per PHP process to avoid
 * recreating the HTTP client on every call.
 */

use GuzzleHttp\Client as GuzzleClient;
use Pusher\Pusher;

class PusherService
{
    private static ?Pusher $instance = null;

    /**
     * Get the shared Pusher client. Returns null if credentials are missing
     * so callers can decide whether to fail or skip.
     */
    public static function getClient(): ?Pusher
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $appId   = $_ENV['PUSHER_APP_ID']   ?? '';
        $key     = $_ENV['PUSHER_KEY']      ?? '';
        $secret  = $_ENV['PUSHER_SECRET']   ?? '';
        $cluster = $_ENV['PUSHER_CLUSTER']  ?? 'us2';

        if (!$appId || !$key || !$secret) {
            return null;
        }

        // Dev-only: allow skipping SSL verification when cacert.pem is not configured
        // (typical on local WAMP/XAMPP). Set PUSHER_VERIFY_SSL=false in .env
        $verifySsl = strtolower($_ENV['PUSHER_VERIFY_SSL'] ?? 'true') !== 'false';

        $guzzle = new GuzzleClient([
            'timeout'         => 10,
            'connect_timeout' => 5,
            'verify'          => $verifySsl,
        ]);

        self::$instance = new Pusher($key, $secret, $appId, [
            'cluster' => $cluster,
            'useTLS'  => true,
        ], $guzzle);

        return self::$instance;
    }

    /**
     * Trigger an event. Returns true on success, false on failure.
     * Never throws — errors are logged to error_log so the caller's
     * request flow is not affected.
     */
    public static function trigger(string $channel, string $event, array $payload): bool
    {
        try {
            $client = self::getClient();
            if ($client === null) {
                error_log('[PusherService] credentials missing, skipping trigger');
                return false;
            }

            $client->trigger($channel, $event, $payload);
            return true;
        } catch (\Throwable $e) {
            error_log('[PusherService] trigger failed: ' . $e->getMessage());
            return false;
        }
    }
}
