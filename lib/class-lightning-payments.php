<?php

/**
 * Responsible for lightning payments via Bitcoin and Lightning Publisher.
 *
 * @see https://github.com/getAlby/lightning-publisher-wordpress
 */
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
use Firebase\JWT;
use swentel\nostr\Key\Key;

class LightningPayments
{
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
     * Creates a lightning invoice.
     *
     * @return JSON Lightning invoice
     *
     * {
     *   amount":65000,
     *   "token":"abc123...",
     *   "payment_request":"lnbc1..."
     * }
     */
    public function ajax_create_invoice()
    {
        // Sanitize and verify nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'ca_game_nonce')) {
            wp_send_json_error(['message' => 'Nonce verification failed']);
        }

        // Sanitize input
        $amount = intval($_POST['amount']);
        $comment = sanitize_text_field(wp_unslash($_POST['comment'] ?? ''));
        $pubkey = $this->sanitize_pubkey($_POST['pubkey'] ?? ''); // now hex

        // Validate pubkey
        if (empty($pubkey)) {
            wp_send_json_error(['message' => 'Invalid public key']);
        }

        // Check if BLN Publisher is active and configured
        $bln_active = defined('BLN_PUBLISHER_PAYWALL_JWT_KEY')
        && !empty(BLN_PUBLISHER_PAYWALL_JWT_KEY)
        && is_plugin_active('bitcoin-lightning-publisher/bln-publisher.php');

