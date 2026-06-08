<?php
declare(strict_types=1);

namespace Etechflow\MegaMenu\Controller\Products;

use Etechflow\MegaMenu\Model\Config;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * GET /megamenu/products/index?cat=<id>
 *
 * Returns a JSON payload with the immediate subcategories of <cat> plus a small
 * set of featured products from <cat>. Designed to be called from the storefront
 * mega menu on first hover/focus so the page load itself stays free of menu data.
 *
 * Result is cached for {@see Config::getCacheTtl()} seconds, tagged with
 * Magento's standard catalog tags so any category/product save invalidates it.
 */
class Index implements HttpGetActionInterface
{
    /** Tags piggyback on Magento's catalog tags so cat/product saves auto-bust. */
    private const CACHE_TAGS = ['cat_p', 'cat_c', 'etechflow_megamenu'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly CategoryCollectionFactory $catCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Category $categoryModel,
        private readonly CacheInterface $cache,
        private readonly ImageHelper $imageHelper,
        private readonly Config $config
    ) {
    }

    public function execute()
    {
        $catId = (int) $this->request->getParam('cat', 0);
        $result = $this->jsonFactory->create();

        if (!$catId || !$this->config->isEnabled()) {
            return $result->setData(['subcategories' => [], 'products' => []]);
        }

        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $currencyCode = $store->getCurrentCurrencyCode();
        $cacheKey = sprintf('etmm_megamenu_%d_%d_%s_%s_v2', $storeId, $catId, $currencyCode, $this->config->getProductsSource());

        // ---- CACHE HIT ----------------------------------------------------
        $hit = $this->cache->load($cacheKey);
        if ($hit !== false && $hit !== null && $hit !== '') {
            $decoded = json_decode($hit, true);
            if (is_array($decoded)) {
                $result->setData($decoded);
                $this->applyCacheHeaders($result);
                return $result;
            }
        }

        // ---- CACHE MISS ---------------------------------------------------
        $payload = $this->buildPayload($catId, $storeId, $store);

        try {
            $this->cache->save(
                json_encode($payload),
                $cacheKey,
                self::CACHE_TAGS,
                $this->config->getCacheTtl()
            );
        } catch (\Throwable $e) {
            // Cache write failures must never break the response.
        }

        $result->setData($payload);
        $this->applyCacheHeaders($result);
        return $result;
    }

