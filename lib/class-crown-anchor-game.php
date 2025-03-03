<?php

/**
 * "Main" plugin class.
 * Responsible for game, global scripts and methods.
 */
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
use swentel\nostr\Event\Event;

class CrownAnchorGame
{
    public function init()
    {
        add_shortcode('crown_anchor_game', [$this, 'display_game']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_ca_login', [$this, 'ajax_login']);
        add_action('wp_ajax_nopriv_ca_login', [$this, 'ajax_login']);
        add_action('wp_ajax_ca_logout', [$this, 'ajax_logout']);
        add_action('wp_ajax_nopriv_ca_logout', [$this, 'ajax_logout']);
        add_action('wp_ajax_ca_play_game', [$this, 'ajax_play_game']);
        add_action('wp_ajax_nopriv_ca_play_game', [$this, 'ajax_play_game']);
    }

    // Database setup
    public static function activate()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = 'CREATE TABLE IF NOT EXISTS '.CAGAME_TABLE." (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        pubkey varchar(255) NOT NULL,
        credits mediumint(9) DEFAULT '0' NOT NULL,
        current_result_hash varchar(64) DEFAULT '' NOT NULL,
        current_rolls varchar(10) DEFAULT '' NOT NULL,
        current_randomhash varchar(32) DEFAULT '' NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY pubkey (pubkey)
    ) {$charset_collate};";

        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // Enqueue scripts
    public function enqueue_assets()
    {
        wp_register_script('ca-game-js', CAGAME_URL.'js/crown-anchor-game.js', ['jquery'], CAGAME_VERSION, true);
        wp_register_script('nostr-login', 'https://www.unpkg.com/nostr-login@latest/dist/unpkg.js', [], 'latest', true);
        wp_register_script('confetti', 'https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js', [], '1.9.3', false); // NB: head
        wp_register_style('ca-game-css', CAGAME_URL.'css/crown-anchor-game.css', [], CAGAME_VERSION);
        wp_localize_script('ca-game-js', 'caAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ca_game_nonce'),
            'plugin_url' => CAGAME_URL,
        ]);

        // Add data-* attributes using script_loader_tag filter
        add_filter('script_loader_tag', 'adjust_attributes', 10, 3);
        function adjust_attributes($tag, $handle, $src) {
            // nostr-login script
            if ($handle === 'nostr-login') {
                // Add data-* attributes
                $attributes = [
                    'data-methods' => 'connect,extension',
                    // 'data-start-screen' => 'welcome-login',
                    'data-theme' => 'ocean',
                    'data-dark-mode' => 'true',
                    'data-title' => 'Login to Crown and Anchor',
                    'data-description' => 'A provably fair Crown and Anchor game integrated with Nostr, Lightning, and Cashu.'
                ];

                $attr_string = '';
                foreach ($attributes as $key => $value) {
                    $attr_string .= " $key=\"" . esc_attr($value) . "\"";
                }

                // Replace the original script tag with one containing data-* attributes
                $tag = str_replace('<script ', "<script {$attr_string} ", $tag);
            }
            return $tag;
        }
    }

    // Shortcode to display the game
    public function display_game() {
        // Prevent page caching by defining DONOTCACHEPAGE
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        // Add no-cache headers to prevent browser/CDN caching
        nocache_headers();

        // Enqueue scripts and styles
        wp_enqueue_script('ca-game-js');
        wp_enqueue_script('nostr-login');
        wp_enqueue_script('confetti');
        wp_enqueue_style('ca-game-css');

        return '<div id="crown-anchor-game"></div>';
    }

    // Utility function to generate dice rolls and result hash
    public function ca_generate_game_data()
    {
        $rolls = [];
        for ($i = 0; $i < 3; ++$i) {
            $rolls[] = random_int(1, 6); // 1=spade, 2=anchor, 3=club, 4=heart, 5=crown, 6=diamond
        }
        $randomhash = wp_generate_password(32, false);
        $symbolNames = [1 => 'spade', 2 => 'anchor', 3 => 'club', 4 => 'heart', 5 => 'crown', 6 => 'diamond'];
        $rollNames = array_map(function ($roll) use ($symbolNames) { return $symbolNames[$roll]; }, $rolls);
        $result_hash = hash('sha256', implode('-', $rollNames).'-'.$randomhash);

        return [
            'rolls' => implode(',', $rolls),
            'randomhash' => $randomhash,
            'result_hash' => $result_hash,
        ];
    }

    // Handle Nostr login
    public function ajax_login()
    {
        // Sanitize and verify nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'ca_game_nonce')) {
            wp_send_json_error('Nonce verification failed');
        }

        // Sanitize input data
        $event = sanitize_text_field(wp_unslash($_POST['event'] ?? ''));
        $event = base64_decode($event); // now a json encoded string

        // Verify event signature and format
        try {
            $nip98 = new Event();
            if (!$nip98->verify($event)) {
                wp_send_json_error('Invalid authtoken.');
            }
        } catch (Throwable $e) {
            wp_send_json_error('Sorry, Nostr Login is currently disabled.');
        }

        // Do NIP98 specific authtoken validation checks
        // @see https://github.com/nostr-protocol/nips/blob/master/98.md
        $event = json_decode($event);
        if (JSON_ERROR_NONE !== json_last_error()) {
            wp_send_json_error('Invalid authtoken: '.json_last_error_msg());
        }
        $valid = ('27235' == $event->kind) ? true : false;              // NIP98 event
        $valid = (time() - $event->created_at <= 60) ? $valid : false;  // <60 secs old
        $tags = array_column($event->tags, 1, 0);                       // Expected Tags
        $valid = (admin_url('admin-ajax.php') == $tags['u']) ? $valid : false;
        $valid = ('POST' == $tags['method']) ? $valid : false;
        if (!$valid) {
            wp_send_json_error('Authorisation is invalid or expired.');
        }

        // Get player
        global $wpdb;
        $player = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.CAGAME_TABLE.' WHERE pubkey = %s', $event->pubkey));
        if (!$player) {
            $game_data = $this->ca_generate_game_data();
            $welcome_credits = (defined('CAGAME_WELCOME_CREDITS'))
                ? (int) CAGAME_WELCOME_CREDITS : 0;
            $wpdb->insert(CAGAME_TABLE, [
                'pubkey' => $event->pubkey,
                'credits' => $welcome_credits, // Starting credits
                'current_result_hash' => $game_data['result_hash'],
                'current_rolls' => $game_data['rolls'],
                'current_randomhash' => $game_data['randomhash'],
            ]);
            $player = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.CAGAME_TABLE.' WHERE pubkey = %s', $event->pubkey));
        }
        wp_send_json_success([
            'credits' => (int) $player->credits,
            'result_hash' => $player->current_result_hash,
        ]);
    }

    // Handle logout
    public function ajax_logout()
    {
        // Sanitize and verify nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'ca_game_nonce')) {
            wp_send_json_error('Nonce verification failed');
        }

        // Set new game data to invalidate current result hash
        $pubkey = sanitize_text_field($_POST['pubkey']);
        global $wpdb;
        $new_game_data = $this->ca_generate_game_data();
        $wpdb->update(CAGAME_TABLE, [
            'current_result_hash' => $new_game_data['result_hash'],
            'current_rolls' => $new_game_data['rolls'],
            'current_randomhash' => $new_game_data['randomhash'],
        ], ['pubkey' => $pubkey]);
        wp_send_json_success(); // nothing to say
    }

    // Handle game play
    public function ajax_play_game()
    {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'ca_game_nonce')) {
            wp_send_json_error('Nonce verification failed');
        }

        $pubkey = sanitize_text_field($_POST['pubkey']);
        $hash = sanitize_text_field($_POST['hash']);
        $bets = array_map('intval', (array) $_POST['bets']); // [1=>bet, 2=>bet, ...]
        $total_cost = array_sum($bets);
        if ($total_cost <= 0) {
            wp_send_json_error('No bets placed');
        }
        global $wpdb;
        $player = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM '.CAGAME_TABLE.' WHERE pubkey = %s',
            $pubkey
        ));
        if (!$player || $player->current_result_hash != $hash) {
            wp_send_json_error('Invalid session. Please reload the game.');
        }
        if ($player->credits < $total_cost) {
            wp_send_json_error('Insufficient credits');
        }
        $rolls = explode(',', $player->current_rolls);
        $randomhash = $player->current_randomhash;
        $symbol_counts = array_count_values($rolls);
        $winnings = 0;
        foreach ($bets as $symbol => $bet) {
            if ($bet > 0 && isset($symbol_counts[$symbol])) {
                $winnings += ($symbol_counts[$symbol] + 1) * $bet; // Payout = stake + (matches * stake)
            }
        }
        $new_credits = $player->credits - $total_cost + $winnings;
        $new_game_data = $this->ca_generate_game_data();
        $wpdb->update(CAGAME_TABLE, [
            'credits' => $new_credits,
            'current_result_hash' => $new_game_data['result_hash'],
            'current_rolls' => $new_game_data['rolls'],
            'current_randomhash' => $new_game_data['randomhash'],
        ], ['pubkey' => $pubkey]);
        wp_send_json_success([
            'rolls' => $rolls,
            'randomhash' => $randomhash,
            'stake' => $total_cost,
            'winnings' => $winnings,
            'credits' => $new_credits,
            'new_result_hash' => $new_game_data['result_hash'],
        ]);
    }
}
