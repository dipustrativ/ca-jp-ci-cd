<?php

declare(strict_types=1);

namespace IXPF\Syncer\ViewModel\Reviews;

use IXPF\Syncer\Api\Data\ReviewFilterInterface;
use IXPF\Syncer\Api\Data\ReviewListInterface;
use IXPF\Syncer\Api\Data\ReviewSummaryInterface;
use IXPF\Syncer\Api\ReviewProviderInterface;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Model\Reviews\ReviewFilterFactory;
use IXPF\Syncer\Model\Reviews\ReviewProvider;
use IXPF\Syncer\Services\Reviews\ReviewMediaStorage;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use NumberFormatter;
use Throwable;

/**
 * Everything the review drawer templates need.
 *
 * One view model serves both paths on purpose:
 *
 * - On the product page it answers from the `review_count` / `review_average` product attributes
 *   only. That is the whole reason those attributes exist - the trigger renders inside
 *   full-page-cached HTML and must not cost a query.
 * - On the AJAX render it reads the request itself and goes to ReviewProviderInterface. No
 *   controller-to-block wiring, and the summary and list templates stay dumb.
 *
 * The product id comes from the registry on the product page and from the request on the AJAX
 * call. The review group is never taken from the request: the provider resolves it from the
 * product, because a caller-supplied group key would turn the endpoint into a way to enumerate
 * every pool on the platform.
 */
class Reviews implements ArgumentInterface
{
    /** Regional indicator symbol letter A - the base for building flag emoji from a country code. */
    private const FLAG_OFFSET = 0x1F1E6;

    private ?ReviewListInterface $list = null;
    private ?ReviewSummaryInterface $summary = null;
    private ?string $resolvedGroup = null;
    private bool $groupResolved = false;

    public function __construct(
        private readonly ReviewProviderInterface $provider,
        private readonly ReviewFilterFactory $filterFactory,
        private readonly ReviewMediaStorage $storage,
        private readonly Config $config,
        private readonly Registry $registry,
        private readonly ResolverInterface $localeResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone,
        private readonly UrlInterface $url,
        private readonly RequestInterface $request
    ) {
    }

    // ---------------------------------------------------------------- product page (FPC safe)

    /**
     * Storefront display switch, separate from the read-service master. Already ANDed with the
     * master inside Config, so this is the only check a template needs.
     */
    public function isFrontendEnabled(): bool
    {
        return $this->config->isReviewsFrontendEnabled();
    }

    public function getProduct(): ?Product
    {
        $product = $this->registry->registry('current_product');

        return $product instanceof Product ? $product : null;
    }

    public function getProductId(): int
    {
        $product = $this->getProduct();
        if ($product !== null) {
            return (int)$product->getId();
        }

        return (int)$this->request->getParam('product_id', 0);
    }

    /**
     * Read straight off the loaded product. No query, no provider call - safe inside cached HTML.
     */
    public function getAttributeCount(): int
    {
        return (int)($this->getProduct()?->getData('review_count') ?? 0);
    }

    public function getAttributeAverage(): float
    {
        return (float)($this->getProduct()?->getData('review_average') ?? 0);
    }

    /**
     * Whether to render the trigger at all. Deliberately decided from the product attributes
     * rather than from the pool, so an empty pool costs nothing to discover.
     */
    public function showTrigger(): bool
    {
        return $this->isFrontendEnabled()
            && $this->getAttributeCount() > 0
            && (string)($this->getProduct()?->getData('review_group') ?? '') !== '';
    }

    public function getLoadUrl(): string
    {
        return $this->url->getUrl('ixpfsyncer/reviews/load');
    }

    // ---------------------------------------------------------------- AJAX render

    public function getReviewGroup(): ?string
    {
        if ($this->groupResolved) {
            return $this->resolvedGroup;
        }

        $this->groupResolved = true;

        $productId = $this->getProductId();
        if ($productId > 0) {
            $this->resolvedGroup = $this->provider->getGroupKeyForProduct($productId);
        }

        return $this->resolvedGroup;
    }

    public function getSummary(): ReviewSummaryInterface
    {
        if ($this->summary === null) {
            $group = $this->getReviewGroup();
            $this->summary = $this->provider->getSummary($group ?? '', $this->getLocale());
        }

        return $this->summary;
    }

