<?php

namespace Evalent\AdminActivityLog\Plugin\Config;

use function Composer\Autoload\includeFile;
use Evalent\AdminActivityLog\Api\Data\ConfigActivityInterfaceFactory;
use Evalent\AdminActivityLog\Model\ConfigActivityRepository;
use Magento\Backend\Model\Auth\Session;
use Psr\Log\LoggerInterface;

class AroundSaveConfig
{

    const LOGGER_PREFIX = 'Around config save: ';

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
        \Magento\Config\Model\Config $subject,
        callable $proceed
    ) {
        $section = $subject->getSection();
        $oldConfigs = $this->scopeConfig->getValue($section);
        //Proceed
        $returnValue = $proceed();

        $newConfigs = $this->scopeConfig->getValue($section);
        $changedValues = $this->checkDifference($oldConfigs, $newConfigs);
        $storeView = $subject->getStore();
        $website = $subject->getWebsite();

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
        $activity->setStoreview($storeView);
        $activity->setWebsite($website);
        $activity->setSection($section);
        $activity->setEditedAttributes(json_encode($changedValues));
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


    public function checkDifference($array1, $array2)
    {
        if ($array1 && $array2) {
            foreach ($array1 as $key => $value) {
                if (is_array($value)) {
                    if (!isset($array2[$key])) {
                        $difference[$key] = $value;
                    } elseif (!is_array($array2[$key])) {
                        $difference[$key] = $value;
                    } else {
                        $new_diff = $this->checkDifference($value,
                            $array2[$key]);
                        if ($new_diff != false) {
                            $difference[$key] = $new_diff;
                        }
                    }
                } elseif (isset($array2[$key]) && $array2[$key] != $value) {
                    $difference[$key] = [$value, $array2[$key]];
                }
            }
        }
        return !isset($difference) ? 0 : $difference;
    }
}