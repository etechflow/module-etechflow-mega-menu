<?php
declare(strict_types=1);

namespace Etechflow\MegaMenu\Observer;

use Etechflow\MegaMenu\Model\Config;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Theme\Block\Html\Topmenu;

/**
 * Auto-detects and removes the active theme's built-in category navigation so the
 * mega menu can take its place — without hardcoding any one theme's block names.
 *
 * Detection strategy (runs only when the mega menu is enabled):
 *  1. Remove every block of type Magento\Theme\Block\Html\Topmenu. This is the
 *     standard category-menu block used by Luma, the Adobe Commerce default theme,
 *     and virtually all custom child themes, whatever name they gave it
 *     (catalog.topnav, custom_topnav, vendor_main_menu, ...). Detecting by *type*
 *     rather than by name is what makes this theme-agnostic.
 *  2. Remove known nav-section wrapper blocks (e.g. Luma's "store.menu") so no empty
 *     nav strip is left behind once the menu inside it is gone.
 *
 * Disabling the mega menu leaves all of this untouched, so the theme's own nav returns.
 *
 * Note: Hyvä themes render their navigation inline in the header template instead of as
 * a removable Topmenu block, so on Hyvä the native nav is overridden at the template
 * level (the block already serves a Hyvä-specific template); this observer is a no-op
 * there because no Topmenu block exists to remove.
 */
class ReplaceNativeNav implements ObserverInterface
{
    /** The standard category-navigation block class shared by Luma-family themes. */
    private const NATIVE_NAV_CLASS = Topmenu::class;

    /**
     * Known nav-section wrapper blocks to also drop, so removing the menu inside them
     * doesn't leave an empty section. Extend this list for other themes as needed.
     */
    private const NAV_WRAPPER_BLOCKS = ['store.menu'];

    public function __construct(
        private readonly Config $config
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        /** @var LayoutInterface|null $layout */
        $layout = $observer->getEvent()->getData('layout');
        if (!$layout instanceof LayoutInterface) {
            return;
        }

        // 1) Auto-detect the built-in category nav by block type, then remove each one.
        //    Collect names first so we never mutate the layout while iterating it.
        $navBlockNames = [];
        foreach ($layout->getAllBlocks() as $name => $block) {
            if ($block instanceof Topmenu) {
                $navBlockNames[] = (string) $name;
            }
        }
        foreach ($navBlockNames as $name) {
            $layout->unsetElement($name);
        }

        // 2) Drop known wrapper sections so no empty nav strip is left behind.
        foreach (self::NAV_WRAPPER_BLOCKS as $name) {
            if ($layout->hasElement($name)) {
                $layout->unsetElement($name);
            }
        }
    }
}
