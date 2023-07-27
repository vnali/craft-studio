<?php

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;

/**
 * m230727_065334_add_column_podcast_GUID migration.
 */
class m230727_065334_add_column_podcast_GUID extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->addColumn('{{%studio_i18n}}', 'podcastGUID', $this->string(36)->after('episodeGUID'));

        Craft::$app->db->schema->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230727_065334_add_column_podcast_GUID cannot be reverted.\n";
        return false;
    }
}
