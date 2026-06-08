<?php
declare(strict_types=1);

namespace Etechflow\MegaMenu\ViewModel;

use Etechflow\MegaMenu\Model\Config;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Exposes the data the mega menu phtml needs:
 *  - top-level categories under the configured root
 *  - the configured behaviour flags
 *  - the active theme code (so the phtml can branch if needed)
 *
 * Used by both the Hyvä and Luma templates; same data shape both consume.
 */
class MenuData implements ArgumentInterface
{
    /** @var array<int,array{id:int,name:string,url:string,has_children:bool}>|null */
    private ?array $topLevelCache = null;

    public function __construct(
        private readonly CategoryCollectionFactory $catCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly DesignInterface $design,
        private readonly Config $config
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Top-level categories — the items rendered as the horizontal menu bar.
     *
     * @return array<int,array{id:int,name:string,url:string,has_children:bool}>
     */
    public function getTopLevelCategories(): array
    {
        if ($this->topLevelCache !== null) {
            return $this->topLevelCache;
        }

        $store = $this->storeManager->getStore();
        $coll = $this->catCollectionFactory->create();
        $coll->addAttributeToSelect(['name', 'url_key', 'url_path', 'include_in_menu'])
            ->addAttributeToFilter('parent_id', $this->config->getRootCategoryId())
            ->addAttributeToFilter('is_active', 1)
            ->setStoreId((int) $store->getId())
            ->setOrder('position', 'ASC')
            ->setPageSize(50);

        // Respect each category's "Include in Menu" flag unless the admin chose
        // to attach ALL active categories.
        if (!$this->config->includeAllCategories()) {
            $coll->addAttributeToFilter('include_in_menu', 1);
        }

        $out = [];
        foreach ($coll as $cat) {
            $out[] = [
                'id' => (int) $cat->getId(),
                'name' => (string) $cat->getName(),
                'url' => (string) $cat->getUrl(),
                'has_children' => ((int) $cat->getChildrenCount()) > 0,
            ];
        }

        // Merge admin-defined custom links in after the category items.
        foreach ($this->config->getCustomItems() as $item) {
            $out[] = [
                'id' => 0,
                'name' => $item['label'],
                'url' => $item['url'],
                'has_children' => false,
            ];
        }

        return $this->topLevelCache = $out;
    }

    /**
     * The URL the frontend hits to lazy-load subcategory + product data per top-level cat.
     */
    public function getJsonEndpoint(): string
    {
        return rtrim($this->storeManager->getStore()->getBaseUrl(), '/') . '/megamenu/products/index';
    }

    /**
     * Active design theme code, e.g. "Hyva/default", "Magento/luma".
     */
    public function getThemeCode(): string
    {
        return (string) $this->design->getDesignTheme()->getCode();
    }

    /**
     * True when the active theme is a Hyvä-family theme. Used by the layout
     * to decide which template to render.
     */
    public function isHyvaTheme(): bool
    {
        return str_starts_with($this->getThemeCode(), 'Hyva/');
    }
}
