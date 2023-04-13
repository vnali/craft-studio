# Release Notes for Studio

## Unreleased

- Added a podcast general settings page
- Added an option to disallow publishing RSS for a podcast
- Added an option to allow all users/guests to view RSS
- Added studio-viewPublishedRSS-[PodcastUID] permission to check accessing to RSS
- Added checkAccessToVolumes general setting which checks saveAssets:[VolumeUID] permission before listing volumes on Asset Indexes utility page
- Added lastBuildDate for podcast RSS
- Added support importing from content:encoded
- Added support importing from itunes:keywords
- Added ignoreMainAsset option on fetch from RSS form to prevent fetching large main asset via CURL
- Added ignoreImageAsset option on fetch from RSS form to prevent fetching episode's image via CURL
- Added podcast and episode elements to garbage collection
- Added slug, uri, id, uid, revisionNotes, revisionCreator, drafts to podcast and episode index pages
- Added importerService
- Added support for propagate-to and set-enabled-for-site for console resave
- Now each podcast has two settings pages (general settings, episode settings)
- Episode summary and description can be different now
- Added basic support for pubDate on podcast RSS
- Added support for fetching itunes:subtitle from RSS
- podcasts and episodes records are now removing when their related elements are removed
- Improved fetch from RSS via CURL 
- Improved fetch from RSS progress notifications
- Improved RSS generation for podcasts
- Make internal podcast handle unique which is now combination of {podcastId}-{podcastSlug} instead of {podcastSlug}
- Each podcast has its heading in users permission page
- Admin users can grant more specific permissions to users
- Episode title, duration, number and pubDate are not overwritten when fetching metadata if they are not empty
- Refactored console ResaveController to add new options and support core 'set' option
- Changed podcast RSS URL to use site handle instead of site Id
- Fixed route for episodes
- Fixed a bug where changing lightswitch inputs on the sidebar of the episode edit page, creates unwanted drafts
- Fixed permission issues where non-admin users were not able to access some plugin actions
- Fixed a bug where not passing the limit value on the fetch from RSS form resulted in an error.
- Fixed a bug where a deleted podcast format with same handle was used in podcast creation
- Fixed a bug where trashed items won't show up correctly on podcast and episode index page
- Fixed an error on element index pages on plugin reinstall where template cache try to get old elements
- Fixed an error where podcast RSS label was wrong for multi-site set up
- Fixed a bug where RSS always used current site instead of requested site
- Removed getPodcastBySlug()
- Dropped Craft 4.3 support

## 0.1.0 - 2023-03-02

- Initial Release