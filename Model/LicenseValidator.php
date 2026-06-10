<?php

declare(strict_types=1);

namespace Etechflow\MegaMenu\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Hybrid HMAC + portal license validator for Etechflow_MegaMenu.
 * Follows PORTAL_LICENSING_GUIDE.md §3-step-1.
 *
 * A valid licence is ALWAYS required — there is no "Production Environment"
 * dev-bypass toggle. The only way to unlock is a valid key (SP-XXXX subscription,
 * HMAC per-module, or shared bundle). On a dev/staging box, set a valid HMAC key
 * computed for the host (see computeKey()).
 *
 *   isValid() priority:
 *     1. revoked = 1                       → false (portal revoke wins)
 *     2. SP-XXXX key, portal answers       → portal's answer is final (true/false)
 *     3. SP-XXXX key, portal unreachable   → 48h local grace fallback
 *     4. Legacy HMAC per-module key        → hash_equals(computeKey(host), key)
 *     5. Bundle key                        → hash_equals(computeBundleKey(host), key)
 *     6. otherwise                         → false
 */
class LicenseValidator
{
    public const XML_PATH_LICENSE_KEY            = 'etechflow_megamenu/license/license_key';
    public const XML_PATH_PORTAL_URL             = 'etechflow_megamenu/license/portal_url';
    public const XML_PATH_PORTAL_API_URL         = 'etechflow_megamenu/license/portal_api_url';

    public const XML_PATH_BUNDLE_LICENSE_KEY = 'etechflow_bundle/license/license_key';

    private const MODULE_ID = 'mega-menu';
    private const BUNDLE_ID = 'etechflow-bundle';

    private const SECRET_FRAGMENTS = [
        'eTF-MM-2026',
        'Q5vP-kN8r',
        'W3jH-tB6n',
        'Y7mX-fL2d',
    ];

    private const BUNDLE_SECRET_FRAGMENTS = [
        'eTF-BUNDLE-2026',
        'k2D9-mP4x',
        'L8nR-vH2j',
        'X7tY-zW5q',
    ];

    // Valid results are cached only briefly so a portal-side change (IP removal,
    // suspension) takes effect within this window instead of staying authorised for
    // up to an hour. Per PORTAL_LICENSING_GUIDE.md §3 (PORTAL_CACHE_TTL = 30).
    private const CACHE_TTL_VALID  = 30;
    private const CACHE_TTL_REJECT = 60;
    public const CACHE_TAG = 'etf_megamenu_license';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly Curl $curl
    ) {
    }

    public function isValid(): bool
    {
        $host = $this->getCurrentHost();
        if ($host === '') {
            return false;
        }

        if ($this->isExplicitlyRevoked()) {
            return false;
        }

        // A valid licence is ALWAYS required — no Production Environment bypass.
        $configuredKey = $this->getConfiguredKey();

        if (str_starts_with($configuredKey, 'SP-')) {
            $portalAnswer = $this->validateViaPortal($host, $configuredKey);
            if ($portalAnswer === true) {
                return true;
            }
            if ($portalAnswer === false) {
                return false;
            }
            return $this->isLocallyIssuedKey($configuredKey, $host);
        }

        if ($configuredKey !== '' && hash_equals($this->computeKey($host), $configuredKey)) {
            return true;
        }

        $bundleKey = $this->getConfiguredBundleKey();
        if ($bundleKey !== '' && hash_equals($this->computeBundleKey($host), $bundleKey)) {
            return true;
        }

        return false;
    }

    /**
     * @return bool|null  true=valid  false=explicit reject  null=unreachable
     */
    private function validateViaPortal(string $host, string $licenseKey): ?bool
    {
        $cacheKey = 'etf_mm_lic_' . md5($host . ':' . $licenseKey);
        $cached   = $this->cache->load($cacheKey);
        if ($cached === '1') {
            return true;
        }
        if ($cached === '0') {
            return false;
        }

        $apiBase = $this->getPortalApiBase();
        if ($apiBase === '') {
            return null;
        }

        $url = rtrim($apiBase, '/') . '/license/validate'
            . '?domain='      . urlencode($this->canonicalize($host))
            . '&license_key=' . urlencode($licenseKey)
            . '&platform=magento'
            . '&module='      . urlencode(self::MODULE_ID);

        $status = 0;
        $body   = '';
        try {
            $this->curl->setTimeout(5);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('User-Agent', 'ETechFlow-MM/1.0');
            $this->curl->get($url);
            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();
        } catch (\Exception) {
            return null;
        }

        if ($status === 200 && $body !== '') {
            $data  = json_decode($body, true);
            $valid = !empty($data['valid']);
            $this->cache->save(
                $valid ? '1' : '0',
                $cacheKey,
                [self::CACHE_TAG],
                $valid ? self::CACHE_TTL_VALID : self::CACHE_TTL_REJECT
            );
            return $valid;
        }

        if ($status === 401 || $status === 403) {
            $this->cache->save('0', $cacheKey, [self::CACHE_TAG], self::CACHE_TTL_REJECT);
            return false;
        }

        return null;
    }

    private function getPortalApiBase(): string
    {
        $api = trim((string) $this->scopeConfig->getValue(self::XML_PATH_PORTAL_API_URL));
        if ($api !== '') {
            return $api;
        }
        $browser = trim((string) $this->scopeConfig->getValue(self::XML_PATH_PORTAL_URL));
        if ($browser !== '' && !str_contains($browser, '127.0.0.1') && !str_contains($browser, 'localhost')) {
            return $browser;
        }
        return '';
    }

    public function computeKey(string $host): string
    {
        $payload = $this->canonicalize($host) . ':' . self::MODULE_ID;
        $secret  = implode('', self::SECRET_FRAGMENTS);
        $raw     = hash_hmac('sha256', $payload, $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function computeBundleKey(string $host): string
    {
        $payload = $this->canonicalize($host) . ':' . self::BUNDLE_ID;
        $secret  = implode('', self::BUNDLE_SECRET_FRAGMENTS);
        $raw     = hash_hmac('sha256', $payload, $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function canonicalize(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    public function getConfiguredKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_LICENSE_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function getConfiguredBundleKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_BUNDLE_LICENSE_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function getCurrentHost(): string
    {
        try {
            $url  = $this->storeManager->getStore()->getBaseUrl();
            $host = parse_url($url, PHP_URL_HOST);
            return is_string($host) ? strtolower($host) : '';
        } catch (\Exception) {
            return '';
        }
    }

    private function isLocallyIssuedKey(string $key, string $host): bool
    {
        $issuedKey = trim((string) $this->scopeConfig->getValue('etechflow_megamenu/license/issued_key'));
        if ($issuedKey === '' || !hash_equals($issuedKey, $key)) {
            return false;
        }
        $issuedDomain = trim((string) $this->scopeConfig->getValue('etechflow_megamenu/license/issued_domain'));
        if ($issuedDomain === '' || $this->canonicalize($issuedDomain) !== $this->canonicalize($host)) {
            return false;
        }
        $sessionId = trim((string) $this->scopeConfig->getValue('etechflow_megamenu/license/stripe_session_id'));
        if ($sessionId === '') {
            return false;
        }
        $issuedAt = (int) $this->scopeConfig->getValue('etechflow_megamenu/license/issued_at');
        if ($issuedAt === 0) {
            return false;
        }
        return (time() - $issuedAt) < 172800;
    }

    private function isExplicitlyRevoked(): bool
    {
        return (string) $this->scopeConfig->getValue(
            'etechflow_megamenu/license/revoked',
            ScopeInterface::SCOPE_STORE
        ) === '1';
    }
}
