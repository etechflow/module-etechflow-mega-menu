<?php
declare(strict_types=1);

namespace Etechflow\MegaMenu\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Dynamic-rows admin field (Stores → Configuration → eTechFlow → Mega Menu).
 *
 * Lets the admin add their OWN top-level menu links (e.g. "Sale", "Blog",
 * an external URL) on top of the auto-attached category items. Each row is
 * a simple link; values are stored as JSON via ArraySerialized and merged
 * into the menu by {@see \Etechflow\MegaMenu\ViewModel\MenuData}.
 */
class CustomItems extends AbstractFieldArray
{
    protected function _prepareToRender()
    {
        $this->addColumn('label', [
            'label' => __('Label'),
            'class' => 'required-entry',
        ]);
        $this->addColumn('url', [
            'label' => __('URL or /path'),
            'class' => 'required-entry',
        ]);
        $this->addColumn('sort_order', [
            'label' => __('Sort'),
            'class' => 'validate-number',
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Custom Link');
    }
}
