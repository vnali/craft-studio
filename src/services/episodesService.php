<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\services;

use Craft;
use craft\events\ConfigEvent;
use craft\helpers\ProjectConfig;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use Done\Subtitles\Subtitles;
use vnali\studio\elements\Episode as EpisodeElement;
use vnali\studio\helpers\GeneralHelper;
use vnali\studio\Studio;

use yii\base\Component;

/**
 * Episode service class
 */
class episodesService extends Component
{
    public const CONFIG_EPISODEFIELDLAYOUT_KEY = 'studio.episodeFieldLayout';

    /**
     * Get episode by Id
     *
     * @param int $id
     * @param int|null $siteId
     * @return EpisodeElement|null
     */
    public function getEpisodeById(int $id, ?int $siteId = null): ?EpisodeElement
    {
        return Craft::$app->getElements()->getElementById($id, EpisodeElement::class, $siteId);
    }

    /**
     * Get episode by Uid.
     *
     * @param string $uid
     * @param int|null $siteId
     * @return EpisodeElement|null
     */
    public function getEpisodeByUid(string $uid, ?int $siteId = null): ?EpisodeElement
    {
        return Craft::$app->getElements()->getElementByUid($uid, EpisodeElement::class, $siteId);
    }

    /**
     * Delete episode.
     *
     * @param EpisodeElement $episode
     * @return boolean
     */
    public function deleteEpisode(EpisodeElement $episode): bool
    {
        return Craft::$app->getElements()->deleteElement($episode);
    }

    /**
     * Saves the episode field layout.
     *
     * @param FieldLayout $fieldLayout
     * @return boolean
     */
    public function saveEpisodeFieldLayout(FieldLayout $fieldLayout): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $fieldLayoutConfig = $fieldLayout->getConfig();
        $uid = StringHelper::UUID();

        $projectConfig->set(self::CONFIG_EPISODEFIELDLAYOUT_KEY, [$uid => $fieldLayoutConfig], 'Save the episode field layout');

        return true;
    }

    /**
     * Handles a changed episode field layout.
     *
     * @param ConfigEvent $event
     * @return void
     */
    public function handleChangedEpisodeFieldLayout(ConfigEvent $event): void
    {
        $data = $event->newValue;

        // Make sure all fields are processed
        ProjectConfig::ensureAllFieldsProcessed();

        $fieldsService = Craft::$app->getFields();

        // Save the field layout
        $layout = FieldLayout::createFromConfig(reset($data));
        $layout->id = $fieldsService->getLayoutByType(EpisodeElement::class)->id;
        $layout->type = EpisodeElement::class;
        $layout->uid = key($data);
        $fieldsService->saveLayout($layout, false);
    }

    /**
     * Returns translatable native fields
     *
     * @param int $podcastFormatId
     * @return array
     */
    public function translatableFields(int $podcastFormatId): array
    {
        $podcastFormatEpisode = Studio::$plugin->podcastFormats->getPodcastFormatEpisodeById($podcastFormatId);
        $episodeNativeFields = $podcastFormatEpisode->episodeNativeFields();

        $nativeSettings = json_decode($podcastFormatEpisode->nativeSettings, true);

        foreach ($episodeNativeFields as $key => $episodeAttribute) {
            if (!isset($nativeSettings[$key]) || !$nativeSettings[$key]['translatable']) {
                unset($episodeNativeFields[$key]);
            }
        }
        return $episodeNativeFields;
    }

    /**
     * Returns not translatable native fields
     *
     * @param int $podcastFormatId
     * @return array
     */
    public function notTranslatableFields(int $podcastFormatId): array
    {
        $podcastFormatEpisode = Studio::$plugin->podcastFormats->getPodcastFormatEpisodeById($podcastFormatId);
        $episodeNativeFields = $podcastFormatEpisode->episodeNativeFields();

        $nativeSettings = json_decode($podcastFormatEpisode->nativeSettings, true);

        foreach ($episodeNativeFields as $key => $episodeAttribute) {
            if (isset($nativeSettings[$key]) && $nativeSettings[$key]['translatable']) {
                unset($episodeNativeFields[$key]);
            }
        }
        return $episodeNativeFields;
    }

    /**
     * Get transcript on different types based on transcript string or episode element
     *
     * @param string $type
     * @param string|null $transcript
     * @param EpisodeElement|null $episode
     * @return string|null
     */
    public function transcript(string $type, string $transcript = null, EpisodeElement $episode = null): ?string
    {
        $captionContent = null;
        if (!$transcript && $episode) {
            list($transcriptTextField) = GeneralHelper::getFieldDefinition('transcriptText');
            if ($transcriptTextField) {
                $transcript = $episode->{$transcriptTextField->handle};
            }
        }
        if (strcasecmp($type, 'JSON') === 0) {
            $subtitles = new Subtitles();
            $subtitles = Subtitles::loadFromString($transcript, 'vtt');
            $internalFormat = $subtitles->getInternalFormat();
            $jsonArray = [];
            $jsonArray['version'] = '1.0.0';
            if (is_array($internalFormat)) {
                foreach ($internalFormat as $format) {
                    $segment = [];
                    $segment['startTime'] = $format['start'];
                    $segment['endTime'] = $format['end'];
                    $body = '';
                    foreach ($format['lines'] as $key => $line) {
                        if ($key == 0) {
                            $lineParts = explode(':', $line);
                            if (count($lineParts) > 1) {
                                $segment['speaker'] = $lineParts[0];
                                $body = $lineParts[1];
                            } else {
                                $body = $line;
                            }
                        } else {
                            $body = $body . '<br>' . $line;
                        }
                    }
                    $segment['body'] = $body;
                    $jsonArray['segments'][] = $segment;
                }
            }
            $captionContent = json_encode($jsonArray, JSON_UNESCAPED_UNICODE);
        } elseif (strcasecmp($type, 'HTML') === 0) {
            $subtitles = new Subtitles();
            $subtitles = Subtitles::loadFromString($transcript, 'vtt');
            $internalFormat = $subtitles->getInternalFormat();
            if (is_array($internalFormat)) {
                foreach ($internalFormat as $format) {
                    $captionContent = $captionContent . '<time>' . $format['start'] . '</time>';
                    $body = '';
                    foreach ($format['lines'] as $key => $line) {
                        if ($key == 0) {
                            $lineParts = explode(':', $line);
                            if (count($lineParts) > 1) {
                                $captionContent = $captionContent . '<cite>' . $lineParts[0] . '</cite>';
                                $body = $lineParts[1];
                            } else {
                                $body = $line;
                            }
                        } else {
                            $body = $body . '<br>' . $line;
                        }
                    }
                    $captionContent = $captionContent . '<p>' . $body . '</p>';
                }
            }
        } elseif (strcasecmp($type, 'SRT') === 0) {
            $subtitles = new Subtitles();
            $subtitles = Subtitles::loadFromString($transcript, 'vtt');
            $captionContent = $subtitles->content('srt');
        } elseif (strcasecmp($type, 'VTT') === 0) {
            $captionContent = $transcript;
        } elseif (strcasecmp($type, 'TEXT') === 0) {
            $captionContent = $transcript;
        }
        return $captionContent;
    }
}
