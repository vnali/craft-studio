<?php

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;

/**
 * m230524_190223_add_column_locked migration.
 */
class m230524_190223_add_column_locked extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->addColumn('{{%studio_i18n}}', 'locked', $this->boolean()->after('medium'));

        $this->createIndex(null, '{{%studio_i18n}}', ['locked'], false);
        Craft::$app->db->schema->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230524_190223_add_column_locked cannot be reverted.\n";
        return false;
    }
}
