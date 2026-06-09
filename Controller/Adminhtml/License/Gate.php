<?php

declare(strict_types=1);

namespace Etechflow\MegaMenu\Controller\Adminhtml\License;

use Etechflow\MegaMenu\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin License Gate page.
 * If isValid(), redirect to the module config page.
 * Otherwise render the gate (plan cards + Stripe checkout form).
 */
class Gate extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_MegaMenu::config';

    public function __construct(
        Context $context,
        private readonly LicenseValidator $licenseValidator,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if ($this->licenseValidator->isValid()) {
            $this->messageManager->addSuccessMessage(
                (string) __('Mega Menu is licensed. Configure the module below.')
            );
            return $this->resultRedirectFactory->create()->setPath(
                'adminhtml/system_config/edit',
                ['section' => 'etechflow_megamenu']
            );
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend((string) __('Mega Menu — License Required'));
        return $resultPage;
    }
}
