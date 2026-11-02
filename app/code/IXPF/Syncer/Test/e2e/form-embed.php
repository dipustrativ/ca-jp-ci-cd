<?php
/**
 * Checks for the review form embed URL builder.
 *
 * The same assertions live in Test/Unit/ViewModel/Reviews/FormEmbedTest.php, which is the file to
 * read. This runner exists because vendor/bin/phpunit does not boot in this project ("Subscriber
 * PHPUnit\Event\Test\DataProviderMethodCalledSubscriber does not exist"), so the unit suite cannot
 * actually be executed here. Delete this once PHPUnit works.
 *
 * Run: docker exec -i -w /var/www/html magento_backend \
 *          php app/code/IXPF/Syncer/Test/e2e/form-embed.php
 */
declare(strict_types=1);

require '/var/www/html/app/bootstrap.php';

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\ReviewLogger;
use IXPF\Syncer\ViewModel\Reviews\FormEmbed;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

const VALID_TOKEN = 'eessG37jcBLrMOzfjhKaOiFvXwNdNkF7Uqkm7JbzLOs';
const STORE_URL   = 'https://www.carpetavenue.de/';

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   $name\n";
        return;
    }
    $fail++;
    echo "  FAIL $name" . ($detail !== '' ? " -- $detail" : '') . "\n";
}

function viewModel(
    array $params,
    string $baseUrl = 'https://review.carpetavenue.com',
    bool $enabled = true
): FormEmbed {
    $request = new class ($params) implements RequestInterface {
        public function __construct(private array $params)
        {
        }

        public function getParam($key, $defaultValue = null)
        {
            return $this->params[$key] ?? $defaultValue;
        }

        public function getModuleName()
        {
            return 'reviews';
        }

        public function setModuleName($name)
        {
            return $this;
        }

        public function getActionName()
        {
            return 'index';
        }

        public function setActionName($name)
        {
            return $this;
        }

        public function setParams(array $params)
        {
            $this->params = $params;
            return $this;
        }

        public function getParams()
        {
            return $this->params;
        }

        public function getCookie($name, $default)
        {
            return $default;
        }

        public function isSecure()
        {
            return true;
        }
    };

    $config = new class ($baseUrl, $enabled) extends Config {
        public function __construct(private string $baseUrl, private bool $enabled)
        {
        }

        public function isReviewFormEnabled(): bool
        {
            return $this->enabled;
        }

        public function getReviewFormBaseUrl(): string
        {
            return $this->baseUrl;
        }
    };

    $storeManager = new class implements StoreManagerInterface {
        public function getStore($storeId = null)
        {
            return new class extends Store {
                public function __construct()
                {
                }

                public function getBaseUrl($type = self::URL_TYPE_LINK, $secure = null)
                {
                    return STORE_URL;
                }
            };
        }

        public function setIsSingleStoreModeAllowed($value)
        {
        }

        public function hasSingleStore()
        {
            return false;
        }

        public function isSingleStoreMode()
        {
            return false;
        }

        public function getStores($withDefault = false, $codeKey = false)
        {
            return [];
        }

        public function getWebsite($websiteId = null)
        {
            return null;
        }

        public function getWebsites($withDefault = false, $codeKey = false)
        {
            return [];
        }

        public function reinitStores()
        {
        }

        public function getDefaultStoreView()
        {
            return null;
        }

        public function getGroup($groupId = null)
        {
            return null;
        }

        public function getGroups($withDefault = false)
        {
            return [];
        }

        public function setCurrentStore($store)
        {
        }
    };

    $logger = new class extends ReviewLogger {
        public function __construct()
        {
        }

        public function writeDaily($message, string $type = 'info'): bool
        {
            return true;
        }
    };

    return new FormEmbed($request, $config, $storeManager, $logger);
}

echo "Review form embed URL builder\n";

$url = viewModel(['token' => VALID_TOKEN, 'sku' => 'VAN211640', 'rating' => '4'])->getFormUrl();
check('builds the /r/<token> path', str_starts_with($url, 'https://review.carpetavenue.com/r/' . VALID_TOKEN . '?'), $url);
check('keeps a valid sku', str_contains($url, 'sku=VAN211640'), $url);
check('keeps a valid rating', str_contains($url, 'rating=4'), $url);
check('passes the parent origin', str_contains($url, 'parent=' . rawurlencode('https://www.carpetavenue.de')), $url);

$url = viewModel(['token' => VALID_TOKEN, 'redirect' => 'https://evil.example', 'foo' => 'bar'])->getFormUrl();
check('drops unknown params', !str_contains($url, 'evil.example') && !str_contains($url, 'foo'), $url);

foreach ([
    'missing token'     => null,
    'empty token'       => '',
    'short token'       => 'abc',
    'traversal token'   => '../../etc/passwd',
    'query break token' => 'abcdefghijklmnop&admin=1',
    'quoted token'      => 'abcdefghijklmnop"onload="x',
    'overlong token'    => str_repeat('a', 129),
] as $name => $token) {
    check("rejects $name", !viewModel(['token' => $token])->isAvailable());
}

foreach ([
    'attribute-breaking sku' => 'A" onload="x',
    'sku with a space'       => 'VAN 211640',
    'overlong sku'           => str_repeat('A', 65),
] as $name => $sku) {
    $vm = viewModel(['token' => VALID_TOKEN, 'sku' => $sku]);
    check("drops $name but still renders", $vm->isAvailable() && !str_contains($vm->getFormUrl(), 'sku='), $vm->getFormUrl());
}

foreach (['1' => true, '5' => true, '0' => false, '6' => false, '-3' => false, 'four' => false] as $rating => $kept) {
    $url = viewModel(['token' => VALID_TOKEN, 'rating' => (string)$rating])->getFormUrl();
    check("rating \"$rating\" " . ($kept ? 'kept' : 'dropped'), str_contains($url, 'rating=') === $kept, $url);
}

check('refuses a non-https base url', !viewModel(['token' => VALID_TOKEN], 'http://review.carpetavenue.com')->isAvailable());
check('refuses an empty base url', !viewModel(['token' => VALID_TOKEN], '')->isAvailable());
check('honours the config switch', !viewModel(['token' => VALID_TOKEN], 'https://review.carpetavenue.com', false)->isAvailable());

check(
    'keeps the port in the expected origin',
    viewModel(['token' => VALID_TOKEN], 'https://localhost:8443')->getExpectedOrigin() === 'https://localhost:8443'
);
check(
    'does not double the slash after a trailing-slash base url',
    str_starts_with(viewModel(['token' => VALID_TOKEN], 'https://review.carpetavenue.com/')->getFormUrl(), 'https://review.carpetavenue.com/r/')
);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
