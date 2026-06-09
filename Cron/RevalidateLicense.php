<?php

declare(strict_types=1);

namespace Etechflow\MegaMenu\Cron;

use Etechflow\MegaMenu\Model\LicenseValidator;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;

/**
 * Periodically re-checks the licence with the portal and, when the enforced state
 * changes (valid <-> invalid), flushes the page + block cache so the storefront
 * reflects it without waiting for the Full Page Cache to expire.
 *
 * Why this is needed: the mega menu is baked into the FPC-cached page, and Magento
 * only re-evaluates Config::isEnabled() (-> LicenseValidator::isValid()) on an FPC
 * MISS. Once every page is cached it never re-checks, so a portal-side suspension or
 * IP removal would otherwise keep showing the menu until the cache happened to
 * regenerate. This cron is the "phone home" that closes that gap.
 *
 * Requires Magento cron to be running (it is on every production install). The flush
 * only fires on an actual state transition, so steady-state cost is a single cheap
 * portal call per run.
 */
class RevalidateLicense
{
    /** Stored in the default cache pool (NOT FPC), so it survives the FPC flush below. */
    private const STATE_CACHE_KEY = 'etf_megamenu_enforced_state';

    public function __construct(
        private readonly LicenseValidator $licenseValidator,
        private readonly CacheInterface $cache,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    public function execute(): void
    {
        // Drop the short-lived valid/reject cache so isValid() asks the portal afresh.
        $this->cache->clean([LicenseValidator::CACHE_TAG]);

        $now  = $this->licenseValidator->isValid() ? '1' : '0';
        $last = $this->cache->load(self::STATE_CACHE_KEY);

        if ($last !== false && (string) $last === $now) {
            return; // no change — nothing to invalidate
        }

        // State flipped (or first run): regenerate cached storefront pages so the
        // mega menu appears/disappears in line with the licence.
        $this->cacheTypeList->cleanType('full_page');
        $this->cacheTypeList->cleanType('block_html');

        $this->cache->save($now, self::STATE_CACHE_KEY);
    }
}
