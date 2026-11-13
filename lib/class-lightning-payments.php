<?php

/**
 * Responsible for lightning payments via the Paid in Sats API.
 *
 * @see https://paidinsats.com
 */
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
use swentel\nostr\Key\Key;

class LightningPayments
{
    private const SATS_PER_CREDIT = 10;
    private const MIN_SATS = 100; // the smallest payment Paid in Sats takes

    public function init()
    {
        // Checkout and raise invoice
        add_action('wp_ajax_ca_create_invoice', [$this, 'ajax_create_invoice']);
        add_action('wp_ajax_nopriv_ca_create_invoice', [$this, 'ajax_create_invoice']);
        // Check payment status and update credits
        add_action('wp_ajax_ca_check_payment', [$this, 'ajax_verify_payment']);
        add_action('wp_ajax_nopriv_ca_check_payment', [$this, 'ajax_verify_payment']);
    }

    /**
     * Creates a lightning invoice for credits, via Paid in Sats.
     * Without an API key, payments are off and the top-up is free.
     *
     * @return JSON {"amount":100,"token":"abc123...","payment_request":"lnbc1...","payment_hash":"..."}
     */
    public function ajax_create_invoice()
    {
        // Sanitize and verify nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'ca_game_nonce')) {
            wp_send_json_error(['message' => 'Nonce verification failed']);
        }

        // Validate pubkey
        $pubkey = $this->sanitize_pubkey($_POST['pubkey'] ?? ''); // now hex
        if (empty($pubkey)) {
            wp_send_json_error(['message' => 'Invalid public key']);
        }

        if (!defined('CAGAME_PIS_API_KEY') || empty(CAGAME_PIS_API_KEY)) {
            $credits = $this->add_credits($pubkey, 100);
            if (null === $credits) {
                wp_send_json_error(['message' => 'Player not found.']);
            }
            wp_send_json_success([
                'credits' => $credits,
                'message' => 'Lightning payments unavailable. Added 100 credits instead.',
            ]);
        }

        // 1 credit = 10 sats
        $credits = intdiv(max(0, (int) ($_POST['amount'] ?? 0)), self::SATS_PER_CREDIT);
        if ($credits * self::SATS_PER_CREDIT < self::MIN_SATS) {
            wp_send_json_error(['message' => 'Please deposit at least 100 sats for 10 credits']);
        }

        $payment = $this->paidinsats('POST', '/api/v1/payments', [
            'amount' => $credits * self::SATS_PER_CREDIT,
            'currency' => 'SAT',
            'reference' => 'Crown & Anchor credits',
            'external_reference' => 'cagame:'.$pubkey,
            'memo' => "Crown & Anchor: {$credits} credits",
        ]);
        if (empty($payment['payment_request'])) {
            wp_send_json_error(['message' => 'There was a problem creating the invoice. Please contact us']);
        }

        wp_send_json_success([
            'amount' => (int) $payment['total_sats'],
            'token' => $payment['id'],
            'payment_request' => $payment['payment_request'],
            'payment_hash' => $payment['payment_hash'],
        ]);
    }

    /**
     * Polled by the game: credits the player once their invoice is paid.
     */
    public function ajax_verify_payment()
    {
        // Sanitize and verify nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'ca_game_nonce')) {
            wp_send_json_error(['message' => 'Nonce verification failed']);
        }

        // Validate pubkey
        $pubkey = $this->sanitize_pubkey($_POST['pubkey'] ?? ''); // now hex
        if (empty($pubkey)) {
            wp_send_json_error(['message' => 'Invalid public key']);
        }

        // Validate token (the payment id)
        $token = sanitize_text_field($_POST['token'] ?? '');
        if (!preg_match('/^[0-9a-f]{16,64}$/', $token)) {
            wp_send_json_error(['message' => 'Invalid token.']);
        }

        $payment = $this->paidinsats('GET', '/api/v1/payments/'.$token);
        $state = $payment['state'] ?? '';
        if ('pending' === $state) {
            wp_send_json_success(['settled' => false]); // not paid yet, but ok to wait
        }
        if ('paid' !== $state || ($payment['external_reference'] ?? '') !== 'cagame:'.$pubkey) {
            wp_send_json_error(['message' => 'Invoice not found or expired.']);
        }
        // Only a recent payment is credited, so its marker need not be kept forever
        if (time() - (int) strtotime((string) ($payment['paid_at'] ?? '')) > DAY_IN_SECONDS) {
            wp_send_json_error(['message' => 'This payment is too old to credit. Please contact us']);
        }

        // The unique option name makes this insert succeed once per payment
        global $wpdb;
        $paid_key = 'cagame_paid_'.$token;
        $first = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $paid_key,
            (string) time()
        ));
        if (1 !== $first) {
            wp_send_json_error(['message' => 'This payment has already been credited.']);
        }
        $credits = $this->add_credits($pubkey, intdiv((int) $payment['total_sats'], self::SATS_PER_CREDIT));
        if (null === $credits) {
            $wpdb->delete($wpdb->options, ['option_name' => $paid_key]);
            wp_send_json_error(['message' => 'Player not found.']);
        }
        // Markers older than the crediting window can never match again
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
            $wpdb->esc_like('cagame_paid_').'%',
            time() - 2 * DAY_IN_SECONDS
        ));

        wp_send_json_success(['credits' => $credits]);
    }

    /**
     * Adds credits to a player in one statement.
     *
     * @return null|int the new balance, null if there is no such player
     */
    private function add_credits(string $pubkey, int $credits): ?int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE '.CAGAME_TABLE.' SET credits = credits + %d WHERE pubkey = %s',
            $credits,
            $pubkey
        ));
        $balance = $wpdb->get_var($wpdb->prepare('SELECT credits FROM '.CAGAME_TABLE.' WHERE pubkey = %s', $pubkey));

        return null === $balance ? null : (int) $balance;
    }

    /**
     * Calls the Paid in Sats API with the key from wp-config.
     *
     * @return null|array decoded response, null on any failure
     */
    private function paidinsats(string $method, string $path, ?array $body = null): ?array
    {
        $base = defined('CAGAME_PIS_URL') ? CAGAME_PIS_URL : 'https://paidinsats.com';
        $response = wp_remote_request($base.$path, [
            'method' => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer '.CAGAME_PIS_API_KEY,
                'Content-Type' => 'application/json',
            ],
            'body' => null === $body ? null : wp_json_encode($body),
        ]);
        $code = wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || $code < 200 || $code > 299) {
            $why = is_wp_error($response) ? $response->get_error_message() : $code.' '.wp_remote_retrieve_body($response);
            error_log("crown-anchor: paidinsats {$method} {$path} failed: {$why}");

            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : null;
    }

    private function sanitize_pubkey($hexpub)
    {
        try {
            $key = new Key();

            $value = $key->convertPublicKeyToBech32($hexpub);
            if (empty($value) || 0 !== strpos($value, 'npub')) {
                return '';
            }
            $hex = $key->convertToHex($value);
            if (!ctype_xdigit($hex) || 64 !== strlen($hex)) {
                return ''; // Ensure it's exactly 64 hex characters
            }

            return (string) $hex;
        } catch (Exception $e) {
            error_log($e->getMessage());

            return '';
        }
    }
}
