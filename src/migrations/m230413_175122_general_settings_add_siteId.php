<?php

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;

/**
 * m230413_175122_general_settings_add_siteId migration.
 */
class m230413_175122_general_settings_add_siteId extends Migration
{
    // Create a siteId column for studio_podcast_general_settings table
    public function safeUp(): bool
    {
        $this->addColumn('{{%studio_podcast_general_settings}}', 'siteId', $this->integer()->after('podcastId'));
        
        // Create podcastId,siteId unique
        $this->createIndex(null, '{{%studio_podcast_general_settings}}', ['podcastId', 'siteId'], true);
        $this->addForeignKey(null, '{{%studio_podcast_general_settings}}', 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        Craft::$app->db->schema->refresh();
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230413_175122_general_settings_add_siteId cannot be reverted.\n";
        return false;
    }
}
