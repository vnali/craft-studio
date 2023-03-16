# Release Notes for Studio

## Unreleased

- Added a podcast general settings page
- Added an option to disallow publishing RSS for a podcast
- Added an option to allow all users/guests to view RSS
- Added studio-viewPublishedRSS-[PodcastUID] permission to check accessing to RSS
- Added checkAccessToVolumes general setting which checks saveAssets:[VolumeUID] permission before listing volumes on asset index page
- Added lastBuildDate for podcast RSS
- Added support importing from content:encoded
- Added support importing from itunes:keywords
- Added ignoreMainAsset option on fetch from RSS form to prevent fetching large main asset via CURL
- Added podcast and episode elements to garbage collection
- Now each podcast has two settings pages (general settings, episode settings)
- Episode summary and description can be different now
- podcasts and episodes records are now removing when their related elements are removed
- Improved fetch from RSS via CURL 
- Improved fetch from RSS progress notifications
- Improved RSS generation for podcasts
- Fixed route for episodes
- Fixed a bug where changing lightswitch inputs on the sidebar of the episode edit page, creates unwanted drafts
- Fixed permission issues where non-admin users were not able to access some plugin actions
- Fixed a bug where not passing the limit value on the fetch from RSS form resulted in an error.

## 0.1.0 - 2023-03-02

- Initial Release