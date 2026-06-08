<?php
declare(strict_types=1);

namespace Etechflow\MegaMenu\Block;

use Etechflow\MegaMenu\ViewModel\MenuData;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Renders the mega menu. Picks its own template at runtime based on the
 * active theme — Hyvä themes get the Alpine.js variant, everything else
 * gets the vanilla-JS Luma variant.
 *
 * Merchants can override by setting `default_template` via layout XML.
 */
class MegaMenu extends Template
{
    private const TEMPLATE_HYVA = 'Etechflow_MegaMenu::mega-menu.phtml';
    private const TEMPLATE_LUMA = 'Etechflow_MegaMenu::mega-menu-luma.phtml';

    public function __construct(
        Context $context,
        private readonly MenuData $menuData,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** Cached rendered HTML of the optional promo CMS block (same for every dropdown). */
    private ?string $promoHtml = null;

    public function getMenuData(): MenuData
    {
        return $this->menuData;
    }

    /**
     * Render the configured promo CMS block once (reused across all dropdowns).
     * Returns '' when no block is configured or it fails to render.
     */
    public function getPromoBlockHtml(): string
    {
        if ($this->promoHtml !== null) {
            return $this->promoHtml;
        }
        $blockId = $this->menuData->getConfig()->getPromoBlockId();
        if ($blockId === '') {
            return $this->promoHtml = '';
        }
        try {
            return $this->promoHtml = (string) $this->getLayout()
                ->createBlock(\Magento\Cms\Block\Block::class)
                ->setBlockId($blockId)
                ->toHtml();
        } catch (\Throwable $e) {
            return $this->promoHtml = '';
        }
    }

    /**
     * Pick the template based on the active theme — unless the layout XML
     * explicitly set one via `<arguments><argument name="template">…</argument></arguments>`.
     */
    public function getTemplate()
    {
        $explicit = parent::getTemplate();
        if ($explicit && $explicit !== self::TEMPLATE_HYVA && $explicit !== self::TEMPLATE_LUMA) {
            // Layout XML forced a custom template — respect that.
            return $explicit;
        }
        return $this->menuData->isHyvaTheme() ? self::TEMPLATE_HYVA : self::TEMPLATE_LUMA;
    }

    /**
     * Final guard: never render any markup when the feature is disabled or there
     * are no top-level items.
     */
    protected function _toHtml()
    {
        if (!$this->menuData->isEnabled()) {
            return '';
        }
        if (!$this->menuData->getTopLevelCategories()) {
            return '';
        }
        return parent::_toHtml();
    }
}
