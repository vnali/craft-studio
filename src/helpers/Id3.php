<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\helpers;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Tags;
use craft\helpers\Assets;
use craft\helpers\Db;

/**
 * ID3 helper
 */
class Id3
{
    public static function getYear(array $fileInfo)
    {
        $year = null;

        if (isset($fileInfo['tags']['id3v2']['year'][0])) {
            $year = $fileInfo['tags']['id3v2']['year'][0];
            if (!is_numeric($year)) {
                Craft::warning('year is not numeric' . $year . ' we skip it');
            }
            $yearLen = strlen($year);
            if ($yearLen > 4) {
                $year = substr($year, 0, 4);
                Craft::warning('year is more than 4 characters: ' . $year);
            }
        }

        return $year;
    }

    public static function getImage($fileInfo)
    {
        $mimetype = null;
        if (isset($fileInfo['id3v2']['APIC'][0]['data'])) {
            $imageData = $fileInfo['id3v2']['APIC'][0]['data'];
        } elseif (isset($fileInfo['id3v2']['PIC'][0]['data'])) {
            $imageData = $fileInfo['id3v2']['PIC'][0]['data'];
        } else {
            $imageData = null;
        }
        if (isset($fileInfo['id3v2']['APIC'][0]['image_mime'])) {
            $mimetype = $fileInfo['id3v2']['APIC'][0]['image_mime'];
        }
        // TODO: other supported formats
        if (!is_null($imageData)) {
            switch ($mimetype) {
                case 'image/jpeg':
                    $ext = "jpg";
                    break;
                case 'image/png':
                    $ext = "png";
                    break;
                default:
                    $ext = "jpg";
                    break;
            }
            return array($imageData, $mimetype, $ext);
        }
    }

    public static function getGenres($fileInfo, $genreFieldType = null, $genreFieldGroupId = null, $itemGenreImportOptions = null, $itemGenreCheck = null, $defaultGenresList = [])
    {
        // Read Genres
        $metaGenres = [];
        $defaultGenres = [];
        $genreIds = [];
        $genres = [];

        if (isset($fileInfo['tags']['id3v2']['genre'][0])) {
            $metaGenres = $fileInfo['tags']['id3v2']['genre'];
        }

        if ($itemGenreImportOptions && $itemGenreImportOptions != 'only-default' && is_array($metaGenres)) {
            foreach ($metaGenres as $genre) {
                // TODO: if we should normalize case
                //$genre = strtolower($genre);
                if ($genreFieldType == Tags::class) {
                    $tagQuery = Tag::find();
                    $tag = $tagQuery
                        ->groupId($genreFieldGroupId)
                        ->title(Db::escapeParam($genre))
                        ->unique()
                        ->one();

                    if (!$itemGenreCheck && !$tag) {
                        $tag = new Tag();
                        $tag->groupId = $genreFieldGroupId;
                        $tag->title = $genre;
                        Craft::$app->getElements()->saveElement($tag);
                    }
                    if ($tag) {
                        $genreIds[] = $tag->id;
                        $genres[] = $tag->title;
                    }
                } elseif ($genreFieldType == Categories::class) {
                    $category = \craft\elements\Category::find()
                        ->groupId($genreFieldGroupId)
                        ->title(Db::escapeParam($genre))
                        ->unique()
                        ->one();

                    if (!$itemGenreCheck && !$category) {
                        $category = new Category();
                        $category->groupId = $genreFieldGroupId;
                        $category->title = $genre;
                        Craft::$app->getElements()->saveElement($category);
                    }
                    if ($category) {
                        $genreIds[] = $category->id;
                        $genres[] = $category->title;
                    }
                } elseif ($genreFieldType == Entries::class) {
                    $entry = \craft\elements\Entry::find()
                        ->sectionId($genreFieldGroupId)
                        ->title(Db::escapeParam($genre))
                        ->unique()
                        ->one();

                    if (!$itemGenreCheck && !$entry) {
                        $entry = new Entry();
                        $entry->sectionId = $genreFieldGroupId;
                        $entryTypes = Craft::$app->sections->getEntryTypesBySectionId($genreFieldGroupId);
                        $entry->typeId = $entryTypes[0]->id;
                        $entry->title = $genre;
                        Craft::$app->getElements()->saveElement($entry);
                    }
                    if ($entry) {
                        $genreIds[] = $entry->id;
                        $genres[] = $entry->title;
                    }
                }
            }
        }

        if ((!$genreIds && $itemGenreImportOptions == 'default-if-not-metadata') || $itemGenreImportOptions == 'only-default' || $itemGenreImportOptions == 'metadata-and-default') {
            $defaultGenres = $defaultGenresList;
        }

        if (is_array($defaultGenres)) {
            foreach ($defaultGenres as $defaultGenre) {
                if ($genreFieldType == Tags::class) {
                    $tag = Tag::find()
                        ->groupId($genreFieldGroupId)
                        ->id($defaultGenre)
                        ->unique()
                        ->one();

                    if ($tag) {
                        if (!in_array($tag->id, $genreIds)) {
                            $genreIds[] = $tag->id;
                            $genres[] = $tag->title;
                        }
                    }
                } elseif ($genreFieldType == Categories::class) {
                    $category = Category::find()
                        ->groupId($genreFieldGroupId)
                        ->id($defaultGenre)
                        ->unique()
                        ->one();

                    if ($category) {
                        if (!in_array($category->id, $genreIds)) {
                            $genreIds[] = $category->id;
                            $genres[] = $category->title;
                        }
                    }
                } elseif ($genreFieldType == Entries::class) {
                    $entry = Entry::find()
                        ->sectionId($genreFieldGroupId)
                        ->id($defaultGenre)
                        ->unique()
                        ->one();
                    if ($entry) {
                        if (!in_array($entry->id, $genreIds)) {
                            $genreIds[] = $entry->id;
                            $genres[] = $entry->title;
                        }
                    }
                }
            }
        }

        return array($genreIds, $genres, $metaGenres);
    }

    public static function analyze($type, $path)
    {
        $fileInfo = null;
        $getID3 = new \getID3();
        if ($type == 'local') {
            $fileInfo = $getID3->analyze($path);
        } else {
            // Copy remote file locally to scan with getID3()
            if ($fp_remote = fopen($path, 'rb')) {
                $localTempFilename = Assets::tempFilePath();
                if ($fp_local = fopen($localTempFilename, 'wb')) {
                    while ($buffer = fread($fp_remote, 10000)) {
                        fwrite($fp_local, $buffer);
                    }
                    fclose($fp_local);

                    $remote_headers = array_change_key_case(get_headers($path, true), CASE_LOWER);
                    $remote_filesize = (isset($remote_headers['content-length']) ? (is_array($remote_headers['content-length']) ? $remote_headers['content-length'][count($remote_headers['content-length']) - 1] : $remote_headers['content-length']) : null);

                    $fileInfo = $getID3->analyze($localTempFilename, $remote_filesize, basename($path));

                    // Delete temporary file
                    unlink($localTempFilename);
                }
                fclose($fp_remote);
            }
        }
        return $fileInfo;
    }
}
