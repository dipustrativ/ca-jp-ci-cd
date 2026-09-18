<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Marketing
 * @package   CarpetAvenue_Trackify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Trackify\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends GA4 events server-side over the Measurement Protocol.
 *
 * Only `refund` uses this. Everything else in this module reaches GA4 through the GTM
 * container in the browser, which is both cheaper and better attributed; a refund has no
 * browser to run in — it is raised in the admin or pushed by IXPF from the ERP — so it is
 * the one event that has to be reported from PHP.
 *
 * Config is read through ScopeConfigInterface with an explicit store id rather than
 * through Core's SysConfig helper, because that helper resolves against the *current*
 * store and a refund is processed in the admin scope, where the current store is 0.
 */
class MeasurementProtocol
{
    private const XML_PATH_ACTIVE = 'carpetavenue_analytics/measurement_protocol/active';
    private const XML_PATH_MEASUREMENT_ID = 'carpetavenue_analytics/measurement_protocol/measurement_id';
    private const XML_PATH_API_SECRET = 'carpetavenue_analytics/measurement_protocol/api_secret';
    private const XML_PATH_DEBUG = 'carpetavenue_analytics/measurement_protocol/debug';

    private const ENDPOINT = 'https://www.google-analytics.com/mp/collect';

    /**
     * Validates a payload and returns what is wrong with it instead of recording it.
     * Nothing sent here reaches a report — it is for testing only.
     */
    private const DEBUG_ENDPOINT = 'https://www.google-analytics.com/debug/mp/collect';

    /**
     * This runs inline with the refund, so the ceiling stays low: a Google outage must not
     * hold an admin refund — or an IXPF bulk credit-memo sync — open. If refund volume
     * ever makes even this matter, move the send to a queue consumer; the retry behaviour
     * is already there, since trackify_refund_sent_at is only written on success.
     */
    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Switched on, and actually configured with both credentials.
     *
     * @param int $storeId
     * @return bool
     */
    public function isEnabled(int $storeId): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ACTIVE, ScopeInterface::SCOPE_STORE, $storeId)) {
            return false;
        }

        return $this->getMeasurementId($storeId) !== '' && $this->getApiSecret($storeId) !== '';
    }

    /**
     * POST a single GA4 event.
     *
     * Returns false on anything other than a clean acceptance, so the caller can leave the
     * credit memo unflagged and let a later save retry it.
     *
     * @param array $event GA4 Measurement Protocol event: ['name' => ..., 'params' => [...]]
     * @param string $clientId
     * @param int $storeId
     * @return bool
     */
    public function send(array $event, string $clientId, int $storeId): bool
    {
        if ($event === [] || $clientId === '' || !$this->isEnabled($storeId)) {
            return false;
        }

        $debug = $this->scopeConfig->isSetFlag(self::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE, $storeId);

        $url = ($debug ? self::DEBUG_ENDPOINT : self::ENDPOINT) . '?' . http_build_query([
            'measurement_id' => $this->getMeasurementId($storeId),
            'api_secret' => $this->getApiSecret($storeId),
        ]);

        $body = (string) json_encode(
            [
                'client_id' => $clientId,
                'events' => [$event],
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::TIMEOUT_SECONDS);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->post($url, $body);

            $status = (int) $curl->getStatus();

            if ($debug) {
                // The debug endpoint answers 200 with a validationMessages array — empty
                // means the payload is good. It still records nothing, so never treat a
                // debug run as a delivered event.
                $this->logger->info('Trackify MP debug: ' . $curl->getBody());

                return false;
            }

            // A live collect returns 204 with no body. Anything else is a failure worth
            // seeing — the endpoint does not explain itself on a 2xx.
            if ($status === 204 || $status === 200) {
                return true;
            }

            $this->logger->warning(
                sprintf('Trackify MP: %s returned HTTP %d — %s', $event['name'] ?? '?', $status, $curl->getBody())
            );
        } catch (Throwable $e) {
            $this->logger->warning('Trackify MP: send failed — ' . $e->getMessage());
        }

        return false;
    }

    /**
     * @param int $storeId
     * @return string
     */
    private function getMeasurementId(int $storeId): string
    {
        $value = trim(
            (string) $this->scopeConfig->getValue(
                self::XML_PATH_MEASUREMENT_ID,
                ScopeInterface::SCOPE_STORE,
                $storeId
            )
        );

        // Same reasoning as the GTM container id: the field is where somebody pastes the
        // wrong thing, so the shape is checked once, here.
        return preg_match('/^G-[A-Z0-9]+$/', $value) === 1 ? $value : '';
    }

    /**
     * @param int $storeId
     * @return string
     */
    private function getApiSecret(int $storeId): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_API_SECRET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($value === '') {
            return '';
        }

        try {
            return trim($this->encryptor->decrypt($value));
        } catch (Throwable) {
            // A value saved before the backend model was in place is stored in clear.
            return trim($value);
        }
    }
}
