<?php


namespace Evalent\Banner\Block\Adminhtml\Banner\Edit;

use Evalent\Banner\Api\BannerRepositoryInterface;
use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Authorization\PolicyInterface;
use Magento\Framework\Exception\LocalizedException;

abstract class GenericButton
{

    protected $context;

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    protected $authSession;

    /**
     * @var \Magento\Framework\Authorization\PolicyInterface
     */
    protected $policy;

    /**
     * @var \Evalent\Banner\Api\BannerRepositoryInterface
     */
    private $bannerRepository;

    private $banner;

    public function __construct(
        BannerRepositoryInterface $bannerRepository,
        Context $context,
        Session $authSession,
        PolicyInterface $policy
    ) {
        $this->context = $context;
        $this->authSession = $authSession;
        $this->policy = $policy;
        $this->bannerRepository = $bannerRepository;
    }
    /**
     * Return model ID
     *
     * @return int|null
     */
    public function getModelId()
    {
        return $this->context->getRequest()->getParam('entity_id');
    }

    protected function getModel()
    {
        if ($this->banner != null) {
            return $this->banner;
        }
        try {
            $this->banner = $this->bannerRepository->get($this->getModelId());
        } catch (LocalizedException $e) {

        }
        return $this->banner;
    }

    /**
     * Generate url by route and parameters
     *
     * @param   string $route
     * @param   array $params
     * @return  string
     */
    public function getUrl($route = '', $params = [])
    {
        return $this->context->getUrlBuilder()->getUrl($route, $params);
    }
}
