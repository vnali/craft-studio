<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use vnali\studio\elements\Episode as EpisodeElement;
use vnali\studio\elements\Podcast as PodcastElement;

use Yii;

/**
 * Install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $this->createTables();
        Craft::$app->db->schema->refresh();
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        $this->deleteElements();
        $this->deleteFieldLayouts();
        $this->deleteTables();
        $this->deleteProjectConfig();
        return true;
    }

    /**
     * Creates the tables needed for the plugin.
     *
     * @return void
     */
    private function createTables(): void
    {

        // Create podcast format table
        if (!$this->tableExists('{{%studio_podcastFormat}}')) {
            $this->createTable('{{%studio_podcastFormat}}', [
                'id' => $this->primaryKey(),
                'name' => $this->string()->notNull(),
                'handle' => $this->string()->notNull(),
                'enableVersioning' => $this->boolean()->defaultValue(false)->notNull(),
                'fieldLayoutId' => $this->integer(),
                'nativeSettings' => $this->text(),
                'mapping' => $this->text(),
                'userId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'dateDeleted' => $this->dateTime()->null(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey(null, '{{%studio_podcastFormat}}', 'fieldLayoutId', Table::FIELDLAYOUTS, 'id', 'SET NULL', null);
            $this->addForeignKey(null, '{{%studio_podcastFormat}}', 'userId', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
        }

        // Create podcast table
        if (!$this->tableExists('{{%studio_podcast}}')) {
            $this->createTable('{{%studio_podcast}}', [
                'id' => $this->primaryKey(),
                'podcastFormatId' => $this->integer(),
                'uploaderId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey(null, '{{%studio_podcast}}', 'uploaderId', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
            $this->addForeignKey(null, '{{%studio_podcast}}', 'podcastFormatId', '{{%studio_podcastFormat}}', 'id', 'CASCADE', 'CASCADE');
        }

        // Create episode table
        if (!$this->tableExists('{{%studio_episode}}')) {
            $this->createTable('{{%studio_episode}}', [
                'id' => $this->primaryKey(),
                'episode' => $this->integer(),
                'podcastId' => $this->integer(),
                'deletedWithPodcast' => $this->boolean()->null(),
                'uploaderId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey(null, '{{%studio_episode}}', 'podcastId', '{{%studio_podcast}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%studio_episode}}', 'uploaderId', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
        }

        // Create i18n field
        if (!$this->tableExists('{{%studio_i18n}}')) {
            $this->createTable('{{%studio_i18n}}', [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer(),
                'siteId' => $this->integer(),
                'name' => $this->string(1000),
                'duration' => $this->integer(),
                'authorName' => $this->string(),
                'ownerName' => $this->string(),
                'ownerEmail' => $this->string(),
                'episodeBlock' => $this->boolean()->defaultValue(false),
                'episodeExplicit' => $this->boolean()->defaultValue(false),
                'episodeNumber' => $this->integer(),
                'episodeType' => $this->string(10),
                'episodeSeason' => $this->smallInteger(),
                'episodeGUID' => $this->string(1000),
                'podcastBlock' => $this->boolean()->defaultValue(false),
                'podcastLink' => $this->string(1000),
                'podcastComplete' => $this->boolean()->defaultValue(false),
                'podcastExplicit' => $this->boolean()->defaultValue(false),
                'podcastType' => $this->string(50),
                'podcastRedirectTo' => $this->string(1000),
                'podcastIsNewFeedUrl' => $this->boolean()->defaultValue(false),
                'copyright' => $this->string(1000),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey(null, '{{%studio_i18n}}', 'elementId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%studio_i18n}}', 'siteId', '{{%sites}}', 'id', 'CASCADE', 'CASCADE');
        }

        // Create table to keep episode data for podcast format
        if (!$this->tableExists('{{%studio_podcastFormat_episode}}')) {
            $this->createTable('{{%studio_podcastFormat_episode}}', [
                'id' => $this->primaryKey(),
                'podcastFormatId' => $this->integer()->notNull(),
                'enableVersioning' => $this->boolean()->defaultValue(false)->notNull(),
                'fieldLayoutId' => $this->integer(),
                'nativeSettings' => $this->text(),
                'mapping' => $this->text(),
                'userId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey(null, '{{%studio_podcastFormat_episode}}', 'podcastFormatId', '{{%studio_podcastFormat}}', 'id', 'CASCADE', null);
            $this->addForeignKey(null, '{{%studio_podcastFormat_episode}}', 'fieldLayoutId', Table::FIELDLAYOUTS, 'id', 'SET NULL', null);
            $this->addForeignKey(null, '{{%studio_podcastFormat_episode}}', 'userId', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
        }

        // Create table to keep sites data for podcast format
        if (!$this->tableExists('{{%studio_podcastFormat_sites}}')) {
            $this->createTable('{{%studio_podcastFormat_sites}}', [
                'id' => $this->primaryKey(),
                'podcastFormatId' => $this->integer()->notNull(),
                'siteId' => $this->integer(),
                'podcastUriFormat' => $this->text(),
                'podcastTemplate' => $this->string(500),
                'podcastEnabledByDefault' => $this->boolean()->defaultValue(true)->notNull(),
                'episodeUriFormat' => $this->text(),
                'episodeTemplate' => $this->string(500),
                'episodeEnabledByDefault' => $this->boolean()->defaultValue(true)->notNull(),
                'userId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey(null, '{{%studio_podcastFormat_sites}}', 'siteId', Table::SITES, 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%studio_podcastFormat_sites}}', 'podcastFormatId', '{{%studio_podcastFormat}}', 'id', 'CASCADE', null);
        }

        // Create episode's setting table
        if (!$this->tableExists('{{%studio_podcast_episode_settings}}')) {
            $this->createTable('{{%studio_podcast_episode_settings}}', [
                'id' => $this->primaryKey(),
                'podcastId' => $this->integer()->unique(),
                'settings' => $this->text(),
                'enable' => $this->boolean()->defaultValue(false),
                'userId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey(null, '{{%studio_podcast_episode_settings}}', 'podcastId', '{{%studio_podcast}}', 'id', 'SET NULL', 'CASCADE');
            $this->addForeignKey(null, '{{%studio_podcast_episode_settings}}', 'userId', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
        }
    }

    /**
     * Delete the plugin's tables.
     *
     * @return void
     */
    protected function deleteTables(): void
    {
        $this->dropTableIfExists('{{%studio_podcastFormat_sites}}');
        $this->dropTableIfExists('{{%studio_podcastFormat_episode}}');
        $this->dropTableIfExists('{{%studio_podcast_episode_settings}}');
        $this->dropTableIfExists('{{%studio_i18n}}');
        $this->dropTableIfExists('{{%studio_episode}}');
        $this->dropTableIfExists('{{%studio_podcast}}');
        $this->dropTableIfExists('{{%studio_podcastFormat}}');
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

    /**
     * Delete the plugin's elements.
     *
     * @return void
     */
    protected function deleteElements(): void
    {
        $elementTypes = [
            EpisodeElement::class,
            PodcastElement::class,
        ];

        $elementsService = Craft::$app->getElements();

        foreach ($elementTypes as $elementType) {
            /* @var Element $elementType */
            $elements = $elementType::findAll();

            foreach ($elements as $element) {
                $elementsService->deleteElement($element);
            }
        }
    }

    /**
     * Delete the plugin's field layouts.
     *
     * @return void
     */
    protected function deleteFieldLayouts(): void
    {
        Craft::$app->fields->deleteLayoutsByType(EpisodeElement::class);
        Craft::$app->fields->deleteLayoutsByType(PodcastElement::class);
    }

    /**
     * Delete the plugin's project config
     *
     * @return void
     */
    protected function deleteProjectConfig(): void
    {
        Craft::$app->getProjectConfig()->remove('studio');
    }
}
