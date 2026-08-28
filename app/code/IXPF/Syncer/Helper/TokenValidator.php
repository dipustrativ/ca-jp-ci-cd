<?php

namespace IXPF\Syncer\Helper;

use Magento\Integration\Model\Oauth\TokenFactory;

class TokenValidator
{
    private $tokenFactory;

    public function __construct(
        TokenFactory $tokenFactory
    )
    {
        $this->tokenFactory = $tokenFactory;
    }

    public function checkTokenValidation($token)
    {
        $tokenModel = $this->tokenFactory->create()->loadByToken($token);
        if (!$tokenModel->getId()) {
            throw new \Exception("Unauthorized token");
        }

        return true;
    }
}
