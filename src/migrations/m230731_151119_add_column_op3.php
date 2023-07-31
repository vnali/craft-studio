<?php

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;

/**
 * m230731_151119_add_column_op3 migration.
 */
class m230731_151119_add_column_op3 extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->addColumn('{{%studio_podcast_general_settings}}', 'enableOP3', $this->boolean()->defaultValue(false)->after('allowAllToSeeRSS'));

        Craft::$app->db->schema->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230731_151119_add_column_op3 cannot be reverted.\n";
        return false;
    }
}
