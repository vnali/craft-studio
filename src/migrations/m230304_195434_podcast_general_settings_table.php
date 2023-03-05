<?php

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;

use Yii;

/**
 * m230304_195434_podcast_general_settings_table migration.
 */
class m230304_195434_podcast_general_settings_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Create general setting table for podcast
        if (!$this->tableExists('{{%studio_podcast_general_settings}}')) {
            $this->createTable('{{%studio_podcast_general_settings}}', [
                'id' => $this->primaryKey(),
                'podcastId' => $this->integer()->unique(),
                'publishRSS' => $this->boolean()->defaultValue(false),
                'userId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey(null, '{{%studio_podcast_general_settings}}', 'podcastId', '{{%studio_podcast}}', 'id', 'SET NULL', 'CASCADE');
            $this->addForeignKey(null, '{{%studio_podcast_general_settings}}', 'userId', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
        }
        Craft::$app->db->schema->refresh();
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230304_195434_podcast_general_settings_table cannot be reverted.\n";
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
