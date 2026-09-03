<?php

declare(strict_types=1);

namespace Evalent\CheckoutModsJP\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * Strips HTML/script/control characters from billing & shipping address text
 * fields (and the quote-level customer name fields) before the order is placed.
 */
class SanitizeAddressFields implements ObserverInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Text fields where script/HTML must never appear.
     */
    private const FIELDS = [
        'prefix',
        'firstname',
        'middlename',
        'lastname',
        'suffix',
        'company',
        'street',   // array
        'city',
        'postcode',
        'telephone',
        'fax',
        'region',
        'vat_id',
    ];

    /**
     * Quote-level customer name fields (order "customer name" comes from these, not the address).
     */
    private const QUOTE_FIELDS = [
        'customer_prefix',
        'customer_firstname',
        'customer_middlename',
        'customer_lastname',
        'customer_suffix',
    ];

    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        /** @var Quote $quote */
        $quote = $observer->getQuote();
        if (!$quote) {
            return;
        }

        $addressMap = [
            'billing'  => $quote->getBillingAddress(),
            'shipping' => $quote->getShippingAddress(),
        ];

        foreach ($addressMap as $type => $address) {
            if (!$address) {
                continue;
            }
            foreach (self::FIELDS as $field) {
                $value = $address->getData($field);
                if ($value === null || $value === '') {
                    continue;
                }
                $clean = $this->clean($value);
                $this->logSanitize("$type.$field", $value, $clean);
                $address->setData($field, $clean);
            }
        }

        // Customer name on the order is taken from quote-level fields, not the address.
        foreach (self::QUOTE_FIELDS as $field) {
            $value = $quote->getData($field);
            if ($value === null || $value === '') {
                continue;
            }
            $clean = $this->clean($value);
            $this->logSanitize("quote.$field", $value, $clean);
            $quote->setData($field, $clean);
        }
    }

    /**
     * @param string $field
     * @param string|array $before
     * @param string|array $after
     */
    private function logSanitize(string $field, $before, $after): void
    {
        $this->logger->info('[CheckoutModsJP] SanitizeAddressFields', [
            'field'  => $field,
            'before' => is_array($before) ? implode('|', $before) : $before,
            'after'  => is_array($after) ? implode('|', $after) : $after,
        ]);
    }

    /**
     * Remove HTML/script tags from a value (recurses into arrays such as street).
     *
     * @param string|array $value
     * @return string|array
     */
    private function clean($value): string|array
    {
        if (is_array($value)) {
            return array_map([$this, 'clean'], $value);
        }
        $clean = (string) $value;
        $clean = str_replace("\0", '', $clean);                   // null bytes
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $clean);  // control chars
        $clean = strip_tags($clean);                              // HTML tags
        $clean = trim($clean);

        return $clean;
    }
}
