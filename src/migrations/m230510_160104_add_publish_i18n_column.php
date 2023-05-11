<?php

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;

/**
 * m230510_160104_add_publish_i18n_column migration.
 */
class m230510_160104_add_publish_i18n_column extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->addColumn('{{%studio_i18n}}', 'publishOnRSS', $this->integer()->after('episodeExplicit'));

        // Create podcastId,siteId unique
        $this->createIndex(null, '{{%studio_i18n}}', ['publishOnRSS'], false);
        Craft::$app->db->schema->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m230510_160104_add_publish_i18n_column cannot be reverted.\n";
        return false;
    }
}
