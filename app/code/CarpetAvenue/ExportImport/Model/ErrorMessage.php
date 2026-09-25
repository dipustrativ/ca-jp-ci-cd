<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Order
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Model;

/**
 * Turns an exception into a message that names the actual cause.
 *
 * Magento's sales repositories wrap whatever went wrong in a generic
 * CouldNotSaveException ("The shipment couldn't be saved.", "The invoice
 * couldn't be saved.") and keep the real error — a constraint violation, a
 * value too long for its column — only as the previous exception. Reporting
 * just the outer message leaves the operator nothing to fix.
 */
class ErrorMessage
{
    /** Stop following the chain after this many links. */
    private const MAX_DEPTH = 5;

    public static function describe(\Throwable $e): string
    {
        $messages = [];
        for ($depth = 0; $e !== null && $depth < self::MAX_DEPTH; $depth++, $e = $e->getPrevious()) {
            $message = trim($e->getMessage());
            if ($message !== '' && !in_array($message, $messages, true)) {
                $messages[] = $message;
            }
        }
        return implode(' — caused by: ', $messages);
    }
}
