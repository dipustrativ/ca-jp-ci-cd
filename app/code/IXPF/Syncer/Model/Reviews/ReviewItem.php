<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Reviews;

/**
 * One review resolved for a requested locale.
 *
 * Both the resolved text and the review's own source-language text are carried, so a
 * "show original" toggle needs no second request. When resolvedLocale equals sourceLocale the
 * two are identical and the toggle should not be offered.
 *
 * Text is plain, unescaped as received — escape on output.
 */
class ReviewItem
{
    /**
     * @param array<string, int|null> $subRatings quality / delivery / customer_service
     * @param ReviewPhoto[] $photos
     */
    public function __construct(
        public readonly string $reviewId,
        public readonly string $sku,
        public readonly int $rating,
        public readonly array $subRatings,
        public readonly string $reviewer,
        public readonly string $createdAt,
        public readonly ?string $purchasedAt,
        public readonly string $sourceLocale,
        public readonly string $sourceStoreCode,
        public readonly string $resolvedLocale,
        public readonly ?string $body,
        public readonly ?string $sourceBody,
        public readonly ?string $replyBody,
        public readonly ?string $replyCreatedAt,
        public readonly ?string $boughtName,
        public readonly ?string $boughtVariantText,
        public readonly ?string $thumbnailPath,
        public readonly array $photos
    ) {
    }

    public function isTranslated(): bool
    {
        return $this->resolvedLocale !== $this->sourceLocale;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'review_id'           => $this->reviewId,
            'sku'                 => $this->sku,
            'rating'              => $this->rating,
            'sub_ratings'         => $this->subRatings,
            'reviewer'            => $this->reviewer,
            'created_at'          => $this->createdAt,
            'purchased_at'        => $this->purchasedAt,
            'source_locale'       => $this->sourceLocale,
            'source_store_code'   => $this->sourceStoreCode,
            'resolved_locale'     => $this->resolvedLocale,
            'is_translated'       => $this->isTranslated(),
            'body'                => $this->body,
            'source_body'         => $this->sourceBody,
            'reply_body'          => $this->replyBody,
            'reply_created_at'    => $this->replyCreatedAt,
            'bought_name'         => $this->boughtName,
            'bought_variant_text' => $this->boughtVariantText,
            'thumbnail_path'      => $this->thumbnailPath,
            'photos'              => array_map(static fn (ReviewPhoto $p): array => $p->toArray(), $this->photos),
        ];
    }
}
