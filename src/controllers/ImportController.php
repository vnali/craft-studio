<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\controllers;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\web\Controller;
use craft\web\UrlManager;

use vnali\studio\elements\Podcast;

use yii\web\ForbiddenHttpException;
use yii\web\Response;

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
        } elseif ($currentUser->can('studio-importPodcastTaxonomy')) {
            return $this->redirect('studio/import/podcast-taxonomy');
        } else {
            throw new ForbiddenHttpException('user can not access import');
        }
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
            $sampleEntry = Entry::find()->sectionId($sectionId)->typeId($entryTypeId)->siteId('*')->one();
            if ($sampleEntry) {
                Craft::$app->getSession()->setError(Craft::t('studio', 'Selected section/entry type has already entries'));
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
     * Pass required variable to the template for importing podcast taxonomies
     *
     * @return Response
     */
    public function actionPodcastTaxonomy(): Response
    {
        $this->requirePermission('studio-importPodcastTaxonomy');

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
            'studio/import/_podcastTaxonomy',
            $variables
        );
    }

    /**
     * Import podcast taxonomies to the specified section/entry type
     *
     * @return Response|null
     */
    public function actionPodcastTaxonomyImport(): Response|null
    {
        $this->requirePermission('studio-importPodcastTaxonomy');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $sectionId = $request->getRequiredBodyParam('section');
        $entryTypeId = $request->getRequiredBodyParam('entrytype');
        $languages = $request->getRequiredBodyParam('languages');

        if (!$sectionId || !$entryTypeId || !is_array($languages) || count($languages) == 0) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'One option should be selected'));
            return $this->redirect('studio/import/podcast-taxonomy');
        }

        $section = Craft::$app->sections->getSectionById($sectionId);
        $this->requirePermission("saveEntries:$section->uid");
        // Don't let import if section/entries already has categories
        $sampleEntry = Entry::find()->sectionId($sectionId)->typeId($entryTypeId)->siteId('*')->one();
        if ($sampleEntry) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'Selected section/entry type has already entries'));
            return $this->redirect('studio/import/podcast-taxonomy');
        }

        $enURL = "https://raw.githubusercontent.com/Podcastindex-org/podcast-namespace/v6.0/taxonomy-en.json";
        $frURL = "https://raw.githubusercontent.com/Podcastindex-org/podcast-namespace/v6.0/taxonomy-fr.json";
        $deURL = "https://raw.githubusercontent.com/Podcastindex-org/podcast-namespace/79000a35205badc0a72214df89f6a081cac47c0d/taxonomy-de.json";

        $redirectTo = 'entries/index';
        $curlArray = array();
        $mh = curl_multi_init();
        $results = [];

        foreach ($languages as $language) {
            if (isset(${$language . 'URL'})) {
                $curlArray[$language] = curl_init(${$language . 'URL'});
                curl_setopt($curlArray[$language], CURLOPT_RETURNTRANSFER, true);
                curl_multi_add_handle($mh, $curlArray[$language]);
            }
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                // Wait a short time for more activity
                curl_multi_select($mh);
            }
        } while ($running > 0 && $status == CURLM_OK);

        foreach ($curlArray as $language => $curl) {
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if (!$httpCode || $httpCode != 200) {
                $error = $language;
                break;
            }
            $results[$language] = json_decode(curl_multi_getcontent($curl));
        }

        foreach ($curlArray as $curl) {
            curl_multi_remove_handle($mh, $curl);
        }
        curl_multi_close($mh);

        if (isset($error)) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'problem reaching site ' . $language));
            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([]);

            return null;
        }

        $parentEntry = new Entry();
        $parentEntry->sectionId = $sectionId;
        $parentEntry->typeId = $entryTypeId;
        $supportedSites = $parentEntry->getSupportedSites();

        $groups = [];
        $sites = [];
        $siteStatus = [];
        // loop through all supported sites, convert them (e.g en-CA to en), to use related translation
        foreach ($supportedSites as $supportedSite) {
            $supportedSite = $supportedSite['siteId'];
            $site = Craft::$app->sites->getSiteById($supportedSite);
            // Check if $site is not null - it happens when site is deleted but still returned in supported sites
            if ($site) {
                $sites[$site->id] = substr($site->language, 0, 2);
                $siteStatus[$site->id] = true;
            }
        }

        $cached = [];
        foreach ($results[$language] as $item => $result) {
            $siteIndex = 0;
            foreach ($sites as $siteKey => $site) {
                if (isset($results[$site])) { // First check if translation is available for specified site
                    $group = $results[$site][$item]->group;
                } elseif (isset($results['en'])) { // use english as fallback
                    $group = $results['en'][$item]->group;
                } else { // use one of other languages as final fallback
                    $group = $results[$language][$item]->group;
                }
                // save group item as parent for first site and propagate to all sites
                if ($siteIndex == 0) {
                    if (!in_array($group, array_keys($groups))) {
                        $parentEntry = new Entry();
                        $parentEntry->title = $group;
                        $parentEntry->sectionId = $sectionId;
                        $parentEntry->typeId = $entryTypeId;
                        $parentEntry->siteId = $siteKey;
                        $parentEntry->setEnabledForSite($siteStatus);
                        Craft::$app->elements->saveElement($parentEntry);
                        $groups[$group] = $parentEntry->id;
                        $parentId = $parentEntry->id;
                        $cached[$item]['group'] = $parentEntry->id;
                    } else {
                        $parentId = $groups[$group];
                        $cached[$item]['group'] = $parentId;
                    }
                } else {
                    // find group item for site and update translation
                    $entry = Entry::find()->id($cached[$item]['group'])->siteId($siteKey)->one();
                    $entry->title = $group;
                    Craft::$app->elements->saveElement($entry);
                }

                if (isset($results[$site])) {
                    $role = $results[$site][$item]->role;
                } elseif (isset($results['en'])) {
                    $role = $results['en'][$item]->role;
                } else {
                    $role = $results[$language][$item]->role;
                }
                // save role item as parent for first site and propagate to all sites
                if ($siteIndex == 0 && isset($parentId)) {
                    $entry = new Entry();
                    $entry->title = $role;
                    $entry->sectionId = $sectionId;
                    $entry->typeId = $entryTypeId;
                    $entry->siteId = $siteKey;
                    $entry->setEnabledForSite($siteStatus);
                    $entry->setParentId($parentId);
                    Craft::$app->elements->saveElement($entry);
                    $cached[$item]['role'] = $entry->id;
                } else {
                    $entry = Entry::find()->id($cached[$item]['role'])->siteId($siteKey)->one();
                    $entry->title = $role;
                    Craft::$app->elements->saveElement($entry);
                }
                $siteIndex++;
            }
        }

        Craft::$app->getSession()->setNotice(Craft::t('studio', 'Podcast taxonomies added successfully'));
        return $this->redirect($redirectTo);
    }
}
