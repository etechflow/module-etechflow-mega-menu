<?php
declare(strict_types=1);

namespace Etechflow\MegaMenu\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader over Stores → Configuration → eTechFlow → Mega Menu.
 *
 * Defaults come from {@see etc/config.xml}; this class only adds typing +
 * a single source-of-truth so the controller, block, and JS all agree.
 */
class Config
{
    public const XML_ENABLED              = 'etechflow_megamenu/general/enabled';
    public const XML_ROOT_CATEGORY_ID     = 'etechflow_megamenu/general/root_category_id';
    public const XML_MAX_DEPTH            = 'etechflow_megamenu/general/max_depth';
    public const XML_PRODUCTS_PER_CAT     = 'etechflow_megamenu/dropdown/products_per_category';
    public const XML_SHOW_THUMBNAILS      = 'etechflow_megamenu/dropdown/show_thumbnails';
    public const XML_MOBILE_DRILL         = 'etechflow_megamenu/dropdown/mobile_drill';
    public const XML_CACHE_TTL            = 'etechflow_megamenu/cache/cache_ttl';
    public const XML_PRODUCTS_SOURCE      = 'etechflow_megamenu/dropdown/products_source';
    public const XML_PROMO_BLOCK          = 'etechflow_megamenu/dropdown/promo_block';
    public const XML_INCLUDE_ALL_CATS     = 'etechflow_megamenu/general/include_all_categories';
    public const XML_CUSTOM_ITEMS         = 'etechflow_megamenu/general/custom_items';
    public const XML_ACCENT_COLOR         = 'etechflow_megamenu/appearance/accent_color';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getRootCategoryId(): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_ROOT_CATEGORY_ID, ScopeInterface::SCOPE_STORE));
    }

    public function getMaxDepth(): int
    {
        return max(1, min(3, (int) $this->scopeConfig->getValue(self::XML_MAX_DEPTH, ScopeInterface::SCOPE_STORE)));
    }

    public function getProductsPerCategory(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::XML_PRODUCTS_PER_CAT, ScopeInterface::SCOPE_STORE));
    }

    public function showThumbnails(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_SHOW_THUMBNAILS, ScopeInterface::SCOPE_STORE);
    }

    public function isMobileDrillEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_MOBILE_DRILL, ScopeInterface::SCOPE_STORE);
    }

    public function getCacheTtl(): int
    {
        return max(60, (int) $this->scopeConfig->getValue(self::XML_CACHE_TTL, ScopeInterface::SCOPE_WEBSITE));
    }

    /**
     * Which products fill each dropdown: position|newest|bestsellers|on_sale|price_asc|price_desc.
     */
    public function getProductsSource(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PRODUCTS_SOURCE, ScopeInterface::SCOPE_STORE);
        return $value !== '' ? $value : 'position';
    }

    /**
     * Optional CMS block identifier shown as a promo column inside each dropdown.
     * Empty string = no promo.
     */
    public function getPromoBlockId(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PROMO_BLOCK, ScopeInterface::SCOPE_STORE));
    }

    /**
     * When true, every active category is attached to the menu (ignores each
     * category's "Include in Menu" flag). When false, that flag is respected.
     */
    public function includeAllCategories(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_INCLUDE_ALL_CATS, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Optional brand/theme accent colour (#rrggbb) applied to hovers and prices.
     * Empty string => inherit the active theme's colours (the CSS default), so the
     * menu adopts whatever theme it is rendered in.
     */
    public function getAccentColor(): string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::XML_ACCENT_COLOR, ScopeInterface::SCOPE_STORE));
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : '';
    }

    /**
     * Admin-defined custom top-level links, merged in after the category items.
     *
     * @return array<int,array{label:string,url:string,sort:int}>
     */
    public function getCustomItems(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_CUSTOM_ITEMS, ScopeInterface::SCOPE_STORE);
        if (!$raw) {
            return [];
        }
        $rows = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: @unserialize((string) $raw));
        if (!is_array($rows)) {
            return [];
        }
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $url   = trim((string) ($row['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }
            $items[] = ['label' => $label, 'url' => $url, 'sort' => (int) ($row['sort_order'] ?? 0)];
        }
        usort($items, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
        return $items;
    }
}
