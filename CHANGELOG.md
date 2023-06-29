# Release Notes for Studio plugin

## Unreleased

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