    public function getList(): ReviewListInterface
    {
        if ($this->list === null) {
            $group = $this->getReviewGroup();
            $this->list = $this->provider->getReviews($group ?? '', $this->getLocale(), $this->getFilter());
        }

        return $this->list;
    }

    /**
     * @return \IXPF\Syncer\Model\Reviews\ReviewItem[]
     */
    public function getItems(): array
    {
        return $this->getList()->getItems();
    }

    public function getFilter(): ReviewFilterInterface
    {
        $hasPhotos = $this->request->getParam('has_photos');
        $withText  = $this->request->getParam('with_text');
        $rating    = $this->request->getParam('rating');

        return $this->filterFactory->create([
            // Clamping happens in ReviewFilter, which is where it belongs - these values come
            // straight off a query string.
            'rating'    => $rating === null || $rating === '' ? null : (int)$rating,
            'hasPhotos' => ($hasPhotos === '1' || $hasPhotos === 'true') ? true : null,
            // Only the on state filters. An unchecked box means "no filter", not "text-free
            // reviews only", so anything other than an explicit 1/true is null.
            'withText'  => ($withText === '1' || $withText === 'true') ? true : null,
            'page'      => (int)$this->request->getParam('page', 1),
            'pageSize'  => $this->getPageSize(),
            'sort'      => (string)$this->request->getParam('sort', ReviewFilterInterface::SORT_NEWEST),
        ]);
    }

    public function getPageSize(): int
    {
        return $this->config->getReviewsPerPage();
    }

    // ---------------------------------------------------------------- filter/sort UI state

    /**
     * @return array<string, string> sort token => label
     */
    public function getSortOptions(): array
    {
        return [
            ReviewFilterInterface::SORT_NEWEST  => (string)__('Latest'),
            ReviewFilterInterface::SORT_HIGHEST => (string)__('Highest rating'),
            ReviewFilterInterface::SORT_LOWEST  => (string)__('Lowest rating'),
        ];
    }

    /**
     * Rating filter options, highest first. Key '' is "any rating".
     *
     * @return array<string, string>
     */
    public function getRatingOptions(): array
    {
        $options = ['' => (string)__('All ratings')];
        for ($stars = 5; $stars >= 1; $stars--) {
            $options[(string)$stars] = $stars === 1
                ? (string)__('%1 star', $stars)
                : (string)__('%1 stars', $stars);
        }

        return $options;
    }

    // ---------------------------------------------------------------- presentation helpers

    /**
     * Flag emoji built from the locale's region, so all fourteen locales work with no lookup
     * table: da_DK -> DK -> two regional indicator symbols.
     *
     * Returns an empty string for anything that is not `xx_YY`, rather than emitting mojibake.
     */
    public function getFlag(string $locale): string
    {
        if (!preg_match('/_([A-Za-z]{2})$/', $locale, $m)) {
            return '';
        }

        $region = strtoupper($m[1]);

        return mb_chr(self::FLAG_OFFSET + ord($region[0]) - 65, 'UTF-8')
            . mb_chr(self::FLAG_OFFSET + ord($region[1]) - 65, 'UTF-8');
    }

    /**
     * The language name, already in the viewer's own language. intl does the work, so there is
     * nothing to translate and nothing to map.
     *
     * Used for the flag's tooltip, and as the fallback when a review's market has no Magento
     * store - see getSourceDomain().
     */
    public function getLanguageName(string $locale): string
    {
        try {
            $name = \Locale::getDisplayLanguage($locale, $this->getLocale());
        } catch (Throwable) {
            return $locale;
        }

        return $name !== '' ? $name : $locale;
    }

