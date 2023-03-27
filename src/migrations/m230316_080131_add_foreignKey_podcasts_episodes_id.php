<?php

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;

/**
 * m230316_080131_add_foreignKey_podcasts_episodes_id migration.
 */
class m230316_080131_add_foreignKey_podcasts_episodes_id extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->addForeignKey(null, '{{%studio_podcast}}', 'id', '{{%elements}}', 'id', 'CASCADE');
        $this->addForeignKey(null, '{{%studio_episode}}', 'id', '{{%elements}}', 'id', 'CASCADE');

        Craft::$app->db->schema->refresh();
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230316_080131_add_foreignKey_podcasts_episodes_id cannot be reverted.\n";
        return false;
    }
}
