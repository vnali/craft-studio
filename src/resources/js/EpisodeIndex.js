Studio.EpisodeIndex = Craft.BaseElementIndex.extend({

    init: function (elementType, $container, settings) {
        this.on('selectSource', $.proxy(this, 'updateButton'));
        this.on('selectSite', $.proxy(this, 'updateButton'));
        this.base(elementType, $container, settings);
    },

    afterInit: function () {
        this.availablePodcasts = [];
        for (var i = 0; i < Studio.availablePodcasts.length; i++) {
            var availablePodcast = Studio.availablePodcasts[i];
            this.availablePodcasts.push(availablePodcast);
        }
        this.base();
    },

    getDefaultSourceKey: function () {
        // Did they request a specific podcast in the URL?
        if (
            this.settings.context === 'index' &&
            typeof defaultPodcastHandle !== 'undefined'
        ) {
            for (let i = 0; i < this.$sources.length; i++) {
                const $source = $(this.$sources[i]);
                if ($source.data('handle') === defaultPodcastHandle) {
                    return $source.data('key');
                }
            }
        }

        return this.base();
    },

    updateButton: function () {

        if (!this.$source) {
            return;
        }

        let handle;

        handle = this.$source.data('handle');
        // Update the New Episode button
        // ---------------------------------------------------------------------

        if (this.availablePodcasts.length) {
            // Remove the old button, if there is one
            if (this.$newEpisodeBtnGroup) {
                this.$newEpisodeBtnGroup.remove();
            }

            // Determine if they are viewing a podcast that they have permission to create episodes in
            const selectedPodcast = this.availablePodcasts.find(
                (s) => s.handle === handle
            );

            this.$newEpisodeBtnGroup = $('<div class="btngroup submit" data-wrapper/>');
            let $menuBtn;
            const menuId = 'new-episode-menu-' + Craft.randomString(10);

            // If they are, show a primary "New episode" button, and a dropdown of the other podcasts (if any).
            // Otherwise only show a menu button

            if (selectedPodcast) {
                const visibleLabel =
                    this.settings.context === 'index'
                        ? Craft.t('app', 'New episode')
                        : Craft.t('app', 'New {podcast} episode', {
                            podcast: selectedPodcast.name[this.siteId],
                        });

                const ariaLabel =
                    this.settings.context === 'index'
                        ? Craft.t('app', 'New episode for the {podcast} podcast', {
                            podcast: selectedPodcast.name[this.siteId],
                        })
                        : visibleLabel;

                // In index contexts, the button functions as a link
                // In non-index contexts, the button triggers a slideout editor
                const role = this.settings.context === 'index' ? 'link' : null;

                this.$newEpisodeBtn = Craft.ui
                    .createButton({
                        label: visibleLabel,
                        ariaLabel: ariaLabel,
                        spinner: true,
                        role: role,
                    })
                    .addClass('submit add icon')
                    .appendTo(this.$newEpisodeBtnGroup);

                this.addListener(this.$newEpisodeBtn, 'click mousedown', (ev) => {
                    // If this is the element index, check for Ctrl+clicks and middle button clicks
                    if (
                        this.settings.context === 'index' &&
                        ((ev.type === 'click' && Garnish.isCtrlKeyPressed(ev)) ||
                            (ev.type === 'mousedown' && ev.originalEvent.button === 1))
                    ) {
                        window.open(Craft.getUrl(`episodes/${selectedPodcast.handle}/new`));
                    } else if (ev.type === 'click') {
                        this._createEpisode(selectedPodcast.id);
                    }
                });

                if (this.availablePodcasts.length > 1) {
                    $menuBtn = $('<button/>', {
                        type: 'button',
                        class: 'btn submit menubtn btngroup-btn-last',
                        'aria-controls': menuId,
                        'data-disclosure-trigger': '',
                        'aria-label': Craft.t('app', 'New episode, choose a podcast'),
                    }).appendTo(this.$newEpisodeBtnGroup);
                }
            } else {
                this.$newEpisodeBtn = $menuBtn = Craft.ui
                    .createButton({
                        label: Craft.t('app', 'New episode'),
                        ariaLabel: Craft.t('app', 'New episode, choose a podcast'),
                        spinner: true,
                    })
                    .addClass('submit add icon menubtn btngroup-btn-last')
                    .attr('aria-controls', menuId)
                    .attr('data-disclosure-trigger', '')
                    .appendTo(this.$newEpisodeBtnGroup);
            }

            this.addButton(this.$newEpisodeBtnGroup);

            if ($menuBtn) {
                const $menuContainer = $('<div/>', {
                    id: menuId,
                    class: 'menu menu--disclosure',
                }).appendTo(this.$newEpisodeBtnGroup);
                const $ul = $('<ul/>').appendTo($menuContainer);

                for (const podcast of this.availablePodcasts) {
                    const anchorRole =
                        this.settings.context === 'index' ? 'link' : 'button';
                    if (
                        (this.settings.context === 'index' &&
                            $.inArray(this.siteId, podcast.sites) !== -1) ||
                        (this.settings.context !== 'index' && podcast !== selectedPodcast)
                    ) {
                        const $li = $('<li/>').appendTo($ul);
                        const $a = $('<a/>', {
                            role: anchorRole === 'button' ? 'button' : null,
                            href: '#', // Allows for click listener and tab order
                            type: anchorRole === 'button' ? 'button' : null,
                            text: Craft.t('app', 'New {podcast} episode', {
                                podcast: podcast.name[this.siteId],
                            }),
                        }).appendTo($li);
                        this.addListener($a, 'click', () => {
                            $menuBtn.data('trigger').hide();
                            this._createEpisode(podcast.id);
                        });

                        if (anchorRole === 'button') {
                            this.addListener($a, 'keydown', (event) => {
                                if (event.keyCode === Garnish.SPACE_KEY) {
                                    event.preventDefault();
                                    $menuBtn.data('trigger').hide();
                                    this._createEpisode(podcast.id);
                                }
                            });
                        }
                    } else if (this.settings.context === 'index' &&
                        $.inArray(this.siteId, podcast.sites) === -1) {
                        // Remove the old button, if there is one
                        if (this.$newEpisodeBtnGroup) {
                            this.$newEpisodeBtnGroup.remove();
                        }
                    }
                }

                new Garnish.DisclosureMenu($menuBtn);
            }
        }

        // Update the URL if we're on the Episodes index
        // ---------------------------------------------------------------------

        if (this.settings.context === 'index') {
            let uri = 'studio/episodes';

            if (handle) {
                uri += '/' + handle;
            }

            Craft.setPath(uri);
        }
    },

    _createEpisode: function (podcastId) {
        if (this.$newEpisodeBtn.hasClass('loading')) {
            console.warn('New episode creation already in progress.');
            return;
        }

        // Find the podcast
        const podcast = this.availablePodcasts.find((s) => s.id === podcastId);

        if (!podcast) {
            throw `Invalid podcast ID: ${podcastId}`;
        }

        this.$newEpisodeBtn.addClass('loading');

        Craft.sendActionRequest('POST', 'studio/episodes/create', {
            data: {
                siteId: this.siteId,
                podcast: podcast.handle,
            },
        })
            .then(({data}) => {
                if (this.settings.context === 'index') {
                    document.location.href = Craft.getUrl(data.cpEditUrl, { fresh: 1 });
                } else {
                    const slideout = Craft.createElementEditor(this.elementType, {
                        siteId: this.siteId,
                        elementId: data.episode.id,
                        draftId: data.episode.draftId,
                        params: {
                            fresh: 1,
                        },
                    });
                    slideout.on('submit', () => {
                        // Make sure the right podcast is selected
                        const podcastSourceKey = `podcast:${podcast.uid}`;

                        if (this.sourceKey !== podcastSourceKey) {
                            this.selectSourceByKey(podcastSourceKey);
                        }

                        this.clearSearch();
                        this.selectElementAfterUpdate(data.episode.id);
                        this.updateElements();
                    });
                }
            })
            .finally(() => {
                this.$newEpisodeBtn.removeClass('loading');
            });
    },
});

// Register it!
Craft.registerElementIndexClass('vnali\\studio\\elements\\Episode', Studio.EpisodeIndex);
