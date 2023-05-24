<?php

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;

/**
 * m230524_165021_add_column_medium migration.
 */
class m230524_165021_add_column_medium extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->addColumn('{{%studio_i18n}}', 'medium', $this->string(50)->after('copyright'));

        $this->createIndex(null, '{{%studio_i18n}}', ['medium'], false);
        Craft::$app->db->schema->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230524_165021_add_column_medium cannot be reverted.\n";
        return false;
    }
}
