<?php
/**
 * Scheduled task: Send push notifications for due cards
 *
 * @package    mod_flashcards
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_flashcards\task;

defined('MOODLE_INTERNAL') || die();

class send_push_notifications extends \core\task\scheduled_task {

    /**
     * Get task name
     */
    public function get_name() {
        return get_string('task_send_push_notifications', 'mod_flashcards');
    }

    /**
     * Execute task: send push notifications to users with due cards
     */
    public function execute() {
        global $DB;

        $config = get_config('mod_flashcards');

        // Check if push notifications are enabled
        if (empty($config->push_enabled)) {
            mtrace('Push notifications are disabled');
            return;
        }

        // Check VAPID keys
        $vapidpublic = trim($config->vapid_public_key ?? '');
        $vapidprivate = trim($config->vapid_private_key ?? '');
        $vapidsubject = trim($config->vapid_subject ?? '');

        if ($vapidpublic === '' || $vapidprivate === '' || $vapidsubject === '') {
            mtrace('VAPID keys not configured');
            return;
        }

        mtrace('Starting push notifications task...');

        // Get all enabled subscriptions
        $subscriptions = $DB->get_records('flashcards_push_subs', ['enabled' => 1]);

        if (empty($subscriptions)) {
            mtrace('No active push subscriptions found');
            return;
        }

        mtrace('Found ' . count($subscriptions) . ' active subscriptions');

        // Get current timestamp (start of today)
        $today = strtotime('today');

        // Group subscriptions by user
        $usersubscriptions = [];
        foreach ($subscriptions as $sub) {
            if (!isset($usersubscriptions[$sub->userid])) {
                $usersubscriptions[$sub->userid] = [];
            }
            $usersubscriptions[$sub->userid][] = $sub;
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($usersubscriptions as $userid => $usersubs) {
            // Count due cards for this user
            $duecount = $DB->count_records_select(
                'flashcards_progress',
                'userid = :userid AND due <= :today AND hidden = 0',
                ['userid' => $userid, 'today' => $today]
            );

            if ($duecount === 0) {
                $skipped++;
                continue;
            }

            // Get user's preferred language from first subscription
            $lang = $usersubs[0]->lang ?? 'en';

            // Generate notification message in user's language
            $message = $this->get_notification_message($duecount, $lang);

            // Send to all user's devices
            foreach ($usersubs as $sub) {
                $result = $this->send_push($sub, $message, $duecount, $vapidpublic, $vapidprivate, $vapidsubject);

                if ($result === true) {
                    $sent++;
                } else if ($result === 'expired') {
                    // Subscription expired, remove it
                    $DB->delete_records('flashcards_push_subs', ['id' => $sub->id]);
                    mtrace("  Removed expired subscription ID {$sub->id}");
                    $failed++;
                } else {
                    $failed++;
                }
            }
        }

        mtrace("Push notifications complete: sent={$sent}, failed={$failed}, skipped={$skipped}");
    }

    /**
     * Get notification message in user's language
     */
    private function get_notification_message($duecount, $lang) {
        $messages = [
            'en' => [
                'single' => 'You have 1 card to review today',
                'plural' => 'You have %d cards to review today',
            ],
            'uk' => [
                'single' => 'У вас 1 картка на сьогодні',
                'plural' => 'У вас %d карток на сьогодні',
            ],
            'ru' => [
                'single' => 'У вас 1 карточка на сегодня',
                'plural' => 'У вас %d карточек на сегодня',
            ],
            'de' => [
                'single' => 'Sie haben 1 Karte für heute',
                'plural' => 'Sie haben %d Karten für heute',
            ],
            'fr' => [
                'single' => 'Vous avez 1 carte à réviser aujourd\'hui',
                'plural' => 'Vous avez %d cartes à réviser aujourd\'hui',
            ],
            'es' => [
                'single' => 'Tienes 1 tarjeta para revisar hoy',
                'plural' => 'Tienes %d tarjetas para revisar hoy',
            ],
            'pl' => [
                'single' => 'Masz 1 kartę do powtórzenia',
                'plural' => 'Masz %d kart do powtórzenia',
            ],
            'it' => [
                'single' => 'Hai 1 carta da rivedere oggi',
                'plural' => 'Hai %d carte da rivedere oggi',
            ],
        ];

        // Fallback to English if language not found
        $langmessages = $messages[$lang] ?? $messages['en'];

        if ($duecount === 1) {
            return $langmessages['single'];
        } else {
            return sprintf($langmessages['plural'], $duecount);
        }
    }

    /**
     * Send push notification to a subscription
     */
    private function send_push($subscription, $message, $duecount, $vapidpublic, $vapidprivate, $vapidsubject) {
        // Payload for notification
        $payload = json_encode([
            'title' => 'Flashcards | ABC norsk',
            'body' => $message,
            'url' => '/mod/flashcards/my/index.php',
            'dueCount' => $duecount,
            'tag' => 'due-cards-' . date('Y-m-d'),
        ]);

        // Parse endpoint to get audience
        $endpoint = $subscription->endpoint;
        $parsedurl = parse_url($endpoint);
        $audience = $parsedurl['scheme'] . '://' . $parsedurl['host'];

        // Create JWT for VAPID
        $header = json_encode(['typ' => 'JWT', 'alg' => 'ES256']);
        $jwtpayload = json_encode([
            'aud' => $audience,
            'exp' => time() + 86400,
            'sub' => $vapidsubject,
        ]);

        $headerpayload = $this->base64url_encode($header) . '.' . $this->base64url_encode($jwtpayload);

        // Sign with private key
        $privatekey = openssl_pkey_get_private($this->pem_from_base64($vapidprivate));
        if (!$privatekey) {
            mtrace("  Failed to load private key");
            return false;
        }

        openssl_sign($headerpayload, $signature, $privatekey, OPENSSL_ALGO_SHA256);

        // Convert signature from DER to raw format
        $signature = $this->der_to_raw($signature);

        $jwt = $headerpayload . '.' . $this->base64url_encode($signature);

        // Encrypt payload
        $encrypted = $this->encrypt_payload(
            $payload,
            $subscription->p256dh,
            $subscription->auth
        );

        if ($encrypted === false) {
            mtrace("  Failed to encrypt payload for subscription ID {$subscription->id}");
            return false;
        }

        // Send request
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encrypted['ciphertext'],
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'Authorization: vapid t=' . $jwt . ', k=' . $vapidpublic,
                'TTL: 86400',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode >= 200 && $httpcode < 300) {
            return true;
        } else if ($httpcode === 404 || $httpcode === 410) {
            // Subscription expired
            return 'expired';
        } else {
            mtrace("  Push failed for subscription ID {$subscription->id}: HTTP {$httpcode}");
            return false;
        }
    }

    /**
     * Encrypt payload using Web Push encryption
     */
    private function encrypt_payload($payload, $userPublicKey, $userAuth) {
        // Generate local key pair
        $localkey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (!$localkey) {
            return false;
        }

        $localdetails = openssl_pkey_get_details($localkey);
        $localpublic = $this->point_to_uncompressed($localdetails['ec']['x'], $localdetails['ec']['y']);

        // Decode user's public key
        $userpublicdecoded = $this->base64url_decode($userPublicKey);
        $userauthdecoded = $this->base64url_decode($userAuth);

        // Compute shared secret using ECDH
        $sharedkey = $this->compute_ecdh_secret($localkey, $userpublicdecoded);
        if (!$sharedkey) {
            return false;
        }

        // Derive encryption key
        $salt = random_bytes(16);
        $context = "P-256\x00" .
                   pack('n', strlen($userpublicdecoded)) . $userpublicdecoded .
                   pack('n', strlen($localpublic)) . $localpublic;

        $prk = hash_hmac('sha256', $sharedkey, $userauthdecoded, true);
        $info = "Content-Encoding: aes128gcm\x00" . $context;
        $key = $this->hkdf_expand($prk, $info, 16);

        $nonceinfo = "Content-Encoding: nonce\x00" . $context;
        $nonce = $this->hkdf_expand($prk, $nonceinfo, 12);

        // Encrypt
        $padded = $payload . "\x02";
        $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        if ($ciphertext === false) {
            return false;
        }

        // Build aes128gcm header
        $header = $salt . pack('N', 4096) . chr(strlen($localpublic)) . $localpublic;

        return [
            'ciphertext' => $header . $ciphertext . $tag,
        ];
    }

    /**
     * Helper functions for crypto operations
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    private function pem_from_base64($base64key) {
        return "-----BEGIN EC PRIVATE KEY-----\n" .
               chunk_split(base64_encode($this->base64url_decode($base64key)), 64, "\n") .
               "-----END EC PRIVATE KEY-----";
    }

    private function der_to_raw($der) {
        // Extract r and s from DER format
        $offset = 3;
        $rlen = ord($der[$offset]);
        $offset++;
        $r = substr($der, $offset, $rlen);
        $offset += $rlen + 1;
        $slen = ord($der[$offset]);
        $offset++;
        $s = substr($der, $offset, $slen);

        // Pad to 32 bytes
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    private function point_to_uncompressed($x, $y) {
        return "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT) . str_pad($y, 32, "\x00", STR_PAD_LEFT);
    }

    private function compute_ecdh_secret($privatekey, $publickey) {
        // Extract x and y from uncompressed point
        if (strlen($publickey) !== 65 || $publickey[0] !== "\x04") {
            return false;
        }

        $x = substr($publickey, 1, 32);
        $y = substr($publickey, 33, 32);

        // Create public key resource
        $pubkeydetails = [
            'curve_name' => 'prime256v1',
            'x' => $x,
            'y' => $y,
        ];

        $pubkey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (!$pubkey) {
            return false;
        }

        // Use openssl_pkey_derive for ECDH
        $secret = '';
        if (!openssl_pkey_derive($secret, $privatekey, $pubkey)) {
            return false;
        }

        return $secret;
    }

    private function hkdf_expand($prk, $info, $length) {
        $t = '';
        $output = '';
        for ($i = 1; strlen($output) < $length; $i++) {
            $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $output .= $t;
        }
        return substr($output, 0, $length);
    }
}
