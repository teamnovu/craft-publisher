<?php

namespace teamnovu\publisher\elements\db;

use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\helpers\DateTimeHelper;

/** Only loaded on Craft 6, see EntryPublish::find(). */
class EntryPublishQueryCraft6 extends ElementQuery
{
    use EntryPublishQueryTrait;

    protected string $table = 'entrypublishes';

    public function __construct(string $elementType, array $config = [])
    {
        parent::__construct($elementType, $config);

        // Only the plugin's own columns: dateCreated, dateUpdated and uid come from the elements table.
        $this->query->addSelect([
            'entrypublishes.sourceId as sourceId',
            'entrypublishes.sourceSiteId as sourceSiteId',
            'entrypublishes.publishDraftId as publishDraftId',
            'entrypublishes.publishAt as publishAt',
            'entrypublishes.expire as expire',
        ]);

        $this->beforeQuery(static function (self $query) {
            if ($query->sourceId !== null) {
                $query->whereParam('entrypublishes.sourceId', $query->sourceId);
            }

            if ($query->sourceSiteId !== null) {
                $query->whereParam('entrypublishes.sourceSiteId', $query->sourceSiteId);
            }

            if ($query->publishDraftId !== null) {
                $query->whereParam('entrypublishes.publishDraftId', $query->publishDraftId);
            }

            if ($query->publishAt !== null) {
                $query->whereDateParam('entrypublishes.publishAt', $query->publishAt, '<=');
            }

            if ($query->expire !== null) {
                $query->whereBooleanParam('entrypublishes.expire', $query->expire);
            }
        });
    }

    // Craft 6 doesn't typecast datetimeAttributes() on load; publishAt is stored in UTC.
    public function createElement(array $row): ElementInterface
    {
        if (isset($row['publishAt']) && is_string($row['publishAt'])) {
            $row['publishAt'] = DateTimeHelper::toDateTime($row['publishAt']) ?: null;
        }

        if (isset($row['expire'])) {
            $row['expire'] = (bool)$row['expire'];
        }

        return parent::createElement($row);
    }
}
