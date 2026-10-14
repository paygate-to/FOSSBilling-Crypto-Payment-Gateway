<?php

declare(strict_types=1);
/**
 * PayGate.to crypto payments for FOSSBilling.
 *
 * Bitcoin, Ethereum, USDT, USDC, Solana, Litecoin, Monero, Zcash and more, forwarded instantly to
 * your own wallets. Customers pay on PayGate.to's hosted multi-coin page, or on your invoice page
 * with an address and QR code. No KYC, no sign up.
 *
 * Install: copy this PayGateCrypto folder to library/Payment/Adapter/ of your FOSSBilling
 * installation, then add it in System > Payment gateways > New payment gateway.
 *
 * @see https://paygate.to/crypto-payment-gateway-no-kyc-instant-payouts/fossbilling/
 * @see https://paygate.to/docs/crypto-payment-api/
 */

use Pimple\Container;

class Payment_Adapter_PayGateCrypto extends Payment_AdapterAbstract implements FOSSBilling\InjectionAwareInterface
{
    public const MODULE_VERSION = '1.0.0';
    private const TX_TYPE = 'PayGate.to crypto';

    /** How long an invoice-page quote holds: 3 hours to pay, plus 1 hour for blockchain confirmations. */
    private const QUOTE_TTL = 4 * 3600;

    /** Wallet settings, one per network, as used by the hosted multi-coin page. */
    private const NETWORKS = [
        'evm' => 'EVM wallet: ETH, USDT, USDC… on Ethereum, BNB Chain, Polygon, Arbitrum, Base, Optimism, Avalanche',
        'btc' => 'Bitcoin wallet',
        'ltc' => 'Litecoin wallet',
        'solana' => 'Solana wallet: SOL, USDC, USDT on Solana',
        'trc20' => 'Tron wallet: TRX, USDT (TRC20)',
        'bitcoincash' => 'Bitcoin Cash wallet',
        'doge' => 'Dogecoin wallet',
        'xmr' => 'Monero wallet (hosted page only)',
        'zec' => 'Zcash wallet (hosted page only)',
        'ton' => 'TON wallet (hosted page only)',
    ];

    /** Coins offered on the invoice page when the "Coins" setting is empty, by network. */
    private const DEFAULT_COINS = [
        'btc' => ['btc'],
        'evm' => ['erc20_usdt', 'erc20_usdc', 'bep20_usdt', 'polygon_usdc', 'eth'],
        'solana' => ['sol_sol', 'sol_usdc'],
        'trc20' => ['trc20_usdt'],
        'ltc' => ['ltc'],
        'bitcoincash' => ['bch'],
        'doge' => ['doge'],
    ];

    private const EVM_CHAINS = ['eth', 'erc20', 'bep20', 'polygon', 'arbitrum', 'base', 'optimism', 'avax-c', 'linea', 'monad'];

    protected ?Container $di = null;

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        foreach (array_keys(self::NETWORKS) as $network) {
            if (trim((string) ($this->config['wallet_' . $network] ?? '')) !== '') {
                return;
            }
        }

        throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'PayGate.to crypto', ':missing' => 'payout wallet for at least one network'], 4001);
    }

    public static function getConfig(): array
    {
        $form = [
            'mode' => [
                'select', [
                    'label' => 'Checkout',
                    'description' => 'The hosted page lets the customer choose any coin of the networks below. On the invoice page, the customer picks a coin and gets an address and a QR code.',
                    'multiOptions' => [
                        'hosted' => 'Hosted multi-coin page (recommended)',
                        'onsite' => 'Address and QR code on the invoice page',
                    ],
                ],
            ],
        ];
        foreach (self::NETWORKS as $network => $label) {
            $form['wallet_' . $network] = ['text', ['label' => $label, 'description' => $network === 'evm' ? 'Self-custodial wallets you control. Leave a network empty to not accept it.' : '']];
        }

        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Accept Bitcoin, Ethereum, USDT, USDC, Solana, Monero and more, forwarded instantly to your own wallets. No KYC, no sign up. Setup guide: https://paygate.to/crypto-payment-gateway-no-kyc-instant-payouts/fossbilling/',
            'logo' => [
                'logo' => 'PayGateCrypto/logo.png',
                'height' => '30px',
                'width' => '160px',
            ],
            'form' => $form + [
                'coins' => [
                    'text', [
                        'label' => 'Coins on the invoice page (optional)',
                        'description' => 'Comma-separated coin ids for "Address and QR code on the invoice page", for example `btc, erc20_usdt, trc20_usdt, sol_sol`. Leave empty for the common coins of the networks you filled in. [Coin ids](https://api.paygate.to/crypto/info.php)',
                    ],
                ],
                'add_fees' => [
                    'select', [
                        'label' => 'Network fee',
                        'description' => 'Who pays the estimated blockchain fee for forwarding the payment to you.',
                        'multiOptions' => ['0' => 'You (it is taken from the payment)', '1' => 'The customer (added to the amount)'],
                    ],
                ],
                'tolerance' => [
                    'select', [
                        'label' => 'Underpayment tolerance',
                        'description' => 'Crypto prices move and some wallets round amounts. Payments within this margin pay the invoice.',
                        'multiOptions' => ['0.98' => '2% (default)', '0.99' => '1%', '1' => '0%', '0.97' => '3%', '0.95' => '5%', '0.90' => '10%'],
                    ],
                ],
                'confirmations' => [
                    'select', [
                        'label' => 'Confirmations (invoice page)',
                        'description' => 'Blockchain confirmations before the invoice is paid.',
                        'multiOptions' => ['1' => '1 (default)', '2' => '2', '3' => '3', '6' => '6'],
                    ],
                ],
                'api_domain' => [
                    'text', [
                        'label' => 'Dedicated API endpoint (optional)',
                        'description' => 'Your free DDoS-protected endpoint, for example api-yourshop-1a2b3c.paygate.to. Leave empty to use api.paygate.to. [Get one](https://paygate.to/white-label-api-custom-domain-guide/#dedicated-endpoint)',
                    ],
                ],
                'checkout_domain' => [
                    'text', [
                        'label' => 'Checkout domain (optional)',
                        'description' => 'Your white-label checkout domain for the hosted page. Leave empty to use checkout.paygate.to.',
                    ],
                ],
                'theme' => [
                    'text', [
                        'label' => 'Checkout colour (optional)',
                        'description' => 'Hex code or CSS colour name for the hosted page, for example #7c3aed.',
                    ],
                ],
                'logo_url' => [
                    'text', [
                        'label' => 'Checkout logo URL (optional)',
                        'description' => 'Your logo on the hosted page (https URL).',
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');
        $total = $invoiceService->getTotalWithTax($invoiceModel);
        if ($total <= 0) {
            return self::pgError(__trans('This invoice has nothing to pay.'));
        }

        return ($this->config['mode'] ?? 'hosted') === 'onsite'
            ? $this->onsiteHtml($invoiceModel, $total)
            : $this->hostedHtml($invoiceModel, $total);
    }

    private function hostedHtml(Model_Invoice $invoice, float $total): string
    {
        $payload = [];
        foreach (array_keys(self::NETWORKS) as $network) {
            $wallet = trim((string) ($this->config['wallet_' . $network] ?? ''));
            if ($wallet !== '') {
                $payload[$network] = $wallet;
            }
        }
        $payload['fiat_amount'] = round($total, 2);
        $payload['fiat_currency'] = strtoupper((string) $invoice->currency);
        $payload['callback'] = $this->pgCallbackUrl((int) $invoice->id);

        $hosted = $this->pgRequest('POST', 'https://' . $this->pgApiHost() . '/crypto/multi-hosted-wallet.php', $payload);
        if ($hosted === null || !isset($hosted['payment_token']) || !is_string($hosted['payment_token']) || $hosted['payment_token'] === '') {
            return self::pgError(__trans('Payment could not be started. Please try again in a moment.'));
        }

        $query = 'payment_token=' . self::pgKeepEncoded($hosted['payment_token']) . '&add_fees=' . (!empty($this->config['add_fees']) ? '1' : '0');
        foreach (['theme' => 'theme', 'logo_url' => 'logo'] as $setting => $param) {
            $value = trim((string) ($this->config[$setting] ?? ''));
            if ($value !== '') {
                $query .= '&' . $param . '=' . rawurlencode($value);
            }
        }
        if (trim((string) ($this->config['checkout_domain'] ?? '')) !== '') {
            $query .= '&domain=' . rawurlencode($this->pgCheckoutHost());
        }

        return $this->pgRedirectHtml('https://' . $this->pgCheckoutHost() . '/crypto/hosted.php?' . $query, __trans('Pay with crypto'));
    }

    private function onsiteHtml(Model_Invoice $invoice, float $total): string
    {
        $coins = $this->offeredCoins();
        if ($coins === []) {
            return self::pgError(__trans('No coin is configured for this payment method.'));
        }

        $selected = $this->di['request']->query->all()['coin'] ?? '';
        $selected = is_string($selected) ? self::coinId($selected) : '';
        $pageUrl = function (array $params) use ($invoice): string {
            return $this->di['url']->link('invoice/banklink/' . $invoice->hash . '/' . $this->pgGatewayId(), $params);
        };

        if (!isset($coins[$selected])) {
            $html = '<div class="paygate-coins"><p>' . self::pgEscape(__trans('Choose the coin you want to pay with:')) . '</p><div class="d-flex flex-wrap gap-2">';
            foreach (array_keys($coins) as $coin) {
                $html .= '<a class="btn btn-outline-primary" href="' . self::pgEscape($pageUrl(['coin' => $coin])) . '">' . self::pgEscape(self::coinLabel($coin)) . '</a>';
            }

            return $html . '</div></div>';
        }

        $path = self::coinPath($selected);
        $currency = strtolower((string) $invoice->currency);
        $api = 'https://' . $this->pgApiHost() . '/crypto/' . $path;
        $conversion = $this->pgRequest('GET', $api . '/convert.php?value=' . rawurlencode(self::pgAmount($total)) . '&from=' . rawurlencode($currency));
        if ($conversion === null || !isset($conversion['value_coin']) || !is_numeric($conversion['value_coin'])) {
            return self::pgError(__trans('This coin is not available right now. Please choose another one.')) . $this->backLink($pageUrl([]));
        }
        $amount = (float) $conversion['value_coin'];

        if (!empty($this->config['add_fees'])) {
            $fees = $this->pgRequest('GET', $api . '/fees.php');
            $feeUsd = $fees['estimated_cost_currency']['USD'] ?? null;
            if (is_numeric($feeUsd)) {
                $feeCoin = $this->pgRequest('GET', $api . '/convert.php?value=' . rawurlencode(self::pgAmount((float) $feeUsd)) . '&from=usd');
                if ($feeCoin !== null && isset($feeCoin['value_coin']) && is_numeric($feeCoin['value_coin'])) {
                    $amount += (float) $feeCoin['value_coin'];
                }
            }
        }

        $info = $this->pgRequest('GET', $api . '/info.php');
        $minimum = isset($info['minimum']) && is_numeric($info['minimum']) ? (float) $info['minimum'] : 0.0;
        if ($minimum > 0 && $amount < $minimum) {
            return self::pgError(sprintf(__trans('The amount is below the minimum for %s (%s). Please choose another coin.'), self::coinLabel($selected), self::pgAmount($minimum, 8))) . $this->backLink($pageUrl([]));
        }

        $expect = self::pgAmount($amount, 8);
        $quote = ['coin' => $selected, 'expect' => $expect, 'total' => self::pgAmount($total), 'currency' => $currency, 'time' => (string) time()];
        $callback = $this->pgCallbackUrl((int) $invoice->id, [
            'pg_coin' => $quote['coin'],
            'pg_expect' => $quote['expect'],
            'pg_total' => $quote['total'],
            'pg_currency' => $quote['currency'],
            'pg_time' => $quote['time'],
            'pg_quote' => $this->quoteSignature((int) $invoice->id, $quote),
        ]);
        $confirmations = max(1, (int) ($this->config['confirmations'] ?? 1));
        $wallet = $this->pgRequest('GET', $api . '/wallet.php?address=' . rawurlencode((string) $coins[$selected]) . '&callback=' . rawurlencode($callback) . ($confirmations > 1 ? '&confirmations=' . $confirmations : ''));
        if ($wallet === null || !isset($wallet['address_in']) || !is_string($wallet['address_in']) || $wallet['address_in'] === '') {
            return self::pgError(__trans('Payment could not be started. Please try again in a moment.')) . $this->backLink($pageUrl([]));
        }
        $address = $wallet['address_in'];
        $qr = $this->pgRequest('GET', $api . '/qrcode.php?address=' . rawurlencode($address));
        $qrImage = isset($qr['qr_code']) && is_string($qr['qr_code']) && preg_match('#^[A-Za-z0-9+/=]+$#', $qr['qr_code'])
            ? '<img src="data:image/png;base64,' . $qr['qr_code'] . '" width="220" height="220" alt="' . self::pgEscape(__trans('QR code of the payment address')) . '">'
            : '';

        return '<div class="paygate-crypto">'
            . '<p>' . sprintf(self::pgEscape(__trans('Send exactly %s to this address:')), '<strong>' . self::pgEscape($expect . ' ' . self::coinLabel($selected)) . '</strong>') . '</p>'
            . '<p><code style="word-break:break-all;font-size:1.05em">' . self::pgEscape($address) . '</code></p>'
            . $qrImage
            . '<p class="text-muted small">' . self::pgEscape(__trans('Send only this coin on this network, within 3 hours. The invoice is paid automatically once the payment is confirmed on the blockchain.')) . '</p>'
            . $this->backLink($pageUrl([]))
            . '</div>';
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $this->pgRun($api_admin, (int) $id, fn () => $this->handleCallback($api_admin, (int) $id, (array) $data, (int) $gateway_id));
    }

    private function handleCallback($api_admin, int $id, array $data, int $gatewayId): void
    {
        [$invoice, $params] = $this->pgAuthenticate($api_admin, $id, $data, $gatewayId);
        if ($invoice === null) {
            return;
        }
        $txid = trim((string) $params['txid_in']);
        $coin = self::coinId((string) $params['coin']);

        if (($params['pending'] ?? '0') === '1') {
            $this->pgFinish($api_admin, $id, 'pending', sprintf('Unconfirmed payment of %s %s seen; waiting for confirmation.', $params['value_coin'], $coin));

            return;
        }
        if ($this->pgAlreadySettled($gatewayId, (int) $invoice->id, $txid, $id)) {
            $this->pgFinish($api_admin, $id, 'duplicate', 'Repeated callback for ' . $txid . ', already handled.');

            return;
        }

        $wallets = [];
        foreach (array_keys(self::NETWORKS) as $network) {
            $wallets[] = (string) ($this->config['wallet_' . $network] ?? '');
        }
        if (!self::pgPaysUs($params, $wallets)) {
            $this->pgFinish($api_admin, $id, 'wrong_payout', sprintf('Received %s %s, but the payout did not go to the wallets configured here. The invoice was not paid.', $params['value_coin'], $coin));

            return;
        }

        $received = (float) $params['value_coin'];
        $tolerance = isset($this->config['tolerance']) && is_numeric($this->config['tolerance']) ? max(0.5, min(1.0, (float) $this->config['tolerance'])) : 0.98;
        if ($this->di['mod_service']('Invoice')->isInvoiceTypeDeposit($invoice)) {
            $tolerance = max($tolerance, 0.98); // added funds are credited in full
        }
        $total = $this->di['mod_service']('Invoice')->getTotalWithTax($invoice);
        $currency = strtolower((string) $invoice->currency);
        $priced = '';

        if (isset($params['pg_coin'])) {
            // Invoice page: the quote is signed into the callback URL.
            $quote = [
                'coin' => (string) $params['pg_coin'],
                'expect' => (string) ($params['pg_expect'] ?? ''),
                'total' => (string) ($params['pg_total'] ?? ''),
                'currency' => (string) ($params['pg_currency'] ?? ''),
                'time' => (string) ($params['pg_time'] ?? ''),
            ];
            if (!hash_equals($this->quoteSignature((int) $invoice->id, $quote), (string) ($params['pg_quote'] ?? ''))) {
                throw new Payment_Exception('PayGate.to callback rejected: quote signature is invalid');
            }
            if ($coin !== $quote['coin']) {
                $this->pgFinish($api_admin, $id, 'wrong_coin', sprintf('Received %s %s, but %s was quoted: check the payment before releasing the order.', $params['value_coin'], $coin, $quote['coin']));

                return;
            }
            $expected = (float) $quote['expect'];
            // The quote holds only for the invoice total it was made for, and only for a while.
            if ($quote['total'] !== self::pgAmount($total) || $quote['currency'] !== $currency) {
                $priced = ' The invoice total changed after the quote, so it was priced again.';
            } elseif (time() - (int) $quote['time'] > self::QUOTE_TTL) {
                $priced = ' The quote had expired, so the invoice was priced again at the current rate.';
            }
        }
        if (!isset($params['pg_coin']) || $priced !== '') {
            // Hosted page (the customer chose the coin there) or an outdated quote: price the invoice in that coin now.
            $conversion = $this->pgRequest('GET', 'https://' . $this->pgApiHost() . '/crypto/' . self::coinPath($coin) . '/convert.php?value=' . rawurlencode(self::pgAmount($total)) . '&from=' . rawurlencode($currency));
            if ($conversion === null || !isset($conversion['value_coin']) || !is_numeric($conversion['value_coin'])) {
                throw new Payment_Exception('Could not price the invoice in :coin to check the amount', [':coin' => $coin]);
            }
            $expected = (float) $conversion['value_coin'];
        }

        if ($received < $tolerance * $expected) {
            $this->pgFinish($api_admin, $id, 'underpaid', sprintf('Received %s %s, expected %s %s (tolerance %d%%). The invoice was not paid.%s', $params['value_coin'], $coin, self::pgAmount($expected, 8), $coin, (int) round((1 - $tolerance) * 100), $priced));

            return;
        }

        $this->pgPayInvoice($api_admin, $id, $invoice, $txid, sprintf('Received %s %s. TXID in: %s, payout: %s.%s', $params['value_coin'], $coin, $txid, (string) ($params['txid_out'] ?? ''), $priced));
    }

    /** Coin id => payout wallet, for the invoice page. */
    private function offeredCoins(): array
    {
        $list = trim((string) ($this->config['coins'] ?? ''));
        $ids = [];
        if ($list !== '') {
            foreach (preg_split('/[\s,;]+/', $list) ?: [] as $coin) {
                if ($coin !== '') {
                    $ids[] = self::coinId($coin);
                }
            }
        } else {
            foreach (self::DEFAULT_COINS as $network => $coins) {
                $ids = array_merge($ids, $coins);
            }
        }
        $offered = [];
        foreach ($ids as $coin) {
            $network = self::networkOf($coin);
            $wallet = $network === null ? '' : trim((string) ($this->config['wallet_' . $network] ?? ''));
            if ($wallet !== '' && preg_match('#^[a-z0-9][a-z0-9.-]*(_[a-z0-9][a-z0-9._-]*)?$#', $coin)) {
                $offered[$coin] = $wallet;
            }
        }

        return $offered;
    }

    private static function networkOf(string $coin): ?string
    {
        $chain = explode('_', $coin, 2)[0];
        if (in_array($chain, self::EVM_CHAINS, true)) {
            return 'evm';
        }

        return ['btc' => 'btc', 'bch' => 'bitcoincash', 'ltc' => 'ltc', 'doge' => 'doge', 'sol' => 'solana', 'trc20' => 'trc20', 'trx' => 'trc20'][$chain] ?? null;
    }

    /** "erc20/usdc" or "ERC20_USDC" -> "erc20_usdc". */
    private static function coinId(string $coin): string
    {
        return str_replace('/', '_', strtolower(trim($coin, " \t\n\r\0\x0B/")));
    }

    /** "erc20_usdc" -> "erc20/usdc" (only the first underscore separates chain and ticker). */
    private static function coinPath(string $coin): string
    {
        $coin = self::coinId($coin);
        $pos = strpos($coin, '_');

        return $pos === false ? $coin : substr($coin, 0, $pos) . '/' . substr($coin, $pos + 1);
    }

    private static function coinLabel(string $coin): string
    {
        $pos = strpos($coin, '_');
        if ($pos === false) {
            return strtoupper($coin);
        }
        $chain = substr($coin, 0, $pos);
        $names = ['erc20' => 'Ethereum', 'bep20' => 'BNB Chain', 'polygon' => 'Polygon', 'trc20' => 'Tron', 'sol' => 'Solana', 'arbitrum' => 'Arbitrum', 'base' => 'Base', 'optimism' => 'Optimism', 'avax-c' => 'Avalanche'];

        return strtoupper(substr($coin, $pos + 1)) . ' (' . ($names[$chain] ?? strtoupper($chain)) . ')';
    }

    /**
     * HMAC binding the quote (coin, amount, invoice total and currency, time) to the invoice, so the
     * callback URL cannot be edited.
     */
    private function quoteSignature(int $invoiceId, array $quote): string
    {
        return $this->pgHmac('quote|' . $this->pgGatewayId() . '|' . $invoiceId . '|' . $quote['coin'] . '|' . $quote['expect'] . '|' . $quote['total'] . '|' . $quote['currency'] . '|' . $quote['time']);
    }

    private function backLink(string $url): string
    {
        return '<p><a href="' . self::pgEscape($url) . '">' . self::pgEscape(__trans('Choose another coin')) . '</a></p>';
    }

    // ------------------------------------------------------------------
    // Shared PayGate.to helpers (identical in PayGateCard and PayGateCrypto)
    // ------------------------------------------------------------------

    /** Names PayGate.to adds to the callback; never used for our own parameters. */
    private const PAYGATE_PARAMS = ['value_coin', 'coin', 'txid_in', 'txid_out', 'address_in', 'value_forwarded_coin', 'address_out', 'pending'];

    /** Outcomes that are final: such a transaction is never evaluated again. */
    private const FINAL_STATUSES = ['paid', 'underpaid', 'wrong_coin', 'extra_payment', 'wrong_payout'];

    /** Error codes shown in Invoices > Transactions for payments that need the merchant's attention. */
    private const ERROR_CODES = ['underpaid' => 3001, 'wrong_coin' => 3002, 'extra_payment' => 3003, 'wrong_payout' => 3004];

    /** Name of the database lock held while a callback is handled. */
    private ?string $pgLockName = null;

    private function pgApiHost(): string
    {
        return $this->pgHost((string) ($this->config['api_domain'] ?? ''), 'api.paygate.to');
    }

    private function pgCheckoutHost(): string
    {
        return $this->pgHost((string) ($this->config['checkout_domain'] ?? ''), 'checkout.paygate.to');
    }

    private function pgHost(string $value, string $default): string
    {
        $host = strtolower(trim($value));
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = rtrim(explode('/', $host, 2)[0], '.');
        if ($host === '' || !preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return $default;
        }

        return $host;
    }

    /** GET or POST to the PayGate.to API; returns the decoded JSON object, or null on any failure. */
    private function pgRequest(string $method, string $url, ?array $json = null): ?array
    {
        try {
            $options = ['timeout' => 30, 'max_redirects' => 0, 'headers' => ['Accept' => 'application/json', 'User-Agent' => 'FOSSBilling-PayGate/' . self::MODULE_VERSION]];
            if ($json !== null) {
                $options['json'] = $json;
            }
            $client = isset($this->di['http_client']) ? $this->di['http_client'] : Symfony\Component\HttpClient\HttpClient::create();
            $response = $client->request($method, $url, $options);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $data = json_decode($response->getContent(false), true);
        } catch (Throwable $e) {
            // Never log URLs: the callback URL inside them carries the invoice signature.
            $this->pgLog('PayGate.to request to ' . parse_url($url, PHP_URL_HOST) . ' failed: ' . preg_replace('#[a-z][a-z0-9+.-]*://\S+#i', '[url]', $e->getMessage()));

            return null;
        }
        if (!is_array($data) || (isset($data['status']) && is_string($data['status']) && strtolower($data['status']) === 'error')) {
            return null;
        }

        return $data;
    }

    /** Values PayGate.to returns already percent-encoded are passed through; anything else is encoded. */
    private static function pgKeepEncoded(string $value): string
    {
        if (preg_match('/%[0-9A-Fa-f]{2}/', $value) && preg_match('/^(?:[A-Za-z0-9._~-]|%[0-9A-Fa-f]{2})+$/', $value)) {
            return $value;
        }

        return rawurlencode($value);
    }

    /** Plain decimal string for amounts sent to PayGate.to. */
    private static function pgAmount(float $value, int $decimals = 2): string
    {
        $text = number_format($value, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * HMAC with this installation's secret salt (config.php, info.salt), so callback parameters
     * cannot be changed by anyone outside the server.
     */
    private function pgHmac(string $data): string
    {
        $salt = (string) FOSSBilling\Config::getProperty('info.salt', '');
        if (strlen($salt) < 16) {
            throw new Payment_Exception('The info.salt setting in config.php is missing or too short.');
        }

        return hash_hmac('sha256', 'paygate|' . $data, $salt);
    }

    /** This gateway's id (set by FOSSBilling 0.8+, read from the callback URL on older versions). */
    private function pgGatewayId(): int
    {
        if (!empty($this->config['gateway_id'])) {
            return (int) $this->config['gateway_id'];
        }
        parse_str((string) parse_url((string) ($this->config['notify_url'] ?? ''), PHP_URL_QUERY), $query);

        return (int) ($query['gateway_id'] ?? 0);
    }

    /** Signature binding a callback to this gateway and invoice. */
    private function pgInvoiceToken(int $invoiceId): string
    {
        return $this->pgHmac('invoice|' . $this->pgGatewayId() . '|' . $invoiceId);
    }

    /** This invoice's callback URL: FOSSBilling's ipn.php plus the invoice signature. */
    private function pgCallbackUrl(int $invoiceId, array $extra = []): string
    {
        $url = (string) $this->config['notify_url'];
        $url .= (str_contains($url, '?') ? '&' : '?') . 'pg_token=' . $this->pgInvoiceToken($invoiceId);
        foreach ($extra as $name => $value) {
            $url .= '&' . rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return $url;
    }

    /** The undecoded query string of the stored callback request. */
    private static function pgRawQuery(array $server): string
    {
        if (isset($server['REQUEST_URI'])) {
            $uri = (string) $server['REQUEST_URI'];
            $hash = strpos($uri, '#');
            if ($hash !== false) {
                $uri = substr($uri, 0, $hash);
            }
            $mark = strpos($uri, '?');

            return $mark === false ? '' : substr($uri, $mark + 1);
        }

        return (string) ($server['QUERY_STRING'] ?? '');
    }

    /** Query parameters; a repeated name keeps its last value (PayGate.to's, which comes last). */
    private static function pgParseQuery(string $raw): array
    {
        $params = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $params[urldecode($eq === false ? $pair : substr($pair, 0, $eq))] = $eq === false ? '' : urldecode(substr($pair, $eq + 1));
        }

        return $params;
    }

    /**
     * Verify PayGate.to's RSA signature (X-PayGate-Signature, X-PayGate-Key-Id) over the registered
     * callback URL (scheme, host and path of ipn.php) followed by the raw query string as received.
     * Returns an empty string when valid, otherwise the reason.
     */
    private function pgVerifySignature(array $server): string
    {
        $signature = trim((string) ($server['HTTP_X_PAYGATE_SIGNATURE'] ?? ''));
        if ($signature === '') {
            return 'signature_absent';
        }
        if (!function_exists('openssl_verify')) {
            return 'openssl_unavailable';
        }
        $keyId = trim((string) ($server['HTTP_X_PAYGATE_KEY_ID'] ?? '')) ?: 'v1';
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $keyId)) {
            return 'unknown_key_id';
        }
        $pemFile = __DIR__ . '/keys/paygate_callback_public_' . $keyId . '.pem';
        $pem = is_readable($pemFile) ? file_get_contents($pemFile) : false;
        if (!is_string($pem) || $pem === '') {
            return 'unknown_key_id';
        }
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            return 'bad_public_key';
        }
        $raw = base64_decode($signature, true);
        if ($raw === false || $raw === '') {
            return 'signature_not_base64';
        }
        $notifyUrl = (string) $this->config['notify_url'];
        $base = substr($notifyUrl, 0, strcspn($notifyUrl, '?#'));
        $query = self::pgRawQuery($server);
        $signed = $query === '' ? $base : $base . '?' . $query;
        $result = openssl_verify($signed, $raw, $publicKey, OPENSSL_ALGO_SHA256);

        return $result === 1 ? '' : ($result === -1 ? 'openssl_error' : 'signature_mismatch');
    }

    /**
     * Runs a callback handler. Errors are recorded on the transaction (FOSSBilling 0.7 leaves it
     * "received" otherwise) and the invoice lock is always released.
     */
    private function pgRun($api_admin, int $id, callable $handler): void
    {
        try {
            $handler();
        } catch (Throwable $e) {
            try {
                $api_admin->invoice_transaction_update([
                    'id' => $id,
                    'status' => Model_Transaction::STATUS_ERROR,
                    'error' => $e->getMessage(),
                    'error_code' => (int) $e->getCode(),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $ignored) {
                // The original error is the one that matters.
            }

            throw $e;
        } finally {
            if ($this->pgLockName !== null) {
                $this->di['db']->getCell('SELECT RELEASE_LOCK(:name)', [':name' => $this->pgLockName]);
                $this->pgLockName = null;
            }
        }
    }

    /**
     * One callback per invoice at a time: PayGate.to may deliver the same callback more than once,
     * and simultaneous deliveries must not credit the client twice.
     */
    private function pgLock(int $invoiceId): void
    {
        $name = 'paygate:invoice:' . $invoiceId;
        if ((int) $this->di['db']->getCell('SELECT GET_LOCK(:name, 20)', [':name' => $name]) !== 1) {
            throw new Payment_Exception('Another PayGate.to callback for invoice #:id is being handled; this one will be retried', [':id' => $invoiceId]);
        }
        $this->pgLockName = $name;
    }

    /**
     * Common callback checks. Returns [invoice model, PayGate.to parameters], [null, []] when this
     * transaction already has a final outcome, or throws.
     */
    private function pgAuthenticate($api_admin, int $id, array $data, int $gatewayId): array
    {
        $server = is_array($data['server'] ?? null) ? $data['server'] : [];
        $reason = $this->pgVerifySignature($server);
        if ($reason !== '') {
            throw new Payment_Exception('PayGate.to callback rejected: :reason', [':reason' => $reason]);
        }

        $params = self::pgParseQuery(self::pgRawQuery($server));
        $invoiceId = (int) ($params['invoice_id'] ?? 0);
        if ((int) ($params['gateway_id'] ?? 0) !== $gatewayId || $this->pgGatewayId() !== $gatewayId) {
            throw new Payment_Exception('PayGate.to callback rejected: gateway does not match');
        }
        if ($invoiceId < 1 || !hash_equals($this->pgInvoiceToken($invoiceId), (string) ($params['pg_token'] ?? ''))) {
            throw new Payment_Exception('PayGate.to callback rejected: invoice signature is invalid');
        }

        $this->pgLock($invoiceId);
        $tx = $api_admin->invoice_transaction_get(['id' => $id]);
        if (!empty($tx['invoice_id']) && (int) $tx['invoice_id'] !== $invoiceId) {
            throw new Payment_Exception('PayGate.to callback rejected: invoice does not match the transaction');
        }
        if (($tx['status'] ?? '') === Model_Transaction::STATUS_PROCESSED && in_array($tx['txn_status'] ?? '', self::FINAL_STATUSES, true)) {
            return [null, []]; // processed again from the admin area: the recorded outcome stands
        }
        $invoice = $this->di['db']->load('Invoice', $invoiceId);
        if (!$invoice instanceof Model_Invoice) {
            throw new Payment_Exception('Invoice #:id not found', [':id' => $invoiceId]);
        }

        $txid = trim((string) ($params['txid_in'] ?? ''));
        if ($txid === '' || !isset($params['value_coin'], $params['coin'])) {
            throw new Payment_Exception('PayGate.to callback is missing payment data');
        }

        $api_admin->invoice_transaction_update([
            'id' => $id,
            'invoice_id' => $invoiceId,
            'txn_id' => $txid,
            'type' => self::TX_TYPE,
        ]);

        return [$invoice, $params];
    }

    /**
     * True if another transaction already settled this incoming blockchain transaction for this
     * invoice. One blockchain transaction can pay several invoices (exchange batch withdrawals),
     * so the invoice is part of the check.
     */
    private function pgAlreadySettled(int $gatewayId, int $invoiceId, string $txid, int $currentId): bool
    {
        $existing = $this->di['db']->findOne(
            'Transaction',
            'gateway_id = :gateway AND invoice_id = :invoice AND txn_id = :txn AND id != :id AND status = :processed AND txn_status IN (\'' . implode('\', \'', self::FINAL_STATUSES) . '\')',
            [':gateway' => $gatewayId, ':invoice' => $invoiceId, ':txn' => $txid, ':id' => $currentId, ':processed' => Model_Transaction::STATUS_PROCESSED]
        );

        return $existing instanceof Model_Transaction;
    }

    /**
     * Close the transaction (shown in Admin > Invoices > Transactions). Payments that need the
     * merchant's attention carry an error code: 3001 underpaid, 3002 wrong coin, 3003 extra payment.
     */
    private function pgFinish($api_admin, int $id, string $txnStatus, string $note, ?float $amount = null, ?string $currency = null): void
    {
        $update = [
            'id' => $id,
            'txn_status' => $txnStatus,
            'status' => Model_Transaction::STATUS_PROCESSED,
            'error' => isset(self::ERROR_CODES[$txnStatus]) ? $note : '',
            'error_code' => self::ERROR_CODES[$txnStatus] ?? 0,
            'note' => $note,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($amount !== null) {
            $update['amount'] = $amount;
            $update['currency'] = $currency;
        }
        $api_admin->invoice_transaction_update($update);
    }

    /**
     * When PayGate.to lists the payout addresses (address_out), at least 90% of the payment must go
     * to the wallets configured here. Without this, a leaked callback URL could be registered with
     * someone else's wallet.
     *
     * @param string[] $wallets
     */
    private static function pgPaysUs(array $params, array $wallets): bool
    {
        if (!isset($params['address_out']) || trim((string) $params['address_out']) === '') {
            return true; // nothing to compare with
        }
        $shares = json_decode((string) $params['address_out'], true);
        if (!is_array($shares)) {
            return false;
        }
        $ours = [];
        foreach ($wallets as $wallet) {
            if (trim((string) $wallet) !== '') {
                $ours[] = strtolower(trim((string) $wallet));
            }
        }
        $share = 0.0;
        foreach ($shares as $address => $value) {
            if (in_array(strtolower(trim((string) $address)), $ours, true) && is_numeric($value)) {
                $share += (float) $value;
            }
        }

        return $share >= 0.9;
    }

    /** Credit the client with the invoice total and pay the invoice, as FOSSBilling's own gateways do. */
    private function pgPayInvoice($api_admin, int $id, Model_Invoice $invoice, string $txid, string $note): void
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $total = $invoiceService->getTotalWithTax($invoice);
        $apiInvoice = $api_admin->invoice_get(['id' => $invoice->id]);

        // Read the status from the database: the invoice may have been paid or cancelled in the meantime.
        $status = (string) $this->di['db']->getCell('SELECT status FROM invoice WHERE id = :id', [':id' => $invoice->id]);
        if ($status !== Model_Invoice::STATUS_UNPAID) {
            $this->pgFinish($api_admin, $id, 'extra_payment', 'The invoice is ' . $status . ', so this payment was not applied: ' . $note . '. Refund the client or add the amount to their balance.');

            return;
        }

        $api_admin->client_balance_add_funds([
            'id' => $apiInvoice['client']['id'],
            'amount' => $total,
            'description' => self::TX_TYPE . ' transaction ' . $txid,
            'type' => self::TX_TYPE,
            'rel_id' => $id, // this transaction (blockchain hashes are longer than the column)
        ]);
        if ($invoiceService->isInvoiceTypeDeposit($invoice)) {
            $invoiceService->markAsPaid($invoice);
        } else {
            if (!$invoice->approved) {
                $invoiceService->approveInvoice($invoice, ['use_credits' => false]);
            }
            $api_admin->invoice_pay_with_credits(['id' => $invoice->id]);
        }

        $this->pgFinish($api_admin, $id, 'paid', $note, $total, $apiInvoice['currency'] ?? null);
    }

    private function pgLog(string $message): void
    {
        try {
            if (isset($this->di['logger'])) {
                $this->di['logger']->warn($message); // "warning" is not a priority on FOSSBilling 0.7
            }
        } catch (Throwable $e) {
            // Logging must never break a payment.
        }
    }

    private static function pgEscape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A button to the payment page, followed automatically when FOSSBilling asks for it. */
    private function pgRedirectHtml(string $url, string $label): string
    {
        $html = '<a class="btn btn-primary" id="paygate-pay" href="' . self::pgEscape($url) . '" rel="noreferrer">' . self::pgEscape($label) . '</a>';
        if (!empty($this->config['auto_redirect'])) {
            $html .= '<script>window.location.href = ' . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
        }

        return $html;
    }

    private static function pgError(string $message): string
    {
        return '<div class="alert alert-danger">' . self::pgEscape($message) . '</div>';
    }
}
