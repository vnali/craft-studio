<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\controllers;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets;
use craft\fields\Matrix;
use craft\fields\Url;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\Volume;
use craft\records\FieldGroup;
use craft\web\Controller;

use vnali\studio\elements\Episode;
use vnali\studio\elements\Podcast;
use vnali\studio\Studio;

use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class ImportController extends Controller
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
    }

    /**
     * Redirect to Default import action
     * @return Response
     */
    public function actionDefault()
    {
        $userSession = Craft::$app->getUser();
        $currentUser = $userSession->getIdentity();
        if ($currentUser->can('studio-importCategory')) {
            return $this->redirect('studio/import/category');
        } else {
            throw new ForbiddenHttpException('user can not access import');
        }
    }

    /**
     * Render template for importing sample podcast fields.
     *
     * @return Response
     */
    public function actionPodcastFields(): Response
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('studio', 'Administrative changes are disallowed in this environment.'));
        }

        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            throw new ForbiddenHttpException(Craft::t('studio', 'these changes are disallowed when devMode is on'));
        }

        $this->requirePermission('studio-manageSettings');

        $variables['podcastFormats'] = [];

        foreach (Studio::$plugin->podcastFormats->getAllPodcastFormats() as $podcastFormatItem) {
            $podcastFormat = [];
            $podcastFormat['value'] = $podcastFormatItem->id;
            $podcastFormat['label'] = $podcastFormatItem->name;
            $variables['podcastFormats'][] = $podcastFormat;
        }

        return $this->renderTemplate(
            'studio/import/_podcastFields',
            $variables
        );
    }

    /**
     * Render template for importing sample episode fields.
     *
     * @return Response
     */
    public function actionEpisodeFields(): Response
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('studio', 'Administrative changes are disallowed in this environment.'));
        }

        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            throw new ForbiddenHttpException(Craft::t('studio', 'these changes are disallowed when devMode is on'));
        }

        $this->requirePermission('studio-manageSettings');

        $variables['podcastFormats'] = [];

        foreach (Studio::$plugin->podcastFormats->getAllPodcastFormats() as $podcastFormatItem) {
            $podcastFormat = [];
            $podcastFormat['value'] = $podcastFormatItem->id;
            $podcastFormat['label'] = $podcastFormatItem->name;
            $variables['podcastFormats'][] = $podcastFormat;
        }

        return $this->renderTemplate(
            'studio/import/_episodeFields',
            $variables
        );
    }

    /**
     * Pass required variable to the template for importing categories
     *
     * @return Response
     */
    public function actionCategory(): Response
    {
        $this->requirePermission('studio-importCategory');

        $variables['categories'] = [];
        $variables['categories'][] = ['value' => '', 'label' => Craft::t('studio', 'select one')];
        foreach (Craft::$app->categories->getAllGroups() as $categoryItem) {
            $category = [];
            $category['value'] = $categoryItem->id;
            $category['label'] = $categoryItem->name;
            $variables['categories'][] = $category;
        }

        $variables['sections'][] = ['value' => '', 'label' => Craft::t('studio', 'select one')];
        foreach (Craft::$app->sections->getSectionsByType('structure') as $section) {
            $sections['value'] = $section->id;
            $sections['label'] = $section->name;
            $variables['sections'][] = $sections;
        }
        $variables['entrytypes'][] = ['value' => '', 'label' => Craft::t('studio', 'select one')];

        return $this->renderTemplate(
            'studio/import/_category',
            $variables
        );
    }

    /**
     * Import sample categories to the specified category field for podcasts
     * they can imported to a section or category
     *
     * @return Response
     */
    public function actionCategoryImport()
    {
        $this->requirePermission('studio-importCategory');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $categoryGroupId = $request->getBodyParam('categoryGroup');
        $sectionId = $request->getBodyParam('section');
        $entryTypeId = $request->getBodyParam('entrytype');

        if ($categoryGroupId) {
            $group = Craft::$app->categories->getGroupById($categoryGroupId);
            $this->requirePermission("saveCategories:$group->uid");

            $categoryGroups = Craft::$app->categories->getAllGroups();
            $categoryGroupIds = ArrayHelper::getColumn($categoryGroups, 'id');

            if (!in_array($categoryGroupId, $categoryGroupIds)) {
                Craft::$app->getSession()->setError(Craft::t('studio', 'Selected category is not valid'));
                return $this->redirect('studio/import/category');
            }

            // Don't let import if category group already has categories
            $sampleCategory = Category::find()->groupId($categoryGroupId)->one();
            if ($sampleCategory) {
                Craft::$app->getSession()->setError(Craft::t('studio', 'Selected category has already data'));
                return $this->redirect('studio/import/category');
            }
            $redirectTo = 'categories/index';
        } elseif ($sectionId && $entryTypeId) {
            $section = Craft::$app->sections->getSectionById($sectionId);
            $this->requirePermission("saveEntries:$section->uid");
            // Don't let import if section/entries already has categories
            $sampleEntry = Entry::find()->sectionId($sectionId)->typeId($entryTypeId)->one();
            if ($sampleEntry) {
                Craft::$app->getSession()->setError(Craft::t('studio', 'Selected entry has already data'));
                return $this->redirect('studio/import/category');
            }
            $redirectTo = 'entries/index';
        } else {
            Craft::$app->getSession()->setError(Craft::t('studio', 'One option should be selected'));
            return $this->redirect('studio/import/category');
        }

        $categoryItems = [
            'Arts' => ['Books', 'Design', 'Fashion & Beauty', 'Food', 'Performing Arts', 'Visual Arts'],
            'Business' => ['Careers', 'Entrepreneurship', 'Investing', 'Management', 'Marketing', 'Non-Profit'],
            'Comedy' => ['Comedy Interviews', 'Improv', 'Stand-Up'],
            'Education' => ['Courses', 'How To', 'Language Learning', 'Self-Improvement'],
            'Fiction' => ['Comedy Fiction', 'Drama', 'Science Fiction'],
            'Government' => [],
            'History' => [],
            'Health & Fitness' => ['Alternative Health', 'Fitness', 'Medicine', 'Mental Health', 'Nutrition', 'Sexuality'],
            'Kids & Family' => ['Education for Kids', 'Parenting', 'Pets & Animals', 'Stories for Kids'],
            'Leisure' => ['Animation & Manga', 'Automotive', 'Aviation', 'Crafts', 'Games', 'Hobbies', 'Home & Garden', 'Video Games'],
            'Music' => ['Music Commentary', 'Music History', 'Music Interviews'],
            'News' => ['Business News', 'Daily News', 'Entertainment News', 'News Commentary', 'Politics', 'Sports News', 'Tech News'],
            'Religion & Spirituality' => ['Buddhism', 'Christianity', 'Hinduism', 'Islam', 'Judaism', 'Religion', 'Spirituality'],
            'Science' => ['Astronomy', 'Chemistry', 'Earth Sciences', 'Life Sciences', 'Mathematics', 'Natural Sciences', 'Nature', 'Physics', 'Social Sciences'],
            'Society & Culture' => ['Documentary', 'Personal Journals', 'Philosophy', 'Places & Travel', 'Relationships'],
            'Sports' => [
                'Baseball', 'Basketball', 'Cricket', 'Fantasy Sports', 'Football', 'Golf', 'Hockey', 'Rugby',
                'Soccer', 'Swimming', 'Tennis', 'Volleyball', 'Wilderness', 'Wrestling',
            ],
            'Technology' => [],
            'True Crime' => [],
            'TV & Film' => ['After Shows', 'Film History', 'Film Interviews', 'Film Reviews', 'TV Reviews'],
        ];

        foreach ($categoryItems as $parent => $children) {
            if ($categoryGroupId) {
                // save parent category item
                $parentCategory = new Category();
                $parentCategory->title = $parent;
                $parentCategory->groupId = $categoryGroupId;
                Craft::$app->elements->saveElement($parentCategory);
                foreach ($children as $child) {
                    $category = new Category();
                    $category->title = $child;
                    $category->groupId = $categoryGroupId;
                    $category->setParentId($parentCategory->id);
                    Craft::$app->elements->saveElement($category);
                }
            } elseif ($sectionId) {
                // save parent entry item
                $parentEntry = new Entry();
                $parentEntry->title = $parent;
                $parentEntry->sectionId = $sectionId;
                $parentEntry->typeId = $entryTypeId;
                Craft::$app->elements->saveElement($parentEntry);
                foreach ($children as $child) {
                    $entry = new Entry();
                    $entry->title = $child;
                    $entry->sectionId = $sectionId;
                    $entry->typeId = $entryTypeId;
                    $entry->setParentId($parentEntry->id);
                    Craft::$app->elements->saveElement($entry);
                }
            }
        }

        Craft::$app->getSession()->setNotice(Craft::t('studio', 'Podcast categories added successfully'));
        return $this->redirect($redirectTo);
    }

    /**
     * Import sample fields to podcast layout
     *
     * @return Response|null
     */
    public function actionImportPodcastFields(): ?Response
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('studio', 'Administrative changes are disallowed in this environment.'));
        }
        
        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            throw new ForbiddenHttpException(Craft::t('studio', 'these changes are disallowed when devMode is on'));
        }

        $this->requirePermission('studio-manageSettings');

        // Create a field group for the plugin if doesn't exists
        $request = Craft::$app->getRequest();
        $podcastFormatId = $request->getBodyParam('podcastFormat');
        if (!$podcastFormatId) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'Podcast format is required'));
            return null;
        }
        if (($podcastFormat = Studio::$plugin->podcastFormats->getPodcastFormatById($podcastFormatId)) === null) {
            throw new InvalidConfigException('Invalid podcast format ID: ' . $podcastFormatId);
        }

        /** @var FieldGroup|null $fieldGroup */
        $fieldGroup = FieldGroup::find()->where(['name' => 'studio'])->one();

        if (!$fieldGroup) {
            $fieldGroup = new \craft\models\FieldGroup([
                "name" => "studio",
            ]);
            if (!Craft::$app->fields->saveGroup($fieldGroup)) {
                $error = json_encode($fieldGroup->getErrors());
                throw new ServerErrorHttpException("Field group save error $error");
            }
            $fieldGroupId = $fieldGroup['id'];
        } else {
            $fieldGroupId = $fieldGroup->id;
        }

        // Create tag group for the podcast and episode

        $podcastTagGroup = Craft::$app->tags->getTagGroupByHandle('studioPodcast');

        if (!$podcastTagGroup) {
            $podcastTagGroup = new \craft\models\TagGroup([
                "name" => "studio podcast",
                "handle" => "studioPodcast",
            ]);
            if (!Craft::$app->tags->saveTagGroup($podcastTagGroup)) {
                $error = json_encode($podcastTagGroup->getErrors());
                throw new ServerErrorHttpException("Tag group save error $error");
            }
        }

        // Create category group for the podcast and episode

        $podcastCategoryGroup = Craft::$app->categories->getGroupByHandle('studioPodcast');

        if (!$podcastCategoryGroup) {
            $podcastCategoryGroup = new \craft\models\CategoryGroup([
                "name" => "studio podcast",
                "handle" => "studioPodcast",
            ]);
            $allSiteSettings = [];

            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $siteSettings = new CategoryGroup_SiteSettings();
                $siteSettings->siteId = $site->id;
                $siteSettings->uriFormat = null;
                $siteSettings->template = null;
                $allSiteSettings[$site->id] = $siteSettings;
            }

            $podcastCategoryGroup->setSiteSettings($allSiteSettings);

            if (!Craft::$app->categories->saveGroup($podcastCategoryGroup)) {
                $error = json_encode($podcastCategoryGroup->getErrors());
                throw new ServerErrorHttpException("Category group save error $error");
            }
        }

        // Create podcast field layout
        $podcastFieldLayout = $podcastFormat->getFieldLayout();
        $tabs = [];
        if ($podcastFieldLayout->getTabs()) {
            $tabs = $podcastFieldLayout->getTabs();
        }

        // Podcast image volume
        $podcastImageVolume = Craft::$app->volumes->getVolumeByHandle('studioPodcastImageVol');
        if (!$podcastImageVolume) {

            // Create file system
            $fsService = Craft::$app->getFs();
            $fs = $fsService->createFilesystem([
                'name' => 'studioPodcastImageFs',
                'handle' => 'studioPodcastImageFs',
                "hasUrls" => true,
                "url" => "@web/studio/images/podcast",
                "path" => "@webroot/studio/images/podcast",
                "type" => 'craft\fs\Local',
            ]);

            if (!$fsService->saveFilesystem($fs)) {
                return $this->asModelFailure($fs, Craft::t('app', 'Couldn’t save filesystem.'), 'filesystem');
            }

            $podcastImageVolume = new Volume([
                "name" => "podcast images",
                "handle" => "studioPodcastImageVol",
                "fsHandle" => 'studioPodcastImageFs',
            ]);

            if (!Craft::$app->volumes->saveVolume($podcastImageVolume)) {
                $error = json_encode($podcastImageVolume->getErrors());
                throw new ServerErrorHttpException("Podcast image volume save error $error");
            }
        }

        $podcastFields = [];

        // Podcast image field
        $podcastImageField = Craft::$app->fields->getFieldByHandle('studioPodcastImage');
        if (!$podcastImageField) {
            $podcastImageField = new \craft\fields\Assets([
                "groupId" => $fieldGroupId,
                "name" => "Podcast image",
                "handle" => "studioPodcastImage",
                "instructions" => "Image for podcast",
                "useSingleFolder" => "1",
                'defaultUploadLocationSource' => 'volume:' . $podcastImageVolume->uid,
                'singleUploadLocationSource' => 'volume:' . $podcastImageVolume->uid,
                "defaultUploadLocationSubpath" => "",
                "singleUploadLocationSubpath" => "",
                "restrictFiles" => "1",
                "allowedKinds" => ["image"],
                "localizeRelations" => "1",
                "validateRelatedElements" => "1",
            ]);

            if (!Craft::$app->getFields()->saveField($podcastImageField)) {
                $error = json_encode($podcastImageField->getErrors());
                throw new ServerErrorHttpException("Podcast image field save error $error");
            }
            $podcastImageField = Craft::$app->getFields()->getFieldByHandle('studioPodcastImage');
            $podcastImageField->required = false;
            $podcastImageField->sortOrder = 99;
        }
        $podcastFields['studioPodcastImage'] = $podcastImageField;

        $podcastTagField = Craft::$app->fields->getFieldByHandle('studioPodcastTag');
        if (!$podcastTagField) {
            // Create Podcast Tag
            $podcastTagField = new \craft\fields\Tags([
                "groupId" => $fieldGroupId,
                "name" => "Podcast Tag",
                "handle" => "studioPodcastTag",
                "allowMultipleSources" => false,
                "allowLimit" => false,
                "sources" => "*",
                "source" => "taggroup:" . $podcastTagGroup->uid,
                "localizeRelations" => "1",
                "validateRelatedElements" => "1",
            ]);

            if (!Craft::$app->getFields()->saveField($podcastTagField)) {
                $error = json_encode($podcastTagField->getErrors());
                throw new ServerErrorHttpException("Podcast tag field save error $error");
            }

            $podcastTagField = Craft::$app->getFields()->getFieldByHandle('studioPodcastTag');
            $podcastTagField->required = false;
            $podcastTagField->sortOrder = 99;
        }
        $podcastFields['studioPodcastTag'] = $podcastTagField;

        $podcastCategoryField = Craft::$app->fields->getFieldByHandle('studioPodcastCategory');
        if (!$podcastCategoryField) {
            // Create Podcast Category
            $podcastCategoryField = new \craft\fields\Categories([
                "groupId" => $fieldGroupId,
                "name" => "Podcast Category",
                "handle" => "studioPodcastCategory",
                "allowMultipleSources" => false,
                "allowLimit" => false,
                "sources" => "*",
                "source" => "group:" . $podcastCategoryGroup->uid,
                "localizeRelations" => "1",
                "validateRelatedElements" => "1",
            ]);

            if (!Craft::$app->getFields()->saveField($podcastCategoryField)) {
                $error = json_encode($podcastCategoryField->getErrors());
                throw new ServerErrorHttpException("Podcast category field save error $error");
            }

            $podcastCategoryField = Craft::$app->getFields()->getFieldByHandle('studioPodcastCategory');
            $podcastCategoryField->required = false;
            $podcastCategoryField->sortOrder = 99;
        }
        $podcastFields['studioPodcastCategory'] = $podcastCategoryField;

        $layoutModel = [];
        foreach ($tabs as $tab) {
            foreach ($tab->elements as $element) {
                if ($element instanceof CustomField) {
                    if (isset($podcastFields[$element->attribute()])) {
                        unset($podcastFields[$element->attribute()]);
                    }
                }
            }
            $layoutModel['tabs'][] = $tab;
        }
        $newTab = [];
        $newTab['name'] = 'new tab';
        $newTab['uid'] = StringHelper::UUID();
        //
        if (count($podcastFields) > 0) {
            foreach ($podcastFields as $podcastField) {
                $elements = [
                    'type' => CustomField::class,
                    'fieldUid' => $podcastField->uid,
                    'required' => $podcastField->required,
                ];
                $newTab['elements'][] = $elements;
            }
            $layoutModel['tabs'][] = $newTab;
        }
        $layoutModel['uid'] = $podcastFieldLayout->uid;
        $layoutModel['id'] = $podcastFieldLayout->id;
        $podcastFieldLayout = Craft::$app->getFields()->createLayout($layoutModel);
        $podcastFieldLayout->type = Podcast::class;

        $podcastFormat->setFieldLayout($podcastFieldLayout);

        $podcastFieldLayoutConfig = $podcastFieldLayout->getConfig();

        $projectConfig = Craft::$app->getProjectConfig();
        $projectConfig->set('studio.podcastFormats' . '.' . $podcastFormat->uid . '.podcastFieldLayout.' . $podcastFieldLayout->uid, $podcastFieldLayoutConfig, 'Import sample fields to podcast field layout');

        Craft::$app->getSession()->setNotice(Craft::t('studio', 'Podcast sample fields added to field layout'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Import sample fields to episode layout
     *
     * @return Response|null
     */
    public function actionImportEpisodeFields(): ?Response
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('studio', 'Administrative changes are disallowed in this environment.'));
        }

        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            throw new ForbiddenHttpException(Craft::t('studio', 'these changes are disallowed when devMode is on'));
        }

        $this->requirePermission('studio-manageSettings');

        $request = Craft::$app->getRequest();
        $podcastFormatId = $request->getBodyParam('podcastFormat');
        if (!$podcastFormatId) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'Podcast format is required'));
            return null;
        }
        if (($podcastFormat = Studio::$plugin->podcastFormats->getPodcastFormatById($podcastFormatId)) === null) {
            throw new InvalidConfigException('Invalid podcast format ID: ' . $podcastFormatId);
        }

        $podcastFormatEpisode = Studio::$plugin->podcastFormats->getPodcastFormatEpisodeById($podcastFormatId);

        // Create a field group for the plugin if doesn't exists

        /** @var FieldGroup|null $fieldGroup */
        $fieldGroup = FieldGroup::find()->where(['name' => 'studio'])->one();

        if (!$fieldGroup) {
            $fieldGroup = new \craft\models\FieldGroup([
                "name" => "studio",
            ]);
            if (!Craft::$app->fields->saveGroup($fieldGroup)) {
                $error = json_encode($fieldGroup->getErrors());
                throw new ServerErrorHttpException("Field group save error $error");
            }
            $fieldGroupId = $fieldGroup['id'];
        } else {
            $fieldGroupId = $fieldGroup->id;
        }


        $episodeFields = [];

        // Create tag group for the podcast and episode
        $podcastTagGroup = Craft::$app->tags->getTagGroupByHandle('studioPodcast');

        if (!$podcastTagGroup) {
            $podcastTagGroup = new \craft\models\TagGroup([
                "name" => "studio podcast",
                "handle" => "studioPodcast",
            ]);
            if (!Craft::$app->tags->saveTagGroup($podcastTagGroup)) {
                $error = json_encode($podcastTagGroup->getErrors());
                throw new ServerErrorHttpException("Tag group save error $error");
            }
        }

        // Create podcast field layout
        $episodeFieldLayout = $podcastFormatEpisode->getFieldLayout();
        $tabs = [];
        if ($episodeFieldLayout->getTabs()) {
            $tabs = $episodeFieldLayout->getTabs();
        }

        // Episode image volume
        $episodeImageVolume = Craft::$app->volumes->getVolumeByHandle('studioEpisodeImageVol');

        if (!$episodeImageVolume) {
            $fsService = Craft::$app->getFs();

            $fs = $fsService->createFilesystem([
                'name' => 'studioEpisodeImageFs',
                'handle' => 'studioEpisodeImageFs',
                "hasUrls" => true,
                "url" => "@web/studio/images/episode",
                "path" => "@webroot/studio/images/episode",
                "type" => 'craft\fs\Local',
            ]);

            if (!$fsService->saveFilesystem($fs)) {
                return $this->asModelFailure($fs, Craft::t('app', 'Couldn’t save filesystem.'), 'filesystem');
            }

            $episodeImageVolume = new Volume([
                "name" => "episode images",
                "handle" => "studioEpisodeImageVol",
                "fsHandle" => 'studioEpisodeImageFs',
            ]);

            if (!Craft::$app->volumes->saveVolume($episodeImageVolume)) {
                $error = json_encode($episodeImageVolume->getErrors());
                throw new ServerErrorHttpException("Episode image volume save error $error");
            }
        }

        // Episode image field
        $episodeImageField = Craft::$app->fields->getFieldByHandle('studioEpisodeImage');

        if (!$episodeImageField) {
            $episodeImageField = new \craft\fields\Assets([
                "groupId" => $fieldGroupId,
                "name" => "episode image",
                "handle" => "studioEpisodeImage",
                "instructions" => "Image For Episode",
                "useSingleFolder" => "1",
                'defaultUploadLocationSource' => 'volume:' . $episodeImageVolume->uid,
                'singleUploadLocationSource' => 'volume:' . $episodeImageVolume->uid,
                "defaultUploadLocationSubpath" => "",
                "singleUploadLocationSubpath" => "",
                "restrictFiles" => "1",
                "allowedKinds" => ["image"],
                "localizeRelations" => "1",
                "validateRelatedElements" => "1",
            ]);

            if (!Craft::$app->getFields()->saveField($episodeImageField)) {
                $error = json_encode($episodeImageField->getErrors());
                throw new ServerErrorHttpException("Episode image field save error $error");
            }

            $episodeImageField = Craft::$app->getFields()->getFieldByHandle('studioEpisodeImage');
            $episodeImageField->required = false;
            $episodeImageField->sortOrder = 99;
        }

        $episodeFields['studioEpisodeImage'] = $episodeImageField;

        // Create episode tag
        $episodeTagField = Craft::$app->fields->getFieldByHandle('studioEpisodeTag');
        if (!$episodeTagField) {
            $episodeTagField = new \craft\fields\Tags([
                "groupId" => $fieldGroupId,
                "name" => "Episode Tag",
                "handle" => "studioEpisodeTag",
                "allowMultipleSources" => false,
                "allowLimit" => false,
                "sources" => "*",
                "source" => "taggroup:" . $podcastTagGroup->uid,
                "localizeRelations" => "1",
                "validateRelatedElements" => "1",
            ]);

            if (!Craft::$app->getFields()->saveField($episodeTagField)) {
                $error = json_encode($episodeTagField->getErrors());
                throw new ServerErrorHttpException("Episode tag field save error $error");
            }
        }
        $episodeFields['studioEpisodeTag'] = $episodeTagField;

        $matrix = Craft::$app->fields->getFieldByHandle('studioEpisodeMatrix');
        if (!$matrix) {

            // Prepare matrix fields
            $publicEpisodeFileVolume = Craft::$app->volumes->getVolumeByHandle('studioPublicEpisodeVol');

            if (!$publicEpisodeFileVolume) {
                // Create file system
                $fsService = Craft::$app->getFs();
                $fs = $fsService->createFilesystem([
                    'name' => 'studioPublicEpisodeFs',
                    'handle' => 'studioPublicEpisodeFs',
                    "hasUrls" => true,
                    "url" => "@web/studio/files/episode",
                    "path" => "@webroot/studio/files/episode",
                    "type" => 'craft\fs\Local',
                ]);

                if (!$fsService->saveFilesystem($fs)) {
                    return $this->asModelFailure($fs, Craft::t('app', 'Couldn’t save filesystem.'), 'filesystem');
                }
                $publicEpisodeFileVolume = new Volume([
                    "name" => "public episode",
                    "handle" => "studioPublicEpisodeVol",
                    "fsHandle" => 'studioPublicEpisodeFs',
                ]);

                if (!Craft::$app->volumes->saveVolume($publicEpisodeFileVolume)) {
                    $error = json_encode($publicEpisodeFileVolume->getErrors());
                    throw new ServerErrorHttpException("Episode volume save error $error");
                }
            }

            // Episode public file field
            $publicEpisodeFileFieldConfig = [
                "type" => Assets::class,
                "name" => "public episode",
                "handle" => "studioPublicEpisodeFile",
                "restrictFiles" => "1",
                "allowedKinds" => ["audio"],
                "localizeRelations" => "1",
                "validateRelatedElements" => "1",
                'typesettings' => [
                    'sources' => [
                        0 => 'volume:' . $publicEpisodeFileVolume->uid,
                    ],
                    'defaultUploadLocationSource' => 'volume:' . $publicEpisodeFileVolume->uid,
                    'defaultUploadLocationSubpath' => 'public',
                ],
            ];

            $privateEpisodeFileVolume = Craft::$app->volumes->getVolumeByHandle('studioPrivateEpisodeVol');
            if (!$privateEpisodeFileVolume) {
                // Create file system
                $fsService = Craft::$app->getFs();
                $fs = $fsService->createFilesystem([
                    'name' => 'studioPublicEpisodeFs',
                    'handle' => 'studioPublicEpisodeFs',
                    "hasUrls" => true,
                    "url" => "@web/studio/files/episode",
                    "path" => "@webroot/studio/files/episode",
                    "type" => 'craft\fs\Local',
                ]);

                if (!$fsService->saveFilesystem($fs)) {
                    return $this->asModelFailure($fs, Craft::t('app', 'Couldn’t save filesystem.'), 'filesystem');
                }
                $privateEpisodeFileVolume = new Volume([
                    "name" => "private episode",
                    "handle" => "studioPrivateEpisodeVol",
                    "fsHandle" => "studioPublicEpisodeFs",
                ]);

                if (!Craft::$app->volumes->saveVolume($privateEpisodeFileVolume)) {
                    $error = json_encode($privateEpisodeFileVolume->getErrors());
                    throw new ServerErrorHttpException("Episode volume save error $error");
                }
            }

            // Episode public file field
            $privateEpisodeFileFieldConfig = [
                "type" => Assets::class,
                "name" => "private episode file",
                "handle" => "studioPrivateEpisodeFile",
                "restrictFiles" => "1",
                "allowedKinds" => ["audio"],
                "localizeRelations" => "1",
                "validateRelatedElements" => "1",
                'typesettings' => [
                    'sources' => [
                        0 => 'volume:' . $privateEpisodeFileVolume->uid,
                    ],
                    'defaultUploadLocationSource' => 'volume:' . $privateEpisodeFileVolume->uid,
                    'defaultUploadLocationSubpath' => 'private',
                ],
            ];

            $episodeUrlFieldConfig = [
                'type' => Url::class,
                'name' => 'episode Url',
                'handle' => 'studioEpisodeUrl',
            ];

            $matrix = new Matrix();
            $matrix->handle = 'studioEpisodeMatrix';
            $matrix->name = 'Episode';
            $matrix->groupId = $fieldGroupId;
            $matrix->propagationMethod = Matrix::PROPAGATION_METHOD_ALL;

            $blockTypes = [];

            $blockType = [];
            $blockType['handle'] = 'studioPrivateEpisodeBlock';
            $blockType['name'] = 'private episode block';
            $blockType['fields']['new1'] = $privateEpisodeFileFieldConfig;
            $blockTypes[] = $blockType;

            $blockType = [];
            $blockType['handle'] = 'studioPublicEpisodeBlock';
            $blockType['name'] = 'public episode block';
            $blockType['fields']['new2'] = $publicEpisodeFileFieldConfig;
            $blockTypes[] = $blockType;

            $blockType = [];
            $blockType['handle'] = 'studioEpisodeUrlBlock';
            $blockType['name'] = 'episode url';
            $blockType['fields']['new3'] = $episodeUrlFieldConfig;
            $blockTypes[] = $blockType;

            $matrix->setBlockTypes($blockTypes);

            if (!Craft::$app->getFields()->saveField($matrix)) {
                $error = json_encode($matrix->getErrors());
                throw new ServerErrorHttpException("Episode file matrix field save error $error");
            }

            $matrix = Craft::$app->getFields()->getFieldByHandle('studioEpisodeMatrix');
            $matrix->required = false;
            $matrix->sortOrder = 99;
        }

        $episodeFields['studioEpisodeMatrix'] = $matrix;

        // Create field layout model
        $layoutModel = [];
        $newTabSuffix = '';
        foreach ($tabs as $tab) {
            if ($tab->name == 'new tab') {
                $newTabSuffix = StringHelper::randomString(5);
            }
            foreach ($tab->elements as $element) {
                if ($element instanceof CustomField) {
                    if (isset($episodeFields[$element->attribute()])) {
                        unset($episodeFields[$element->attribute()]);
                    }
                }
            }
            $layoutModel['tabs'][] = $tab;
        }
        $newTab = [];
        $newTab['name'] = 'new tab ' . $newTabSuffix;
        $newTab['uid'] = StringHelper::UUID();
        //
        if (count($episodeFields) > 0) {
            foreach ($episodeFields as $episodeField) {
                $elements = [
                    'type' => CustomField::class,
                    'fieldUid' => $episodeField->uid,
                    'required' => $episodeField->required,
                ];
                $newTab['elements'][] = $elements;
            }
            $layoutModel['tabs'][] = $newTab;
        }
        $layoutModel['uid'] = $episodeFieldLayout->uid;
        $layoutModel['id'] = $episodeFieldLayout->id;
        $episodeFieldLayout = Craft::$app->getFields()->createLayout($layoutModel);
        $episodeFieldLayout->type = Episode::class;

        $podcastFormat->setFieldLayout($episodeFieldLayout);

        $episodeFieldLayoutConfig = $episodeFieldLayout->getConfig();

        $projectConfig = Craft::$app->getProjectConfig();
        $projectConfig->set('studio.podcastFormats' . '.' . $podcastFormat->uid . '.episodeFieldLayout.' . $episodeFieldLayout->uid, $episodeFieldLayoutConfig, 'Import sample fields to episode field layout');

        Craft::$app->getSession()->setNotice(Craft::t('studio', 'Episode sample fields added to field layout'));

        return $this->redirectToPostedUrl();
    }
}
