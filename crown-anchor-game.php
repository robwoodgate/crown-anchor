<?php

/*
Plugin Name: Crown and Anchor Game
Plugin URI: https://github.com/robwoodgate/crown-anchor
Description: A provably fair Crown and Anchor game integrated with Nostr, Lightning, and Cashu.
Version: 1.0
Author: Rob Woodgate
Author URI: https://www.cogmentis.com
License: GPL-3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.txt
*/

// * No direct access
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// * Try to load the Composer if it exists.
$composer_autoloader = __DIR__.'/vendor/autoload.php';
if (is_readable($composer_autoloader)) {
    require $composer_autoloader;
}

// * Define Plugin Constants
global $wpdb;
define('CAGAME_TABLE', $wpdb->prefix.'crown_anchor_players');
define('CAGAME_PATH', plugin_dir_path(__FILE__));
define('CAGAME_URL', plugin_dir_url(__FILE__));
define('CAGAME_SLUG', plugin_basename(__DIR__));
define('CAGAME_FILE', plugin_basename(__FILE__));
define('CAGAME_VERSION', '1.0');

// * Handle activation tasks
\register_activation_hook(__FILE__, function (): void {
    CrownAnchorGame::activate();
});

// * Instantiate main plugin
require_once CAGAME_PATH.'lib/class-crown-anchor-game.php';
(new CrownAnchorGame())->init();

// * Instantiate Lightning payments
require_once CAGAME_PATH.'lib/class-lightning-payments.php';
(new LightningPayments())->init();

