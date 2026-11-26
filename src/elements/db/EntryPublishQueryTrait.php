<?php

namespace teamnovu\publisher\elements\db;

trait EntryPublishQueryTrait
{
    /**
     * @var int|array|null The entry ID(s) to query for.
     */
    public $sourceId;

    /**
     * @var int|array|null The entry site ID(s) to query for.
     */
    public $sourceSiteId;

    /**
     * @var int|array|null The draft ID(s) to query for.
     */
    public $publishDraftId;

    /**
     * @var \DateTime|null The DateTime to query for.
     */
    public $publishAt;

    /**
     * @var boolean|null
     */
    public $expire;

    /**
     * Filters the query results based on the element ID.
     *
     * @param int|array|null $value The element ID(s).
     * @return $this
     */
    public function sourceId(int $value)
    {
        $this->sourceId = $value;

        return $this;
    }

    /**
     * Filters the query results based on the element site ID.
     *
     * @param int|array|null $value The element site ID(s).
     * @return $this
     */
    public function sourceSiteId(int $value)
    {
        $this->sourceSiteId = $value;

        return $this;
    }

    /**
     * Filters the query results based on the DateTime.
     *
     * @param \DateTime|null $value The DateTime.
     * @return $this
     */
    public function publishAt(\DateTime $value)
    {
        $this->publishAt = $value;

        return $this;
    }

    /**
     * Filters the query results based on the expire value.
     *
     * @param bool|null $value The expire value.
     * @return $this
     */
    public function expire(bool $value)
    {
        $this->expire = $value;

        return $this;
    }
}
