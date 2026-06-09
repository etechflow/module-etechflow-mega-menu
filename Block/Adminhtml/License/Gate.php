<?php

declare(strict_types=1);

namespace Etechflow\MegaMenu\Block\Adminhtml\License;

use Etechflow\MegaMenu\Model\LicenseValidator;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Gate extends Template
{
    public function __construct(
        Context $context,
        private readonly LicenseValidator $licenseValidator,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFormKey(): string
    {
        if ($this->formKey !== null) {
            return $this->formKey->getFormKey();
        }
        return \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Framework\Data\Form\FormKey::class)
            ->getFormKey();
    }

    public function getConfigUrl(): string
    {
        return (string) $this->getUrl(
            'adminhtml/system_config/edit',
            ['section' => 'etechflow_megamenu', '_fragment' => 'etechflow_megamenu_license-head']
        );
    }

    public function getCheckoutUrl(): string
    {
        return (string) $this->getUrl('etechflow_megamenu/license/checkout');
    }

    public function getCurrentDomain(): string
    {
        return $this->licenseValidator->getCurrentHost();
    }

    public function isStripeConfigured(): bool
    {
        $sk = trim((string) $this->_scopeConfig->getValue('etechflow_megamenu/payment/stripe_secret_key'));
        return $sk !== '';
    }

    public function isPortalConfigured(): bool
    {
        $u = trim((string) $this->_scopeConfig->getValue('etechflow_megamenu/license/portal_url'))
           ?: trim((string) $this->_scopeConfig->getValue('etechflow_megamenu/license/portal_api_url'));
        return $u !== '';
    }
}