    /**
     * What the "automatically translated from ..." note names: the site the review was written on,
     * e.g. `carpetavenue.dk`.
     *
     * The spec is explicit that this is the domain from source_store_code and *not* the language
     * name, and the reason shows up in the data: `de` and `ch_de` reviews both resolve to "German",
     * so on a language name a Swiss customer cannot tell a Swiss review from a German one. The
     * domain separates them.
     *
     * Derived from the store's own base URL rather than a mapping table, so it stays correct per
     * environment with nothing to maintain - locally that yields the .devs host, which is genuinely
     * this environment's domain.
     *
     * IXPF sends markets that are not live in Magento (the same reason locale keys are never
     * validated against store locales), so an unresolvable code falls back to the language name.
     * Losing the precision is fine; losing the note is not.
     */
    public function getSourceDomain(string $storeCode, string $sourceLocale): string
    {
        // Guarded explicitly: source_store_code is not a required payload field, so it can be
        // stored empty - and getStore('') returns the *current* store, which would make the note
        // claim the review was translated from the domain the customer is already reading.
        // Trimmed once and reused, so a padded code still resolves instead of missing the lookup.
        $code = trim($storeCode);
        if ($code === '') {
            return $this->getLanguageName($sourceLocale);
        }

        try {
            $host = (string)parse_url(
                $this->storeManager->getStore($code)->getBaseUrl(),
                PHP_URL_HOST
            );
        } catch (Throwable) {
            return $this->getLanguageName($sourceLocale);
        }

        if ($host === '') {
            return $this->getLanguageName($sourceLocale);
        }

        // The spec's example is `carpetavenue.dk`, not `www.carpetavenue.dk`.
        return preg_replace('/^www\./i', '', $host) ?? $host;
    }

    public function getPercentage(int $count, int $total): int
    {
        return $total > 0 ? (int)round($count / $total * 100) : 0;
    }

    /**
     * The average score, to one decimal, in the store view's own notation.
     *
     * number_format() is hardcoded to a dot, and a dot is a thousands separator in de, fr, it,
     * es, pt, pl, sv, da, nb, nl, fi and hu - twelve of the fourteen locales this store runs.
     * "3.7 von 5" reads as a formatting bug to a German customer; "3,7 von 5" does not.
     *
     * NumberFormatter is instantiated directly rather than through a factory: this version of
     * the framework has no NumberFormatterFactory (only Intl\DateTimeFactory), and it is an intl
     * value object, not an injectable service.
     */
    public function formatAverage(float $average): string
    {
        $formatter = new NumberFormatter($this->localeResolver->getLocale(), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 1);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 1);

