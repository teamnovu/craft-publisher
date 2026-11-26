<?php

namespace teamnovu\publisher\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class EntryPublishQuery extends ElementQuery
{
    use EntryPublishQueryTrait;

    /**
     * @inheritdoc
     */
    protected function beforePrepare(): bool
    {
        $this->joinElementTable('entrypublishes');

        $this->query->select(
            [
                'entrypublishes.*',
            ]
        );

        if ($this->sourceId !== null) {
            $this->subQuery->andWhere(Db::parseParam('entrypublishes.sourceId', $this->sourceId));
        }

        if ($this->sourceSiteId !== null) {
            $this->subQuery->andWhere(Db::parseParam('entrypublishes.sourceSiteId', $this->sourceSiteId));
        }

        if ($this->publishDraftId !== null) {
            $this->subQuery->andWhere(Db::parseParam('entrypublishes.publishDraftId', $this->publishDraftId));
        }

        if ($this->publishAt !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('entrypublishes.publishAt', $this->publishAt, '<='));
        }

        if ($this->expire !== null) {
            $this->subQuery->andWhere(Db::parseParam('entrypublishes.expire', $this->expire));
        }

        return parent::beforePrepare();
    }
}
