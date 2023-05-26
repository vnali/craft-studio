<?php

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;

/**
 * m230526_065750_add_column_season_name migration.
 */
class m230526_065750_add_column_season_name extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->addColumn('{{%studio_i18n}}', 'seasonName', $this->string()->after('episodeSeason'));

        Craft::$app->db->schema->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230526_065750_add_column_season_name cannot be reverted.\n";
        return false;
    }
}
