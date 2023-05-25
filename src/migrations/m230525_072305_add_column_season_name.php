<?php

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;

/**
 * m230525_072305_add_column_season_name migration.
 */
class m230525_072305_add_column_season_name extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->addColumn('{{%studio_i18n}}', 'episodeSeasonName', $this->string()->after('episodeSeason'));

        Craft::$app->db->schema->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230525_072305_add_column_season_name cannot be reverted.\n";
        return false;
    }
}
