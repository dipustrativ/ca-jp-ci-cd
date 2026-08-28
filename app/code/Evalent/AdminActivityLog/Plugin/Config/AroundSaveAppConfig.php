<?php

namespace Evalent\AdminActivityLog\Plugin\Config;

use function Composer\Autoload\includeFile;
use Evalent\AdminActivityLog\Api\Data\ConfigActivityInterfaceFactory;
use Evalent\AdminActivityLog\Model\ConfigActivityRepository;
use Magento\Backend\Model\Auth\Session;
use Psr\Log\LoggerInterface;

class AroundSaveAppConfig
{

    const LOGGER_PREFIX = 'Around app config save: ';

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var \Evalent\AdminActivityLog\Model\ConfigActivityRepository
     */
    private $activityRepository;

    /**
     * @var \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterfaceFactory
     */
    private $activityInterfaceFactory;

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    private $adminSession;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * AroundSaveConfig constructor.
     *
     * @param \Magento\Backend\Model\Auth\Session                               $adminSession
     * @param \Evalent\AdminActivityLog\Model\ConfigActivityRepository          $activityRepository
     * @param \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterfaceFactory $activityInterfaceFactory
     * @param \Magento\Framework\App\Config\ScopeConfigInterface                $scopeConfig
     * @param \Psr\Log\LoggerInterface                                          $logger
     */
    public function __construct(
        Session $adminSession,
        ConfigActivityRepository $activityRepository,
        ConfigActivityInterfaceFactory $activityInterfaceFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->activityRepository = $activityRepository;
        $this->activityInterfaceFactory = $activityInterfaceFactory;
        $this->adminSession = $adminSession;
        $this->logger = $logger;
    }


    public function aroundSave(
        \Magento\Framework\App\Config\Value $subject,
        callable $proceed
    ) {
        $section = $subject->getPath();
        // For some reason it's not possible to grab the old value on some config values
        $oldConfigs = $subject->getOldValue();
        //Proceed
        $returnValue = $proceed();

        $newConfigs = $subject->getValue();

        if (!$subject->isValueChanged()) {
            return $returnValue;
        }

        /** @var \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface $activity */
        $activity = $this->activityInterfaceFactory->create();
        $user = $this->adminSession->getUser();
        if ($user) {
            $activity->setUsername($user->getUserName());
            $activity->setFullName($user->getName());
        } else {
            $activity->setUsername('UNKNOWN/CLI');
            $activity->setFullName('UNKNOWN/CLI');
        }
        $activity->setSection("");
        $activity->setEditedAttributes(json_encode([ $section => [$oldConfigs, $newConfigs]]));
        $activity->setDatetime(date('Y-m-d H:i:s'));

        try {
            $this->activityRepository->save($activity);
        } catch (\Exception $e) {
            $this->logger->error(
                self::LOGGER_PREFIX .
                'There was a problem while saving the config activity.' .
                $e->getMessage()
            );
        }

        return $returnValue;
    }
}
