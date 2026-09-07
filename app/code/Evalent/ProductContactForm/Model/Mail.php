<?php

declare(strict_types=1);

namespace Evalent\ProductContactForm\Model;

use Evalent\ProductContactForm\Helper\Data;
use Magento\Contact\Model\ConfigInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;

class Mail implements MailInterface
{
    /**
     * @var ConfigInterface
     */
    private $contactsConfig;

    /**
     * @var TransportBuilder
     */
    private $transportBuilder;

    /**
     * @var StateInterface
     */
    private $inlineTranslation;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Evalent\ProductContactForm\Helper\Data
     */
    private $dataHelper;

    /**
     * Initialize dependencies.
     *
     * @param \Evalent\ProductContactForm\Helper\Data $dataHelper
     * @param ConfigInterface                         $contactsConfig
     * @param TransportBuilder                        $transportBuilder
     * @param StateInterface                          $inlineTranslation
     * @param StoreManagerInterface|null              $storeManager
     */
    public function __construct(
        Data $dataHelper,
        ConfigInterface $contactsConfig,
        TransportBuilder $transportBuilder,
        StateInterface $inlineTranslation,
        ?StoreManagerInterface $storeManager = null
    ) {
        $this->contactsConfig = $contactsConfig;
        $this->transportBuilder = $transportBuilder;
        $this->inlineTranslation = $inlineTranslation;
        $this->storeManager = $storeManager ?: ObjectManager::getInstance()->get(StoreManagerInterface::class);
        $this->dataHelper = $dataHelper;
    }

    /**
     * Send email from contact form
     *
     * @param string $replyTo
     * @param array $variables
     * @return void
     */
    public function send($replyTo, array $variables)
    {
        /** @see \Magento\Contact\Controller\Index\Post::validatedParams() */
        $replyToName = !empty($variables['data']['name']) ? $variables['data']['name'] : null;

        $this->inlineTranslation->suspend();
        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->dataHelper->getTemplate())
                ->setTemplateOptions(
                    [
                        'area' => Area::AREA_FRONTEND,
                        'store' => $this->storeManager->getStore()->getId()
                    ]
                )
                ->setTemplateVars($variables)
                ->setFrom($this->dataHelper->getFrom())
                ->addTo($this->dataHelper->getReceivers())
                ->addCc($this->dataHelper->getCcReceivers())
                ->setReplyTo($replyTo, $replyToName)
                ->getTransport();

            $transport->sendMessage();
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