    /**
     * Build the response payload. Returns immediate subcategories + a small set
     * of featured products from the requested category.
     */
    private function buildPayload(int $catId, int $storeId, $store): array
    {
        $subcategories = [];
        $products = [];
        $priceCurrency = ObjectManager::getInstance()
            ->get(\Magento\Framework\Pricing\PriceCurrencyInterface::class);

        try {
            $mediaUrl = rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/catalog/product';

            $subColl = $this->catCollectionFactory->create();
            $subColl->addAttributeToSelect(['name', 'url_key', 'url_path'])
                ->addAttributeToFilter('parent_id', $catId)
                ->addAttributeToFilter('is_active', 1)
                ->setStoreId($storeId)
                ->setOrder('position', 'ASC')
                ->setPageSize(200);
            if (!$this->config->includeAllCategories()) {
                $subColl->addAttributeToFilter('include_in_menu', 1);
            }

            $subIds = [];
            $subRaw = [];
            foreach ($subColl as $sub) {
                $sid = (int) $sub->getId();
                $subIds[] = $sid;
                $subRaw[$sid] = [
                    'id' => $sid,
                    'name' => (string) $sub->getName(),
                    'url' => (string) $sub->getUrl(),
                    'count' => 0,
                ];
            }

            if (!empty($subIds)) {
                $resource = ObjectManager::getInstance()->get(ResourceConnection::class);
                $conn = $resource->getConnection();
                $idxTable = $resource->getTableName('catalog_category_product_index');
                $idList = implode(',', array_map('intval', $subIds));
                $counts = $conn->fetchPairs(
                    "SELECT category_id, COUNT(*) FROM `$idxTable` "
                    . "WHERE category_id IN ($idList) AND store_id = $storeId AND visibility IN (2,4) "
                    . "GROUP BY category_id"
                );
                foreach ($counts as $cid => $cnt) {
                    if (isset($subRaw[(int)$cid])) {
                        $subRaw[(int)$cid]['count'] = (int) $cnt;
                    }
                }
            }
            $subcategories = array_values($subRaw);

            // Featured products: the category's OWN (directly-assigned) products first,
            // so every category — including each subcategory — shows a distinct set
            // instead of inheriting the parent's. If slots remain, fill from descendant
            // categories (anchor) so container categories still look populated.
            $category = $this->categoryModel->load($catId);
            $limit = (int) $this->config->getProductsPerCategory();
            $attrs = ['name', 'price', 'thumbnail', 'small_image', 'image', 'url_key'];

            $picked  = [];
            $seenIds = [];

            // 1) Directly-assigned products of THIS category, in category position order.
            $directColl = $this->collectionFactory->create();
            $directColl->addAttributeToSelect($attrs)
                ->addUrlRewrite()
                ->addAttributeToFilter('status', 1)
                ->addAttributeToFilter('visibility', ['in' => [2, 4]]);
            $directColl->getSelect()->join(
                ['etmm_ccp' => $directColl->getResource()->getTable('catalog_category_product')],
                'etmm_ccp.product_id = e.entity_id AND etmm_ccp.category_id = ' . (int) $catId,
                []
            )->order('etmm_ccp.position ASC');
            $directColl->setPageSize($limit)->load();
            foreach ($directColl as $p) {
                $picked[]  = $p;
                $seenIds[] = (int) $p->getId();
            }

            // 2) Top up from descendant categories (anchor) when there is room left.
            if (count($picked) < $limit) {
                $fillColl = $this->collectionFactory->create();
                $fillColl->addAttributeToSelect($attrs)
                    ->addUrlRewrite()
                    ->addCategoryFilter($category)
                    ->addAttributeToFilter('status', 1)
                    ->addAttributeToFilter('visibility', ['in' => [2, 4]]);
                if (!empty($seenIds)) {
                    $fillColl->addAttributeToFilter('entity_id', ['nin' => $seenIds]);
                }
                $this->applyProductsSource($fillColl);
                $fillColl->setPageSize($limit - count($picked))->load();
                foreach ($fillColl as $p) {
                    $picked[] = $p;
                }
            }

            foreach ($picked as $product) {
                $imgUrl = null;
                if ($this->config->showThumbnails()) {
                    try {
                        $imgUrl = $this->imageHelper
                            ->init($product, 'category_page_grid')
                            ->resize(160, 160)
                            ->getUrl();
                    } catch (\Throwable $e) {
                        $thumb = $product->getThumbnail();
                        if ($thumb && $thumb !== 'no_selection') {
                            $imgUrl = $mediaUrl . $thumb;
                        }
                    }
                }
                $rawFinalPrice = (float) $product->getFinalPrice();
                $convertedPrice = (float) $priceCurrency->convert($rawFinalPrice);
                $products[] = [
                    'name' => $product->getName(),
                    'price' => round($convertedPrice, 2),
                    'price_formatted' => $priceCurrency->convertAndFormat($rawFinalPrice, false),
                    'url' => $product->getProductUrl(),
                    'img' => $imgUrl,
                ];
            }
        } catch (\Throwable $e) {
            // Empty payload is the safe fallback — never break the response.
        }

        return ['subcategories' => $subcategories, 'products' => $products];
    }

    /**
     * Apply the configured "Featured Products Source" ordering/filter to the
     * product collection. Falls through to the category's own order by default.
     * Wrapped in the caller's try/catch — never breaks the response.
     */
    private function applyProductsSource(\Magento\Catalog\Model\ResourceModel\Product\Collection $collection): void
    {
        switch ($this->config->getProductsSource()) {
            case 'newest':
                $collection->setOrder('created_at', 'DESC');
                break;
            case 'price_asc':
                $collection->setOrder('price', 'ASC');
                break;
            case 'price_desc':
                $collection->setOrder('price', 'DESC');
                break;
            case 'on_sale':
                $collection->addAttributeToFilter('special_price', ['notnull' => true])
                    ->setOrder('created_at', 'DESC');
                break;
            case 'bestsellers':
                try {
                    $soi = ObjectManager::getInstance()->get(ResourceConnection::class)
                        ->getTableName('sales_order_item');
                    $collection->getSelect()->joinLeft(
                        ['etmm_bs' => new \Zend_Db_Expr(
                            "(SELECT product_id, SUM(qty_ordered) AS qty FROM `{$soi}` "
                            . "WHERE parent_item_id IS NULL GROUP BY product_id)"
                        )],
                        'etmm_bs.product_id = e.entity_id',
                        []
                    )->order(new \Zend_Db_Expr('etmm_bs.qty DESC'));
                } catch (\Throwable $e) {
                    // join failure -> fall back to default ordering
                }
                break;
            case 'position':
            default:
                // Category's own product order (collection default) — no change.
                break;
        }
    }

    /** Tell browser + CDN it's safe to cache for the configured TTL. */
    private function applyCacheHeaders($result): void
    {
        if (method_exists($result, 'setHeader')) {
            try {
                $ttl = $this->config->getCacheTtl();
                $result->setHeader('Cache-Control', "public, max-age={$ttl}, s-maxage={$ttl}", true);
            } catch (\Throwable $e) {
                // Header set failures must never break the response.
            }
        }
    }
}
