<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * VIES validation against the free official EU service (REST-JSON endpoint).
 * No account or third-party library required.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Model\Vies;

use CarpetAvenue\VAT\Api\ViesValidatorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class EuSoapValidator implements ViesValidatorInterface
{
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    public function __construct(
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function validate(string $countryCode, string $vatNumber): ViesResult
    {
        $countryCode = strtoupper(trim($countryCode));
        $vatNumber = preg_replace('/\s+/', '', trim($vatNumber)) ?? '';

        try {
            $this->curl->setHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);
            $this->curl->setTimeout(15);
            $this->curl->post(self::ENDPOINT, $this->json->serialize([
                'countryCode' => $countryCode,
                'vatNumber' => $vatNumber,
            ]));

            $body = (string)$this->curl->getBody();
            $data = $this->json->unserialize($body);
            if (!is_array($data)) {
                return ViesResult::error(__('Unexpected VIES response.')->render());
            }

            if (!empty($data['userError']) && $data['userError'] !== 'VALID') {
                return ViesResult::error((string)$data['userError']);
            }

            return new ViesResult(
                valid: (bool)($data['valid'] ?? $data['isValid'] ?? false),
                countryCode: (string)($data['countryCode'] ?? $countryCode),
                vatNumber: (string)($data['vatNumber'] ?? $vatNumber),
                requestDate: (string)($data['requestDate'] ?? ''),
                traderName: (string)($data['name'] ?? ''),
                traderAddress: (string)($data['address'] ?? ''),
                requestIdentifier: (string)($data['requestIdentifier'] ?? '')
            );
        } catch (\Throwable $e) {
            $this->logger->error('[VIES][eu_soap] ' . $e->getMessage());
            return ViesResult::error($e->getMessage());
        }
    }
}
