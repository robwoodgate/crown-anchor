jQuery(function($) {
    var pubkey = null;
    var credits = 0;
    var resultHash = '';
    var bets = {1: 0, 2: 0, 3: 0, 4: 0, 5: 0, 6: 0};
    var symbolNames = {1: 'spade', 2: 'anchor', 3: 'club', 4: 'heart', 5: 'crown', 6: 'diamond'};
    var intervalId;
    // Initialize the game with login
    function initGame() {
        displayGameBoard();
        $('#login-btn').on('click', doNostrLogin);
        $('.ca-status, .ca-button-container').hide();
    }

    // Handle nostr-login events
    function doNostrLogin() {
        document.dispatchEvent(new CustomEvent('nlLaunch', { detail: 'welcome' }));
    }
    document.addEventListener('nlAuth', (e) => {
      // type is login, signup or logout
      if (e.detail.type === 'login' || e.detail.type === 'signup') {
        login();
      } else {
        logout();
      }
    });

    // Handle Nostr login
    async function login() {
        if (window.nostr) {
            try {
                // HTTP Auth event
                const event = await window.nostr.signEvent({
                    kind: 27235,
                    tags: [
                        ["u", caAjax.ajax_url],
                        ["method", 'POST']
                    ],
                    created_at: Math.round(new Date().getTime() / 1e3),
                    content: ""
                }); // Use Nostr API to sign
                pubkey = event.pubkey; // that signed HTTP Auth
                $('#login-btn').text('One moment...');
                $('#login-btn').off('click', doNostrLogin);
                $.post(caAjax.ajax_url, {
                    action: 'ca_login',
                    event: btoa(JSON.stringify(event)),
                    nonce: caAjax.nonce
                }, function(response) {
                    if (response.success) {
                        credits = parseFloat(response.data.credits) || 0;
                        resultHash = response.data.result_hash || '';
                        activateGameBoard();
                        console.log('logged in!');
                    } else {
                        alert('Login failed: ' + (response.data || 'Unknown error'));
                    }
                });
            } catch (e) {
                alert('Nostr login failed. Check your extension.');
            }
        } else {
            alert('Nostr extension not found. Please install one.');
        }
    }

    // Handle Nostr logout
    function logout() {
       $.post(caAjax.ajax_url, {
            action: 'ca_logout',
            pubkey: pubkey,
            nonce: caAjax.nonce
        }, function(response) {
            location.reload();
        });
    }

    function displayGameBoard() {
        var html = '<div class="ca-login-container">';
        html += '<button id="login-btn">Login with Nostr</button>';
        html += '</div>';
        html += '<div class="ca-status">';
        html += '<p><strong>Credits:</strong> <span id="credits">0</span>&nbsp;&nbsp;';
        html += '<button id="deposit-btn">Deposit</button></p>';
        html += '<p>Next Result Hash: <span id="result-hash">Loading...</span></p>';
        html += '</div>';
        html += '<div class="ca-game-container">';
        html += '<div class="ca-board">';
        for (var i = 1; i <= 6; i++) {
            html += '<div class="ca-symbol" data-symbol="' + i + '">';
            html += '<img src="' + caAjax.plugin_url + '/images/' + symbolNames[i] + '.png" alt="' + symbolNames[i] + '">';
            html += '<div class="bet">Bet: <span>' + bets[i] + '</span></div>';
            html += '</div>';
        }
        html += '</div>';
        html += '</div>';
        html += '<div class="ca-button-container">';
        html += '<button id="play-btn">Play</button>';
        html += '<button id="clear-btn">Clear</button>';
        html += '</div>';
        html += '<div id="ca-result"></div>';
        $('#crown-anchor-game').html(html);
    }

    // Helper to compute sha256 via browser crypto module
    // NB: Browser must be on a secure (https://) site
    async function computeSHA256(input) {
        try {
            const encoder = new TextEncoder();
            const data = encoder.encode(input);
            const hashBuffer = await crypto.subtle.digest('SHA-256', data);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        } catch (e) {
            console.error('Hash computation failed:', e);
            return '*** HASH FAILED ***';
        }
    }

    function activateGameBoard() {
        // Update UI
        $('#login-btn').hide();
        $('.ca-status, .ca-button-container').show();
        $('#result-hash').text(resultHash.substr(0, 24));
        $('#credits').text(credits);

        // Deposit modal
        $('#deposit-btn').on('click', showDepositModal);

        // Add bets
        $('.ca-symbol').on('click', function() {
            $('#ca-result').html('');
            var symbol = $(this).data('symbol');
            var totalBets = Object.values(bets).reduce((a, b) => a + b, 0);
            if (credits > totalBets) {
                bets[symbol]++;
                $(this).find('.bet span').text(bets[symbol]);
            } else {
                alert('Not enough credits.');
            }
        });

        // Clear bets
        $('#clear-btn').on('click', function() {
            for (var i = 1; i <= 6; i++) {
                bets[i] = 0;
                $('.ca-symbol[data-symbol="' + i + '"] .bet span').text(0);
            }
            $('#ca-result').html('');
        });

        // Play the game
        $('#play-btn').on('click', function() {
            var totalBets = Object.values(bets).reduce((a, b) => a + b, 0);
            if (totalBets <= 0) {
                alert('Place at least one bet to play.');
                return;
            }
            if (credits < totalBets) {
                alert('Insufficient credits.');
                showDepositModal();
                return;
            }
            $.post(caAjax.ajax_url, {
                action: 'ca_play_game',
                pubkey: pubkey,
                hash: resultHash,
                bets: bets,
                nonce: caAjax.nonce
            }, async function(response) {
                if (response.success) {
                    var rolls = response.data.rolls;
                    var randomhash = response.data.randomhash;
                    var stake = response.data.stake;
                    var winnings = response.data.winnings;
                    credits = parseInt(response.data.credits);
                    var newResultHash = response.data.new_result_hash;

                    // Calculate symbol counts
                    var symbolCounts = {};
                    rolls.forEach(function(roll) {
                        symbolCounts[roll] = (symbolCounts[roll] || 0) + 1;
                    });

                    // Generate detailed bet outcomes
                    var betOutcomes = '';
                    for (var symbol = 1; symbol <= 6; symbol++) {
                        if (bets[symbol] > 0) {
                            var matches = symbolCounts[symbol] || 0;
                            var payoutMultiplier = matches > 0 ? matches + 1 : 0;
                            var payout = payoutMultiplier * bets[symbol];
                            var profit = payout - bets[symbol];
                            betOutcomes += 'Bet on ' + symbolNames[symbol] + ' (Bet: ' + bets[symbol] + '): ' + matches + ' matches - ';
                            if (matches > 0) {
                                betOutcomes += 'Win ' + payout + ' (' + profit + ' profit + ' + bets[symbol] + ' stake)';
                            } else {
                                betOutcomes += 'Win 0';
                            }
                            betOutcomes += '<br>';
                        }
                    }

                    // Display dice results
                    var resultHtml = '<div class="dice-results">';
                    rolls.forEach(function(roll) {
                        resultHtml += '<img src="' + caAjax.plugin_url + '/images/' + symbolNames[roll] + '.png" alt="' + symbolNames[roll] + '" title="' + symbolNames[roll] + '">';
                    });
                    resultHtml += '</div>';

                    // Win/lose explanation
                    resultHtml += '<div class="win-lose-explanation">';
                    resultHtml += '<p><strong>Winnings Calculation:</strong><br>';
                    resultHtml += 'Dice Rolled: ' + rolls.map(function(roll) { return symbolNames[roll]; }).join(', ') + '<br>';
                    resultHtml += betOutcomes;
                    resultHtml += '<strong>Total Profit:</strong> ' + (winnings - stake) + ' (Bet: '+stake+', Won: '+winnings+') </p>';
                    resultHtml += '</div>';

                    // Hash verification
                    var hashInput = rolls.map(function(roll) { return symbolNames[roll]; }).join('-') + '-' + randomhash;
                    var verifyHash = await computeSHA256(hashInput);
                    resultHtml += '<div class="hash-verification">';
                    resultHtml += '<p><strong>Game Validation:</strong><br>';
                    resultHtml += 'Hash Input: ' + hashInput + '<br>';
                    resultHtml += 'Calculated Hash: ' + verifyHash.substr(0, 24) + '<br>';
                    resultHtml += 'Expected Hash: ' + resultHash.substr(0, 24) + '<br>';
                    resultHtml += 'Verification: ' + (verifyHash === resultHash ? 'Hash matches' : 'Hash mismatch') + '</p>';
                    resultHtml += '</div>';

                    $('#ca-result').html(resultHtml);

                    // Update UI
                    $('#credits').text(credits);
                    resultHash = newResultHash;
                    $('#result-hash').text(resultHash.substr(0, 24));

                    // Reset bets
                    for (var i = 1; i <= 6; i++) {
                        bets[i] = 0;
                        $('.ca-symbol[data-symbol="' + i + '"] .bet span').text(0);
                    }

                    if (credits <= 0) {
                        showDepositModal();
                    }
                } else {
                    alert('Play failed: ' + response.data);
                }
            });
        });
    }

    // Handle deposit modal
    function showDepositModal() {
        var modalHtml = '<div id="ca-deposit-modal">';
        modalHtml += '<div id="close-modal">X</div>';
        modalHtml += '<p class="strong">Add Credits</p>';
        modalHtml += '<div id="ca-deposit-select">';
        modalHtml += '<div>1 credit = 10 sats</div>';
        modalHtml += '<input id="deposit-amount" type="number" min="10" step="10" placeholder="Enter amount (sats)">';
        modalHtml += '<div id="credit-display">Credits: 0</div>';
        modalHtml += '<div><button id="ca-pay-button">Pay Now</button></div>';
        modalHtml += '</div>';
        modalHtml += '<div id="ca-pay" style="display:none;">';
        modalHtml += '<p><a id="ca-invoice-link"><img id="ca-invoice-img"/></a></p>';
        modalHtml += '<p><button id="ca-invoice-copy" class="button">Copy</button>';
        modalHtml += '<p><a href="" id="ca-cashu-link" target="_blank">Pay with Cashu ecash?</a>';
        modalHtml += '</div>';
        modalHtml += '</div>';
        modalHtml += '<div id="ca-modal-overlay"></div>';
        $('body').append(modalHtml);

        // Validate deposit amount
        const $amount = $("#deposit-amount");
        const $creditDisplay = $("#credit-display");
        $amount.on('input', function() {
            let value = parseInt($(this).val(), 10);
            if (isNaN(value)) {
                $(this).val(''); // Clear invalid input
            } else if (value < 0) {
                $(this).val(0); // No negative deposits
            } else {
                // Live preview during typing
                $creditDisplay.text(`Credits: ${Math.floor(value / 10)}`);
            }
        });

        // Helper for copy buttons
        function setupCopyButton(selector, text) {
            $(selector).on("click", function() {
                let orig = $(this).text();
                navigator.clipboard.writeText(text).catch(e => console.error('Failed to copy:', e));
                $(this).text("Copied!");
                setTimeout(() => $(this).text(orig), 1000);
            });
        }

        // Handle deposit request
        const $paybutton = $("#ca-pay-button");
        $paybutton.on("click", async (e)=> {
            e.preventDefault();
            // Get lightning payment request or credits
            const response = await createInvoice($amount.val(), 'Crown & Anchor Game');
            if (response.credits !== undefined) {
                credits = response.credits;
                $('#credits').text(credits);
                $('#ca-deposit-modal, #ca-modal-overlay').remove();
                alert(response.message);
                doConfettiBomb();
            } else {
                const {token, pr} = response;
                console.log('pr:>>',pr);
                // Present payment...
                const img = 'https://quickchart.io/chart?cht=qr&chs=200x200&chl='+pr;
                $("#ca-pay").show();
                $('#ca-deposit-select').hide();
                $("#ca-invoice-link").attr("href", `lightning:${pr}`);
                $("#ca-invoice-img").attr("src", img);
                $('#ca-cashu-link').attr('href', `https://www.nostrly.com/cashu-redeem/?to=${pr}`);
                setupCopyButton("#ca-invoice-copy", pr);

                // Listen for receipt
                checkPaymentStatus(token);
            }
        });

        // Close modal
        $('#close-modal, #ca-modal-overlay').on('click', function() {
            $('#ca-deposit-modal, #ca-modal-overlay').remove();
            clearInterval(intervalId); // in case they cancel invoice
        });

        // Ajax call to create invoice
        function createInvoice(amount, comment) {
            return new Promise((resolve, reject) => {
                $.post(caAjax.ajax_url, {
                    action: 'ca_create_invoice',
                    amount: amount,
                    comment: comment,
                    pubkey: pubkey, // global
                    nonce: caAjax.nonce
                }, function(response) {
                    console.log('res:>>', response);
                    if (response.success) {
                        if (response.data.credits !== undefined) {
                            resolve({
                                credits: response.data.credits,
                                message: response.data.message
                            });
                        } else {
                            // Returns {token, payment_request, amount} from endpoint
                            resolve({
                                token: response.data.token,
                                pr: response.data.payment_request,
                                amount: response.data.amount
                            });
                        }
                    } else {
                        // Handle failure with UI feedback
                        alert('Failed to create invoice: ' + (response.data.message || 'Unknown error'));
                        reject(new Error(response.data.message || 'Unknown error'));
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    alert('Invoice request failed: ' + textStatus);
                    reject(new Error(textStatus));
                });
            });
        }

        // Ajax call to check payment status
        function checkPaymentStatus(token) {
            intervalId = setInterval(function() {
                $.post(caAjax.ajax_url, {
                    action: 'ca_check_payment',
                    token: token,
                    pubkey: pubkey, // global
                    nonce: caAjax.nonce
                }, function(response) {
                    console.log('res:>>', response);
                    if (response.success) {
                        // Payment settled
                        if (response.data.credits !== undefined) {
                            clearInterval(intervalId);
                            credits = response.data.credits;
                            $('#credits').text(credits);
                            $('#ca-deposit-modal, #ca-modal-overlay').remove();
                            doConfettiBomb();
                        }
                        // Otherwise still waiting (402 status handled in PHP as success)
                    } else {
                        clearInterval(intervalId);
                        alert('Payment verification failed: ' + (response.data.message || 'Unknown error'));
                        $('#ca-deposit-modal, #ca-modal-overlay').remove();
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    clearInterval(intervalId);
                    alert('Payment check failed: ' + textStatus);
                    $('#ca-deposit-modal, #ca-modal-overlay').remove();
                });
            }, 5000); // Check every 5 seconds
        }
    }

    // Confetti bomb
    function doConfettiBomb() {
        // Do the confetti bomb
        var duration = 0.25 * 1000; //secs
        var end = Date.now() + duration;

        (function frame() {
            // launch a few confetti from the left edge
            confetti({
                particleCount: 7,
                angle: 60,
                spread: 55,
                origin: {
                    x: 0
                }
            });
            // and launch a few from the right edge
            confetti({
                particleCount: 7,
                angle: 120,
                spread: 55,
                origin: {
                    x: 1
                }
            });

            // keep going until we are out of time
            if (Date.now() < end) {
                requestAnimationFrame(frame);
            }
        }());
        confetti.reset();
    }

    initGame();
});
