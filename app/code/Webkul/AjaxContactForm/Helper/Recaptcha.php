<?php

namespace Webkul\AjaxContactForm\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Recaptcha extends \Magento\Framework\App\Helper\AbstractHelper
{
    private const XML_PATH_PUBLIC_KEY = 'recaptcha_frontend/type_recaptcha_v3/public_key';
    private const XML_PATH_SECRET_KEY = 'recaptcha_frontend/type_recaptcha_v3/private_key';
    private const XML_PATH_POSITION = 'recaptcha_frontend/type_recaptcha_v3/position';
    private const XML_PATH_THEME = 'recaptcha_frontend/type_recaptcha_v3/theme';
    private const XML_PATH_LANGUAGE_CODE = 'recaptcha_frontend/type_recaptcha_v3/lang';

    /**
     * @param Context $context
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context);
    }

    public function getRecaptchaType()
    {
        return $this->scopeConfig->getValue('recaptcha_frontend/type_for/ordercatalogue');
    }

    public function getRecaptchaSiteKey()
    {
        $type = $this->getRecaptchaType();
        if ($type === 'recaptcha_v3') {
            return $this->scopeConfig->getValue(self::XML_PATH_PUBLIC_KEY, ScopeInterface::SCOPE_STORE) ?: '';
        }
        return '';
    }

    public function getRecaptchaSecretKey()
    {
        $type = $this->getRecaptchaType();
        if ($type === 'recaptcha_v3') {
            return $this->scopeConfig->getValue('recaptcha_v3/secret_key');
        } else {
            return $this->scopeConfig->getValue('recaptcha/secret_key');
        }
    }

    public function getRecaptchaLang()
    {
        return $this->scopeConfig->getValue(self::XML_PATH_LANGUAGE_CODE, ScopeInterface::SCOPE_STORE) ?: 'en';
    }

    public function getRecaptchaAttribution($langCode = null): string
    {
        $recaptchaAttribution = [
            // Languages from your image:
            'da' => 'Dette websted er beskyttet af reCAPTCHA, og Googles <a href="https://policies.google.com/privacy?hl=da" target="_blank">privatlivspolitik</a> og <a href="https://policies.google.com/terms?hl=da" target="_blank">servicevilkår</a> gælder.', // Dansk (Danish)
            'de' => 'Diese Website ist durch reCAPTCHA geschützt und es gelten die <a href="https://policies.google.com/privacy?hl=de" target="_blank">Datenschutzbestimmungen</a> und <a href="https://policies.google.com/terms?hl=de" target="_blank">Nutzungsbedingungen</a> von Google.', // Deutsch (German)
            'es' => 'Este sitio está protegido por reCAPTCHA y se aplican la <a href="https://policies.google.com/privacy?hl=es" target="_blank">Política de privacidad</a> y los <a href="https://policies.google.com/terms?hl=es" target="_blank">Términos del servicio</a> de Google.', // Español (Spanish)
            'fr' => 'Ce site est protégé par reCAPTCHA et les <a href="https://policies.google.com/privacy?hl=fr" target="_blank">Règles de confidentialité</a> et les <a href="https://policies.google.com/terms?hl=fr" target="_blank">Conditions d\'utilisation</a> de Google s\'appliquent.', // Français (French)
            'it' => 'Questo sito è protetto da reCAPTCHA e si applicano la <a href="https://policies.google.com/privacy?hl=it" target="_blank">Normativa sulla privacy</a> e i <a href="https://policies.google.com/terms?hl=it" target="_blank">Termini di servizio</a> di Google.', // Italiano (Italian)
            'ja' => 'このサイトは reCAPTCHA によって保護されており、Google の<a href="https://policies.google.com/privacy?hl=ja" target="_blank">プライバシー ポリシー</a>と<a href="https://policies.google.com/terms?hl=ja" target="_blank">利用規約</a>が適用されます。', // 日本語 (Japanese)
            'hu' => 'Ez az oldal reCAPTCHA védelem alatt áll, és a Google <a href="https://policies.google.com/privacy?hl=hu" target="_blank">Adatvédelmi irányelvei</a> és <a href="https://policies.google.com/terms?hl=hu" target="_blank">Szolgáltatási feltételei</a> érvényesek.', // Magyar (Hungarian)
            'nl' => 'Deze site is beschermd door reCAPTCHA en de <a href="https://policies.google.com/privacy?hl=nl" target="_blank">Privacybeleid</a> en <a href="https://policies.google.com/terms?hl=nl" target="_blank">Servicevoorwaarden</a> van Google zijn van toepassing.', // Nederlands (Dutch)
            'no' => 'Dette nettstedet er beskyttet av reCAPTCHA, og Googles <a href="https://policies.google.com/privacy?hl=no" target="_blank">personvernregler</a> og <a href="https://policies.google.com/terms?hl=no" target="_blank">vilkår for bruk</a> gjelder.', // Norsk (Norwegian)
            'pl' => 'Ta witryna jest chroniona przez reCAPTCHA i obowiązują <a href="https://policies.google.com/privacy?hl=pl" target="_blank">Polityka prywatności</a> oraz <a href="https://policies.google.com/terms?hl=pl" target="_blank">Warunki korzystania z usługi</a> Google.', // Polski (Polish)
            'pt' => 'Este site é protegido pelo reCAPTCHA e aplicam-se a <a href="https://policies.google.com/privacy?hl=pt" target="_blank">Política de Privacidade</a> e os <a href="https://policies.google.com/terms?hl=pt" target="_blank">Termos de Serviço</a> da Google.', // Português (Portuguese)
            'fi' => 'Tämä sivusto on suojattu reCAPTCHA-palvelulla, ja Googlen <a href="https://policies.google.com/privacy?hl=fi" target="_blank">tietosuojakäytäntö</a> ja <a href="https://policies.google.com/terms?hl=fi" target="_blank">palveluehdot</a> ovat voimassa.', // Suomi (Finnish)
            'sv' => 'Denna webbplats skyddas av reCAPTCHA och Googles <a href="https://policies.google.com/privacy?hl=sv" target="_blank">Sekretesspolicy</a> och <a href="https://policies.google.com/terms?hl=sv" target="_blank">Användarvillkor</a> gäller.', // Svenska (Swedish)

            // Default fallback (English)
            'default' => 'This site is protected by reCAPTCHA and the Google <a href="https://policies.google.com/privacy?hl=en" target="_blank">Privacy Policy</a> and <a href="https://policies.google.com/terms?hl=en" target="_blank">Terms of Service</a> apply.',
            'en' => 'This site is protected by reCAPTCHA and the Google <a href="https://policies.google.com/privacy?hl=en" target="_blank">Privacy Policy</a> and <a href="https://policies.google.com/terms?hl=en" target="_blank">Terms of Service</a> apply.'
        ];
        $detectedLang = 'en';

        if ($langCode && isset($recaptchaAttribution[$langCode])) {
            $detectedLang = $langCode;
        } else {
            if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                $httpAcceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
                preg_match_all('/([a-z]{2}(-[A-Z]{2})?)(;q=\d\.\d)?/i', $httpAcceptLanguage, $matches);
                $languages = $matches[1];

                foreach ($languages as $lang) {
                    $baseLang = strtolower(explode('-', $lang)[0]); // e.g., 'en' from 'en-US'
                    if (isset($recaptchaAttribution[$baseLang])) {
                        $detectedLang = $baseLang;
                        break;
                    }
                }
            }
        }

        return $recaptchaAttribution[$detectedLang] ?? $recaptchaAttribution['default'];
    }

    public function uniqueId($identifier, $field): string
    {
        return $identifier . '_' . $field;
    }
}
