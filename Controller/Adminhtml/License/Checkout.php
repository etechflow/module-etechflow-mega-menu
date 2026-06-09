<?php

declare(strict_types=1);

namespace Etechflow\MegaMenu\Controller\Adminhtml\License;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManagerInterface;
use Etechflow\MegaMenu\Model\LicenseValidator;

/**
 * Creates a Stripe Checkout Session and returns the session.url as JSON.
 * Per PORTAL_LICENSING_GUIDE.md §4: POST direct to api.stripe.com.
 */
class Checkout extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_MegaMenu::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Curl $curl,
        private readonly EncryptorInterface $encryptor,
        private readonly LicenseValidator $licenseValidator,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        try {
            if (!$this->getRequest()->isPost()) {
                return $result->setData(['error' => 'Invalid request method']);
            }
            $plan  = trim((string) $this->getRequest()->getParam('plan',  ''));
            $email = trim((string) $this->getRequest()->getParam('email', ''));
            $name  = trim((string) $this->getRequest()->getParam('name',  ''));

            if ($plan === '' || $email === '' || $name === '') {
                return $result->setData(['error' => 'All fields are required (plan, name, email).']);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $result->setData(['error' => 'Please enter a valid email address.']);
            }

            $stripeKey = $this->getStripeSecretKey();
            if ($stripeKey === '') {
                return $result->setData(['error' => 'Stripe is not configured. Go to Stores > Configuration > eTechFlow > Mega Menu > Payment (Stripe) and add your Stripe Secret Key.']);
            }

            $planInfo = $this->getPlanInfo($plan);
            if ($planInfo === null) {
                return $result->setData(['error' => 'Unknown plan: ' . $plan]);
            }

            $domain     = $this->licenseValidator->getCurrentHost();
            $successUrl = (string) $this->getUrl('etechflow_megamenu/license/activated')
                . '?session_id={CHECKOUT_SESSION_ID}'
                . '&plan='   . urlencode($plan)
                . '&domain=' . urlencode($domain)
                . '&name='   . urlencode($name)
                . '&email='  . urlencode($email);
            $cancelUrl  = (string) $this->getUrl('etechflow_megamenu/license/gate');

            $session = $this->createCheckoutSession(
                $stripeKey, $planInfo, $email, $name, $domain, $plan, $successUrl, $cancelUrl
            );

            if (isset($session['error'])) {
                return $result->setData(['error' => $session['error']['message'] ?? 'Stripe error']);
            }
            $url = $session['url'] ?? '';
            if ($url === '') {
                return $result->setData(['error' => 'No checkout URL from Stripe. Response keys: ' . implode(',', array_keys($session))]);
            }

            return $result->setData(['url' => $url, 'session_id' => $session['id'] ?? '']);
        } catch (\Throwable $e) {
            return $result->setData(['error' => get_class($e) . ': ' . $e->getMessage()]);
        }
    }

    private function getStripeSecretKey(): string
    {
        $raw = trim((string) $this->scopeConfig->getValue('etechflow_megamenu/payment/stripe_secret_key'));
        if ($raw === '') return '';
        if (preg_match('/^\d+:\d+:/', $raw)) {
            return trim($this->encryptor->decrypt($raw));
        }
        return $raw;
    }

    private function getCurrency(): string
    {
        $c = strtolower(trim((string) $this->scopeConfig->getValue('etechflow_megamenu/payment/stripe_currency')));
        return $c !== '' ? $c : 'usd';
    }

    private function getPlanInfo(string $plan): ?array
    {
        $catalog = [
            'mm_starter'      => ['amount' => 1900, 'label' => 'Starter Plan',      'mode' => 'payment'],
            'mm_professional' => ['amount' => 4900, 'label' => 'Professional Plan', 'mode' => 'payment'],
            'mm_enterprise'   => ['amount' => 9900, 'label' => 'Enterprise Plan',   'mode' => 'payment'],
        ];
        return $catalog[$plan] ?? null;
    }

    private function createCheckoutSession(
        string $key, array $info, string $email, string $name,
        string $domain, string $plan, string $successUrl, string $cancelUrl
    ): array {
        $currency = $this->getCurrency();
        $payload = [
            'mode'                                            => $info['mode'],
            'customer_email'                                  => $email,
            'success_url'                                     => $successUrl,
            'cancel_url'                                      => $cancelUrl,
            'metadata[domain]'                                => $domain,
            'metadata[plan]'                                  => $plan,
            'metadata[module]'                                => 'mega-menu',
            'metadata[customer_name]'                         => $name,
            'line_items[0][price_data][currency]'             => $currency,
            'line_items[0][price_data][unit_amount]'          => (string) $info['amount'],
            'line_items[0][price_data][product_data][name]'   => 'Mega Menu — ' . $info['label'],
            'line_items[0][quantity]'                         => '1',
        ];

        try {
            $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->addHeader('Authorization', 'Bearer ' . $key);
            $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
            $this->curl->post('https://api.stripe.com/v1/checkout/sessions', http_build_query($payload));

            $body   = $this->curl->getBody();
            $status = $this->curl->getStatus();
            $data   = json_decode($body, true);
            if (!is_array($data)) {
                return ['error' => ['message' => 'Stripe HTTP ' . $status . ': ' . substr((string)$body, 0, 200)]];
            }
            return $data;
        } catch (\Throwable $e) {
            return ['error' => ['message' => 'Stripe exception: ' . $e->getMessage()]];
        }
    }
}
