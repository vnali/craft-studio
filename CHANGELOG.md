# Release Notes for Studio plugin

## Unreleased

- Added <podcast:chapters> support [#4](https://github.com/vnali/craft-studio/discussions/4)
- Added a new tool for audio preview to make creating chapters in <podcast:chapters> and {timestamp}-{chapter title} easier [#4](https://github.com/vnali/craft-studio/discussions/4)
- Fixed a bug where users with manage episodes permission could not able to see episodes on the episode index page.

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