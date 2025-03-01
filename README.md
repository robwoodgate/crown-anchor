# Crown and Anchor Game

A provably fair Crown and Anchor game integrated with Nostr, Lightning, and Cashu.

The game uses Nostr authentication for user accounts, to keep track of game credits.

Credits can be topped up via Lightning payment or Cashu ecash.


## Aims

The aim of this project is to demonstrate:

-   A method to ensure games of chance are provably fair
-   How a website / game can use Nostr for authentication
-   How GetAlby's powerful [Bitcoin Lightning Publisher for WordPress](https://github.com/getAlby/lightning-publisher-wordpress/) can be used as a payment engine
-   How [Nostly Cashu Redeem](https://www.nostrly.com/cashu-redeem/) can be used to accept one-click Lightning payments using [Cashu ecash](https://cashu.space)


## Provably Fair

Each game is randomly generated before play, and the SHA256 result hash shared before roll.

The secret hash input comprises the 3 dice roll outcomes and a random string.

```
eg: "club-spade-anchor-ZuTOUPN60N5kPSQg9881GYKrLq7ICrpb"
```

The game is then played, and the secret hash input is shared.

This allows the player to confirm the result hash matches the one calculated before play.

The full hashes are compared, though only the first 24 characters are shown for better UX.


## Install

The game has been prepared as a WordPress plugin.

Clone the repository and install the dependency using [Composer](https://getcomposer.org/)

```bash
git clone https://github.com/robwoodgate/crown-anchor.git
cd crown-anchor
composer install # (maybe you need to add `--ignore-platform-reqs` if it asks you to update PHP)
```

To build a .zip file of the WordPress plugin run:

```bash
./build.sh # this builds a `crown-anchor.zip`
```

Then upload and activate the plugin through the WordPress Plugin admin panel.

You can then add the game to a page using the following shortcode.

```
[crown_anchor_game]
```


## Requirements

Your WordPress server MUST have the PHP-GMP extension enabled, which is required for secure cryptographic operations.

Your WordPress site MUST use SSL (https://) for your browser to be able to calculate and confirm the result hashes.

If you wish to take payments, you need to install and configure the [Bitcoin Lightning Publisher for WordPress plugin](https://github.com/getAlby/lightning-publisher-wordpress/) for payment integation.

### Caching Considerations
The `[crown_anchor_game]` shortcode page uses dynamic nonces for AJAX requests. To prevent caching issues, the plugin sets `DONOTCACHEPAGE` and no-cache headers. Ensure your caching plugin (e.g., WP Rocket, W3 Total Cache) respects `DONOTCACHEPAGE`, or exclude the shortcode page URL (e.g., `/game-page/`) from caching.

### Server-Side Caching
If your server uses caching (e.g., Nginx, Varnish), exclude the shortcode page URL (e.g., `/game-page/`) from caching to ensure the nonce remains fresh.


## NO Payouts (Amusement only)

This plugin does not allow payouts to avoid falling under gambling / gaming laws.

Any credits purchased are for amusement only, there are no money or moneys worth prizes.

