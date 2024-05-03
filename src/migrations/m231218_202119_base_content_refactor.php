<?php

namespace vnali\studio\migrations;

use craft\db\Query;
use craft\migrations\BaseContentRefactorMigration;
use vnali\studio\records\PodcastRecord;
use vnali\studio\Studio;

/**
 * m231218_202119_base_content_refactor migration.
 */
class m231218_202119_base_content_refactor extends BaseContentRefactorMigration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Place migration code here...

        // update podcasts
        $podcastFormats = Studio::$plugin->podcastFormats->getAllPodcastFormats();
        foreach ($podcastFormats as $podcastFormat) {
            $this->updateElements(
                (new Query())->from('{{%studio_podcast}}')->where(['podcastFormatId' => $podcastFormat->id]),
                $podcastFormat->getFieldLayout(),
            );
        }

        // update episodes
        $podcastRecords = PodcastRecord::find()->all();
        foreach ($podcastRecords as $podcastRecord) {
            /** @var PodcastRecord $podcastRecord */
            $podcastFormatEpisode = Studio::$plugin->podcastFormats->getPodcastFormatEpisodeById($podcastRecord->podcastFormatId);
            $this->updateElements(
                (new Query())->from('{{%studio_episode}}')->where(['podcastId' => $podcastRecord->id]),
                $podcastFormatEpisode->getFieldLayout(),
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m231218_202119_base_content_refactor cannot be reverted.\n";
        return false;
    }
}
