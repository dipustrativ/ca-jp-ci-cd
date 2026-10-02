<?php

/**
 * Adds the "change payment method" text link to the admin-managed order confirmation
 * email templates (customer + guest). The link variable change_payment_url is set by
 * Plugin\Order\Email\Container\Template\ChangePaymentVars for bank-transfer
 * (evinvoicepayment) orders only, so the block stays hidden for every other method.
 *
 * Idempotent: an existing {{depend change_payment_url}} block is replaced, and templates
 * that still reference change_payment_url elsewhere (hand-edited) are left alone.
 */

declare(strict_types=1);

namespace CarpetAvenue\ChangePayment\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddChangePaymentLinkToOrderEmails implements DataPatchInterface
{
    private const ORIG_TEMPLATE_CODES = [
        'sales_email_order_template',
        'sales_email_order_guest_template',
    ];

    private const ANCHOR = '{{layout handle="sales_email_order_items"';

    private const BLOCK_PATTERN = '#\{\{depend change_payment_url\}\}.*?\{\{/depend\}\}\s*#s';

    private const SNIPPET = <<<'HTML'
{{depend change_payment_url}}
            <p>{{trans "クレジットカードなど、別のお支払い方法をご希望の場合は、"}}<a href="{{var change_payment_url|raw}}" target="_blank">{{trans "こちら"}}</a>{{trans "からお支払い方法を変更できます。"}}</p>
            {{/depend}}

HTML;

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('email_template');

        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['template_id', 'template_text'])
                ->where('orig_template_code IN (?)', self::ORIG_TEMPLATE_CODES)
        );

        foreach ($rows as $templateId => $text) {
            // Drop a previously injected block so re-running swaps in the current markup.
            $text = (string)preg_replace(self::BLOCK_PATTERN, '', (string)$text);
            if (str_contains($text, 'change_payment_url') || !str_contains($text, self::ANCHOR)) {
                continue;
            }

            $position = strpos($text, self::ANCHOR);
            $text = substr_replace($text, self::SNIPPET . '            ', $position, 0);

            $connection->update($table, ['template_text' => $text], ['template_id = ?' => (int)$templateId]);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
