<?php

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;
use Yii;

/**
 * m230414_164840_podcast_assetIndexes_settings migration.
 */
class m230414_164840_podcast_assetIndexes_settings extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Create asset indexes settings table for podcast
        if (!$this->tableExists('{{%studio_podcast_assetIndexes_settings}}')) {
            $this->createTable('{{%studio_podcast_assetIndexes_settings}}', [
                'id' => $this->primaryKey(),
                'podcastId' => $this->integer()->unique(),
                'settings' => $this->text(),
                'enable' => $this->boolean()->defaultValue(false),
                'userId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey(null, '{{%studio_podcast_assetIndexes_settings}}', 'podcastId', '{{%studio_podcast}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%studio_podcast_assetIndexes_settings}}', 'userId', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
        
            // Drop enable column from episode settings table
            $this->dropColumn('{{%studio_podcast_episode_settings}}', 'enable');
        }
        Craft::$app->db->schema->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230414_164840_podcast_assetIndexes_settings cannot be reverted.\n";
        return false;
    }

    /**
     * Check if a table exists.
     *
     * @param string $table
     * @return boolean
     */
    private function tableExists($table): bool
    {
        return (Yii::$app->db->schema->getTableSchema($table) !== null);
    }
}