        return (string)$formatter->format($average);
    }

    /**
     * URL for the bought-item thumbnail, or null when the fetch has not landed.
     *
     * thumbnail_local has two shapes and only one of them has derivatives. A downloaded file
     * lives under ixpf/reviews/ and ReviewMediaConsumer::fetch() generated its 80px version. A
     * thumbnail resolved from the product gallery instead keeps the gallery path and never went
     * through fetch(), so no derivative was ever written - asking for one there 404s.
     */
    public function getThumbnailUrl(?string $thumbnailPath): ?string
    {
        if ($thumbnailPath === null || $thumbnailPath === '') {
            return null;
        }

        $path = str_starts_with($thumbnailPath, ReviewMediaStorage::ROOT . '/')
            ? $this->storage->derivativePath($thumbnailPath, ReviewMediaStorage::SIZE_THUMB)
            : $thumbnailPath;

        return $this->getMediaUrl($path);
    }

    /**
     * Media-relative paths come back from the provider; the base URL is added here so the
     * templates never build URLs.
     */
    public function getMediaUrl(string $relativePath): string
    {
        return rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/')
            . '/' . ltrim($relativePath, '/');
    }

    /**
     * Stored timestamps are UTC; render them in the store's timezone.
     */
    public function formatDate(?string $sqlDate): string
    {
        if ($sqlDate === null || $sqlDate === '') {
            return '';
        }

        try {
            return $this->timezone->date($sqlDate)->format('Y-m-d');
        } catch (Throwable) {
            return substr($sqlDate, 0, 10);
        }
    }

    /**
     * Whole stars to paint filled.
     *
     * Floor, not round: the fraction becomes a half star instead, so 3.7 paints three full and one
     * half rather than four full. Rounding made the glyphs claim more than the number printed
     * beside them.
     */
    public function getFilledStars(float $rating): int
    {
        return max(0, min(5, (int)floor($rating)));
    }

    /**
     * Whether the star after the filled ones is half filled.
     *
     * Any fraction at all counts, matching the reference design where 4.8 shows as four and a
     * half. False at 5.0 and above, or the row would try to paint a sixth star, and false at or
     * below 0 so a nonsensical rating cannot paint a lone half with nothing filled behind it.
     */
    public function hasHalfStar(float $rating): bool
    {
        return $rating > 0.0 && $rating < 5.0 && $rating - floor($rating) > 0.0;
    }

    /**
     * One row of five stars.
     *
     * Plain glyphs, spaced by CSS. Filled boxes were tried and rejected - at the sizes this UI
     * uses, five coloured blocks side by side read as a solid bar and crowd everything near them.
     *
     * Built here rather than repeated in four templates so the markup has a single definition.
     * Nothing interpolated comes from outside: $rating becomes an int index and $size is matched
     * against a fixed whitelist, so a caller cannot inject markup through them. Call sites can
     * therefore use @noEscape safely.
     *
     * The glyphs are decorative and hidden from assistive tech; $label supplies the text a
     * screen reader actually reads. Without it the rating is simply absent for those users,
     * which is the whole point of the component.
     *
     * @param string $size '' | 'small' | 'large'
     * @param string $label accessible text, e.g. "4 out of 5"
     */
    public function getStarsHtml(
        float $rating,
        string $size = '',
        string $label = ''
    ): string {
        $filled = $this->getFilledStars($rating);
        $halfAt = $this->hasHalfStar($rating) ? $filled + 1 : 0;

        $classes = ['ixpf-stars'];
        if (in_array($size, ['small', 'large'], true)) {
            $classes[] = 'ixpf-stars--' . $size;
        }

        $stars = '';
        for ($i = 1; $i <= 5; $i++) {
            // Whole ratings (every per-review and sub-rating value) never reach the half branch.
            $state = match (true) {
                $i <= $filled  => ' is-filled',
                $i === $halfAt => ' is-half',
                default        => '',
            };
            $stars .= '<span class="ixpf-stars__star' . $state . '">&#9733;</span>';
        }

        // $label reaches here from __() with an interpolated number, so it is escaped rather
        // than trusted - everything else in this string is a literal or an int.
        $srText = $label === ''
            ? ''
            : '<span class="ixpf-stars__sr-only">'
                . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</span>';

        return $srText
            . '<span class="' . implode(' ', $classes) . '" aria-hidden="true">' . $stars . '</span>';
    }

    /**
     * The three sub-rating rows are hidden entirely when a review carries none. IXPF sends
     * sub_ratings as optional, and real pools contain reviews with all three null.
     *
     * @param array<string, int|null> $subRatings
     */
    public function hasSubRatings(array $subRatings): bool
    {
        foreach ($subRatings as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> sub-rating key => label
     */
    /**
     * "(3 reviews)" / "(1 review)".
     *
     * The distribution rows already pick between "%1 star" and "%1 stars"; the count did not,
     * so a pool with one review read "(1 reviews)" in every language.
     */
    public function getCountLabel(int $count): string
    {
        return $count === 1
            ? (string)__('(%1 review)', $count)
            : (string)__('(%1 reviews)', $count);
    }

    /**
     * The drawer headline, e.g. "Based on 79 ratings and 41 reviews".
     *
     * Two numbers because they mean different things: every rating counts towards the average,
     * only the ones with a written body are readable. Built from two already-pluralised fragments
     * rather than one four-way string, so a translator can reorder them and no locale ends up
     * with "1 ratings".
     *
     * Falls back to the ratings half alone when nothing has a body - a pool IXPF pushed before
     * with_text_count existed reports 0, and "and 0 reviews" reads as an error rather than a fact.
     */
    public function getSummaryCountLabel(int $count, int $withTextCount): string
    {
        $ratings = $count === 1
            ? (string)__('%1 rating', $count)
            : (string)__('%1 ratings', $count);

        if ($withTextCount < 1) {
            return (string)__('Based on %1', $ratings);
        }

        $written = $withTextCount === 1
            ? (string)__('%1 review', $withTextCount)
            : (string)__('%1 reviews', $withTextCount);

        return (string)__('Based on %1 and %2', $ratings, $written);
    }

    /**
     * Accessible name for the product-page trigger, which carries the same count and therefore
     * the same singular problem.
     */
    public function getTriggerLabel(float $average, int $count): string
    {
        return $count === 1
            ? (string)__('Rated %1 out of 5. Read %2 review', $this->formatAverage($average), $count)
            : (string)__('Rated %1 out of 5. Read %2 reviews', $this->formatAverage($average), $count);
    }

    public function getSubRatingLabels(): array
    {
        return [
            'quality'          => (string)__('Quality'),
            'delivery'         => (string)__('Delivery'),
            'customer_service' => (string)__('Customer service'),
        ];
    }

    public function getLocale(): string
    {
        return $this->localeResolver->getLocale() ?: ReviewProvider::FALLBACK_LOCALE;
    }
}
