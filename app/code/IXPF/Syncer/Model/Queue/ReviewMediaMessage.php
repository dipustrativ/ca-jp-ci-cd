<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Queue;

/**
 * One message per file on ixpf.review.media.download.
 *
 * Files are keyed by review_id, not entity_id — entity_id is module-internal.
 *
 * The getter/setter pairs are load-bearing, not boilerplate. Magento's queue encode and decode
 * are asymmetric: MessageEncoder::encode() flattens this object to snake_case keys, while
 * ServiceInputProcessor::getConstructorData() matches those keys against constructor parameter
 * names verbatim. `review_id` never matches `$reviewId`, so the constructor path yields no
 * arguments and the decoder falls through to its secondary path, which maps each snake_case key
 * to a getter/setter pair (ServiceInputProcessor::_createFromArray). Without the setters every
 * message is rejected with "must be an instance of ReviewMediaMessage" and nothing is ever
 * downloaded. The constructor defaults exist so the decoder can instantiate with no arguments.
 */
class ReviewMediaMessage
{
    public const TYPE_PHOTO     = 'photo';
    public const TYPE_THUMBNAIL = 'thumbnail';

    /**
     * Argument names must match the keys passed to ReviewMediaMessageFactory::create().
     */
    public function __construct(
        private string $reviewId = '',
        private string $bucketPath = '',
        private string $type = self::TYPE_PHOTO
    ) {
    }

    /**
     * Magento's communication config validates this DTO with MethodsMap, and the queue decoder
     * reads the property type from this docblock, not from the PHP return type.
     *
     * @return string
     */
    public function getReviewId(): string
    {
        return $this->reviewId;
    }

    /**
     * @param string $reviewId
     * @return void
     */
    public function setReviewId(string $reviewId): void
    {
        $this->reviewId = $reviewId;
    }

    /**
     * @return string
     */
    public function getBucketPath(): string
    {
        return $this->bucketPath;
    }

    /**
     * @param string $bucketPath
     * @return void
     */
    public function setBucketPath(string $bucketPath): void
    {
        $this->bucketPath = $bucketPath;
    }

    /**
     * Either self::TYPE_PHOTO or self::TYPE_THUMBNAIL.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return void
     */
    public function setType(string $type): void
    {
        $this->type = $type;
    }
}
