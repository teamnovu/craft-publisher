<?php

namespace teamnovu\publisher\elements\db;

use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\helpers\DateTimeHelper;

/**
 * Schedule query for Craft 6, where `craft\elements\db\ElementQuery` is the native
 * Laravel-based query. Craft 6 no longer calls beforePrepare() (so {@see EntryPublishQuery}
 * would return every schedule, unfiltered): the table is declared with `$table` and the
 * criteria are applied in a beforeQuery() callback. Only loaded on Craft 6.
 */
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

    /**
     * Craft 6 doesn't normalize the element's datetimeAttributes() when loading it, so convert
     * the raw columns here to the types Craft 5 gives (publishAt is stored in UTC).
     */
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
