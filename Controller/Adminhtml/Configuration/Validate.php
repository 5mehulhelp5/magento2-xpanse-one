<?php

namespace xpanse\Payment\Controller\Adminhtml\Configuration;

use xpanse\Payment\Model\Config;
use Exception;
use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use xpanse\Sdk as xpanseSDK;
use xpanse\Sdk\HttpWrapper;
use xpanse\Sdk\ResponseException;
use xpanse\Sdk\UrlTools;

class Validate extends Action
{
    const ADMIN_RESOURCE = 'Magento_Config::config';

    /**
     * @var Config
     */
    protected $config;

    protected $cacheTypeList;

    protected $_encryptor;

    /**
     * Validate constructor.
     * @param Action\Context $context
     * @param Config $config
     */
    public function __construct(
        Action\Context $context,
        Config $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
        $this->config = $config;
        $this->cacheTypeList = $cacheTypeList;
        $this->_encryptor = $encryptor;
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $this->cacheTypeList->cleanType('config');
        $publicKey = $this->getRequest()->getParam('public_key');
        $privateKey = $this->getRequest()->getParam('private_key');
        $storeId = $this->getRequest()->getParam('storeId', 0);
        $environment = $this->getRequest()->getParam('environment');

        if (false !== strpos($publicKey, '*')) {
            if ($environment === Config::ENV_SANDBOX) {
                $publicKey = $this->_encryptor->decrypt($this->config->getValue(Config::SANDBOX_PUBLIC_KEY, $storeId));
            } else {
                $publicKey = $this->_encryptor->decrypt($this->config->getValue(Config::LIVE_PUBLIC_KEY, $storeId));
            }
        }

        if (false !== strpos($privateKey, '*')) {
            if ($environment === Config::ENV_SANDBOX) {
                $privateKey = $this->_encryptor->decrypt($this->config->getValue(Config::SANDBOX_SECRET_KEY, $storeId));
            } else {
                $privateKey = $this->_encryptor->decrypt($this->config->getValue(Config::LIVE_SECRET_KEY, $storeId));
            }
        }

        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            xpanseSDK\Config::initialise($privateKey, $environment);

            if ($this->checkSecretKey() && $this->checkPublicKey($publicKey)) {
                $response->setData(['success' => 'true']);
            } else {
                $response->setData(['success' => 'false']);
            }

        } catch (Exception $e) {
            $response->setData(['success' => 'false']);
        }

        return $response;
    }

    protected function checkSecretKey()
    {
        $svc = new xpanseSDK\Info();
        $result = $svc->Providers([]);
        if (array_key_exists('hasCardProviders', $result)) {
            return true;
        }
        return false;
    }

    protected function checkPublicKey($key)
    {
        $result = $this->ProvidersByPublicKey($key);
        if (array_key_exists('hasCardProviders', $result)) {
            return true;
        }
        return false;
    }

    public function ProvidersByPublicKey($publicKey)
    {
        try {
            $url = '/info/providers';
            $addHeaders = [
                'x-publickey' => $publicKey,
                'x-secretkey' => 'secret', // override the existing secret to validate public key
            ];
            return HttpWrapper::CallApi($url, 'GET', '', $addHeaders);
        } catch (\Exception $ex) {
            throw new ResponseException($ex->getMessage(), 0, 0, false);
        }
    }
}
