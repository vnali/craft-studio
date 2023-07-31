# Release Notes for Studio plugin

## Unreleased

> {warning} Changing one `person` field to `userPerson`, `entryPerson`, `tablePerson`, `textPerson` fields for creating podcast:person.

- Added <podcast:liveItem> support ([#18](https://github.com/vnali/craft-studio/discussions/18)).
- Added <podcast:socialInteract> support ([#19](https://github.com/vnali/craft-studio/discussions/19)).
- Added <podcast:txt> support ([#20](https://github.com/vnali/craft-studio/discussions/20)).
- Added <podcast:guid> support ([#21](https://github.com/vnali/craft-studio/discussions/21)).
- Added <podcast:value> support ([#22](https://github.com/vnali/craft-studio/discussions/22)).
- Added OP3 analytics service support ([#23](https://github.com/vnali/craft-studio/discussions/23)).
- For generating the podcast:person [#11](https://github.com/vnali/craft-studio/discussions/11) via the matrix, instead of one `person` field, there can be `userPerson`, `entryPerson`, `tablePerson`, `textPerson` fields. so the user can use one of these fields when creating each person/block.
- Fixed a bug where deleted and restored elements were displayed in the podcast rss.

## 0.13.0 - 2023-07-19

- Added <podcast:location> support ([#17](https://github.com/vnali/craft-studio/discussions/17)).
- The <podcast:chapters> ([#4](https://github.com/vnali/craft-studio/discussions/4)) supports location attribute now.

## 0.12.0 - 2023-07-13

> {warning} The `trailerUrl` field handle should be changed to `trailer`.

- Added <podcast:alternateEnclosure> support ([#16](https://github.com/vnali/craft-studio/discussions/16)).
- The `trailerUrl` field handle that is used for the URL attribute of a podcast:trailer should be changed to `trailer` for clarity because it can be an asset or a URL.
- The ([podcast:trailer](https://github.com/vnali/craft-studio/discussions/15)) tag can be created via Asset fields now.
- When a trailer item is an asset inside a matrix or super table field, the assets' size and mime type metadata are used for length and type attributes. The length and mimeType custom fields are only used when the trailer item is not an asset.
- The ([podcast:license](https://github.com/vnali/craft-studio/discussions/9)) tag can be created via Asset fields now.
- Fixed a bug where podcast:trailer was generated even if there was not any trailer file.

## 0.11.0 - 2023-07-03

- Added <podcast:trailer> support ([#15](https://github.com/vnali/craft-studio/discussions/15)).

## 0.10.0 - 2023-06-30

- Added <podcast:transcript> support ([#14](https://github.com/vnali/craft-studio/discussions/14)).
- Added a new simple tool for making transcripts ([#14](https://github.com/vnali/craft-studio/discussions/14)).

## 0.9.0 - 2023-06-07

- Added <podcast:person> support ([#11](https://github.com/vnali/craft-studio/discussions/11)).
- Podcast and episode custom field handles are configurable now ([#12](https://github.com/vnali/craft-studio/discussions/12)).
- Any changes to elements inside the project invalidate the RSS page cache ([#13](https://github.com/vnali/craft-studio/discussions/13)).
- Fixed a bug where soundbites with start time 0 were not displayed on RSS. 

## 0.8.0 - 2023-05-29

- Added <podcast:medium> support ([#6](https://github.com/vnali/craft-studio/discussions/6)).
- Added <podcast:locked> support ([#7](https://github.com/vnali/craft-studio/discussions/7)).
- Added <podcast:funding> support ([#8](https://github.com/vnali/craft-studio/discussions/8)).
- Added <podcast:season> support ([#10](https://github.com/vnali/craft-studio/discussions/10)).
- Added <podcast:license> support ([#9](https://github.com/vnali/craft-studio/discussions/9)).
- Fixed bugs where matching elements by episode number and conditions which extend BaseLightswitchConditionRule were checked incorrectly.
- Fixed a bug where fields required for generating podcast:soundbite tag were not checked correctly.
- Fixed a bug where podcastIsNewFeedUrl attribute was saved as null instead of 0.

## 0.7.0 - 2023-05-23

- Added <podcast:soundbite> support ([#5](https://github.com/vnali/craft-studio/discussions/5)).
- Added a new tool for audio preview to make creating soundbites easier ([#5](https://github.com/vnali/craft-studio/discussions/5)).
- Podcast chapter json file now uses JSON_UNESCAPED_UNICODE format.
- Fixed a bug where chapter startTime was not checked properly.

## 0.6.0 - 2023-05-18

- Added <podcast:chapters> support ([#4](https://github.com/vnali/craft-studio/discussions/4)).
- Added a new tool for audio preview to make creating chapters in <podcast:chapters> and {timestamp}-{chapter title} format easier ([#4](https://github.com/vnali/craft-studio/discussions/4)).
- Fixed a bug where users with manage episodes permission could not view episodes on the episode index page.
- Fixed a bug where under certain conditions a podcast considered as enabled even if the podcast was disabled.

## 0.5.0 - 2023-05-12

> {warning} Please read [#3](https://github.com/vnali/craft-studio/discussions/3) before update to this version.

- Changed episode published() method to rss().

## 0.4.0 - 2023-05-12

> {warning} Please read [#1](https://github.com/vnali/craft-studio/discussions/1) and [#2](https://github.com/vnali/craft-studio/discussions/2) before update to this version.

- Added new native field for episodes, publishOnRSS ([#1](https://github.com/vnali/craft-studio/discussions/1))
- Changed podcast and episode methods to simplify writing queries. ([#2](https://github.com/vnali/craft-studio/discussions/2))
- Fixed some bugs on podcast and episode queries, where methods were not applied correctly on queries.

## 0.3.0 - 2023-05-08

- ownerName, ownerEmail, authorName, podcastType, copyright, duration, episodeSeason, episodeNumber, episodeType attributes are now searchable.

## 0.2.1 - 2023-05-07

- Fixed the plugin folder structure.

## 0.2.0 - 2023-05-07

- Initial public release.

## 0.1.0 - 2023-03-02

- Initial Release.