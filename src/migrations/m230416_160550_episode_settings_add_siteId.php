<?php

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;

/**
 * m230416_160550_episode_settings_add_siteId migration.
 */
class m230416_160550_episode_settings_add_siteId extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->addColumn('{{%studio_podcast_episode_settings}}', 'siteId', $this->integer()->after('podcastId'));
        
        // Drop current unique for podcastId
        $this->dropIndex('podcastId', '{{%studio_podcast_episode_settings}}');
        // Create podcastId,siteId unique
        $this->createIndex(null, '{{%studio_podcast_episode_settings}}', ['podcastId', 'siteId'], true);
        $this->addForeignKey(null, '{{%studio_podcast_episode_settings}}', 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        Craft::$app->db->schema->refresh();
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230416_160550_episode_settings_add_siteId cannot be reverted.\n";
        return false;
    }
}
