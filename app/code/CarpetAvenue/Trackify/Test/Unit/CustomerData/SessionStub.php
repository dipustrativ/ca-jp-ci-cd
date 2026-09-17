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

namespace CarpetAvenue\Trackify\Test\Unit\CustomerData;

use Magento\Framework\Session\SessionManager;

/**
 * In-memory stand-in for the session, for TrackifyTest.
 *
 * SessionManager declares getData() but routes setData() through __call, so it cannot be
 * doubled the usual way — and PHPUnit 12 removed getMockBuilder()->addMethods(), which is
 * how that used to be worked around. Overriding the constructor skips the real one's
 * dependencies.
 */
class SessionStub extends SessionManager
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * Deliberately does not call parent::__construct().
     */
    public function __construct()
    {
    }

    /**
     * @param string $key
     * @param bool $clear
     * @return mixed
     */
    public function getData($key = '', $clear = false)
    {
        $value = $this->data[$key] ?? null;

        if ($clear) {
            unset($this->data[$key]);
        }

        return $value;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setData($key, $value = null)
    {
        $this->data[$key] = $value;

        return $this;
    }
}
