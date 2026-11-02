<?php

namespace IXPF\Syncer\Helper;

use Magento\Integration\Model\Oauth\Token;
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
        // oauth_token also holds request and verifier tokens, and a revoked integration keeps its
        // row - existence alone would let either of those authenticate.
        if (!$tokenModel->getId()
            || $tokenModel->getRevoked()
            || $tokenModel->getType() !== Token::TYPE_ACCESS
        ) {
            throw new \Exception("Unauthorized token");
        }

        return true;
    }
}
