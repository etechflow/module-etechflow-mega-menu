<?php

declare(strict_types=1);

namespace Etechflow\MegaMenu\Controller\Adminhtml\License;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\View\Result\PageFactory;

/**
 * Landing page after payment. The buyer returns from the webstore Stripe
 * checkout (module.etechflow.com) carrying the broker session id; we fetch the
 * issued SP-XXXX key from the broker (only returned once Stripe confirms
 * payment) and save it to config. Replaces the prior Stripe success callback.
 */
class Activated extends Action
{
    public const ADMIN_RESOURCE = 'Etechflow_MegaMenu::config';

    private const BROKER_URL = 'https://module.etechflow.com/api/license/result';
    private const LICENSE_TOKEN = 'lcsk_8f3b9d2a7c14e605b9af2e7c1d8043f6';

    public function __construct(
        Context $context,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly CurlFactory $curlFactory,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $sessionId = trim((string) $this->getRequest()->getParam('session_id', ''));
        $plan      = trim((string) $this->getRequest()->getParam('plan', ''));

        $page = $this->resultPageFactory->create();
        $page->getConfig()->getTitle()->set('License Activated');
        $block = $page->getLayout()->getBlock('etechflow.mm.license.activated');

        if ($sessionId === '') {
            if ($block) {
                $block->setData('error', 'Invalid payment callback.');
            }
            return $page;
        }

        $licenseKey = '';
        $error      = '';
        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(30);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Accept', 'application/json');
            $curl->addHeader('X-ETF-License-Token', self::LICENSE_TOKEN);
            $curl->post(self::BROKER_URL, json_encode(['session_id' => $sessionId]));
            $data = json_decode((string) $curl->getBody(), true);
            if ((int) $curl->getStatus() === 200 && !empty($data['license_key'])) {
                $licenseKey = (string) $data['license_key'];
                $plan       = (string) ($data['plan'] ?? $plan);
            } else {
                $error = is_array($data) && !empty($data['error']) ? $data['error'] : 'Payment not confirmed yet.';
            }
        } catch (\Throwable $e) {
            $error = 'Could not reach the licensing portal: ' . $e->getMessage();
        }

        if ($licenseKey !== '') {
            $this->configWriter->save('etechflow_megamenu/license/license_key', $licenseKey);
            $this->configWriter->save('etechflow_megamenu/license/issued_key', $licenseKey);
            $this->configWriter->save('etechflow_megamenu/license/issued_at', (string) time());
            $this->configWriter->save('etechflow_megamenu/license/issued_plan', $plan);
            $this->configWriter->save('etechflow_megamenu/license/revoked', '0');
            $this->cacheTypeList->cleanType('config');
        }

        if ($block) {
            $block->setData('license_key', $licenseKey)
                  ->setData('plan', $plan)
                  ->setData('error', $error)
                  ->setData('settings_url', (string) $this->getUrl('adminhtml/system_config/edit', ['section' => 'etechflow_megamenu']))
                  ->setData('management_url', (string) $this->getUrl('etechflow_megamenu/license/gate'));
        }

        return $page;
    }
}
