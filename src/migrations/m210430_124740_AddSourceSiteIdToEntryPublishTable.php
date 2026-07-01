<?php

namespace teamnovu\publisher\migrations;

use Craft;
use craft\db\Migration;

/**
 * m210430_124740_AddSourceSiteIdToEntryPublishTable migration.
 */
class m210430_124740_AddSourceSiteIdToEntryPublishTable extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $alreadyMigrated = $this->db->columnExists('{{%entrypublishes}}','sourceSiteId');

        if (!$alreadyMigrated) {
            $this->addColumn('{{%entrypublishes}}', 'sourceSiteId', $this->integer());
        }
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        $this->dropColumn('{{%entrypublishes}}', 'sourceSiteId');

        return false;
    }
}