        if ($bln_active) {
            // Prepare invoice request payload
            $payload = [
                'amount' => $amount,
                'currency' => 'btc',
                'memo' => $comment,
            ];

            // Use WP REST API internally
            // @see https://wpscholar.com/blog/internal-wp-rest-api-calls/
            // We catch exceptions as the endpoint contacts an external LN server
            try {
                $request = new WP_REST_Request('POST', '/lnp-alby/v1/invoices');
                $request->set_body_params($payload);
                $response = rest_do_request($request);
                if ($response->is_error()) {
                    wp_send_json_error(['message' => $response->get_error_message()]);
                }
                $server = rest_get_server();
                $data = $server->response_to_data($response, false); // array
            } catch (ClientException $e) {
                $response = $e->getResponse();
                error_log(__METHOD__.' - Error: '.$response->getBody()->getContents());
                wp_send_json_error(['message' => 'There was a problem creating the invoice. Please contact us']);
            } catch (Exception $e) {
                error_log(__METHOD__.' - Error: '.$e->getMessage());
                wp_send_json_error(['message' => 'There was a problem creating the invoice. Please contact us']);
            }

            // Decode the $data['token'] to get payment hash (r_hash), as this is
            // a primary index in the lightning publisher payments table
            // Token internal structure is:
            // [
            //     "post_id" => "0",
            //     "amount" => "100",
            //     "invoice_id" => "ceb1b642b...",
            //     "r_hash" => "ceb1b642b...",
            //     "exp" => "1740755651"
            // ]
            try {
                $jwt = JWT\JWT::decode($data['token'], new JWT\Key(BLN_PUBLISHER_PAYWALL_JWT_KEY, BLN_PUBLISHER_PAYWALL_JWT_ALGORITHM));
                // error_log(print_r($jwt, true));
            } catch (Exception $e) {
                error_log(__METHOD__.' - Error: '.$e->getMessage());
                wp_send_json_error(['message' => 'There was a problem creating the invoice. Please contact us']);
            }

            // Tweak the data
            unset($data['post_id']); // we don't use post_ids
            $data['payment_hash'] = $jwt->{'r_hash'}; // send payment hash

            // BLN Publisher doesn't save comment, so lets save the pubkey there
            // initially for ease of reference. We will adjust this after payment
            // to reflect delivery of credits to user.
            global $wpdb;
            $wpdb->update($wpdb->prefix.'lightning_publisher_payments', [
                'comment' => $pubkey,
            ], ['payment_hash' => $jwt->{'r_hash'}]);

            // Return the invoice data
            wp_send_json_success($data);
        } else {
            // BLN not active - add 100 credits
            global $wpdb;
            $player = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.CAGAME_TABLE.' WHERE pubkey = %s', $pubkey));
            if (!$player) {
                wp_send_json_error('Player not found.');
            }
            $new_credits = $player->credits + 100;
            $wpdb->update(CAGAME_TABLE, ['credits' => $new_credits], ['pubkey' => $pubkey]);
            wp_send_json_success([
                'credits' => $new_credits,
                'message' => 'Lightning payments unavailable. Added 100 credits instead.',
            ]);
        }
    }

    // Ajax Handler: Check Lightning Payment
    public function ajax_verify_payment()
    {
        // Sanitize and verify nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'ca_game_nonce')) {
            wp_send_json_error('Nonce verification failed');
        }

        // Validate pubkey
        $pubkey = $this->sanitize_pubkey($_POST['pubkey'] ?? ''); // now hex
        if (empty($pubkey)) {
            wp_send_json_error(['message' => 'Invalid public key']);
        }

        // Validate token
        $token = sanitize_text_field($_POST['token'] ?? '');
        if (!$token) {
            wp_send_json_error(['message' => __('Invalid token.', 'nostrly')]);
        }

        // Check invoice payment status
        // We can't use WP REST API internally as the endpoint uses wp_send_json
        // and this terminates script execution immediately
        try {
            $api_url = get_rest_url().'lnp-alby/v1/invoices/verify';
            $response = wp_remote_post($api_url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode(['token' => $token]),
                'data_format' => 'body',
            ]);
        } catch (Exception $e) {
            error_log($e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        // Check for errors in the API response
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);

            return;
        }

        // Check invoice payment status
        // Expected: 200 (paid), 402 (not paid), or 404 (not found)
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);
        if (empty($body['settled']) && 200 != $code) {
            if (402 == $code) {
                // not paid yet, but ok to wait
                wp_send_json_success($body);
            }
            // Bad news
            wp_send_json_error(['message' => __('Invoice not found or expired.', 'nostrly')]);
        }

        // Decode the token to get payment hash (r_hash) and amount
        try {
            $jwt = JWT\JWT::decode($token, new JWT\Key(BLN_PUBLISHER_PAYWALL_JWT_KEY, BLN_PUBLISHER_PAYWALL_JWT_ALGORITHM));
            // error_log(print_r($jwt, true));
        } catch (Exception $e) {
            error_log(__METHOD__.' - Error: '.$e->getMessage());
            wp_send_json_error(['message' => 'There was a problem verifying the invoice. Please contact us']);
        }

        // Check if already credited
        global $wpdb;
        $payment_hash = $jwt->{'r_hash'};
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT comment FROM {$wpdb->prefix}lightning_publisher_payments WHERE payment_hash = %s",
            $payment_hash
        ));
        if ($payment) {
            if ($payment->comment !== $pubkey) { // If comment != pubkey, it's been processed
                wp_send_json_error(['message' => 'This payment has already been credited.']);
            }
        } else {
            wp_send_json_error(['message' => 'Payment record not found.']);
        }

        // Ok, update player credits (1 credit = 10 sats)
        $player = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.CAGAME_TABLE.' WHERE pubkey = %s', $pubkey));
        if (!$player) {
            wp_send_json_error('Player not found.');
        }
        $credits = (int) floor($jwt->{'amount'} / 10);
        $new_credits = $player->credits + $credits;
        $wpdb->update(CAGAME_TABLE, ['credits' => $new_credits], ['pubkey' => $pubkey]);

        // Mark payment as credited
        $wpdb->update(
            $wpdb->prefix.'lightning_publisher_payments',
            ['comment' => 'CREDITED:'.$pubkey],
            ['payment_hash' => $payment_hash]
        );

        wp_send_json_success(['credits' => $new_credits]);
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
