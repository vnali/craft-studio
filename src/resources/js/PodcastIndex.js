Studio.PodcastIndex = Craft.BaseElementIndex.extend({

    init: function (elementType, $container, settings) {
        this.on('selectSource', $.proxy(this, 'updateButton'));
        this.on('selectSite', $.proxy(this, 'updateButton'));
        this.base(elementType, $container, settings);
    },

    afterInit: function () {
        // Find which of the visible podcasts the user has permission to create new item
        this.availablePodcastFormats = [];
        for (var i = 0; i < Studio.availablePodcastFormats.length; i++) {
            var podcastFormatSite = Studio.availablePodcastFormats[i];
            this.availablePodcastFormats.push(podcastFormatSite);
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
        if (this.availablePodcastFormats.length) {
            // Remove the old button, if there is one
            if (this.$newPodcastBtnGroup) {
                this.$newPodcastBtnGroup.remove();
            }

            // Determine if they are viewing a podcast that they have permission to create episodes in
            const selectedPodcastFormat = this.availablePodcastFormats.find(
                (s) => s.handle === handle
            );

            this.$newPodcastBtnGroup = $('<div class="btngroup submit" data-wrapper/>');
            let $menuBtn;
            const menuId = 'new-podcast-menu-' + Craft.randomString(10);

            // If they are, show a primary "New podcast" button, and a dropdown of the other podcasts (if any).
            // Otherwise only show a menu button
            if (selectedPodcastFormat) {
                const visibleLabel =
                    this.settings.context === 'index'
                        ? Craft.t('app', 'New podcast')
                        : Craft.t('app', 'New {podcastFormat} podcast', {
                            podcastFormat: selectedPodcastFormat.name,
                        });

                const ariaLabel =
                    this.settings.context === 'index'
                        ? Craft.t('app', 'New episode for the {podcastFormat} podcast', {
                            podcastFormat: selectedPodcastFormat.name,
                        })
                        : visibleLabel;

                // In index contexts, the button functions as a link
                // In non-index contexts, the button triggers a slideout editor
                const role = this.settings.context === 'index' ? 'link' : null;

                this.$newPodcastBtn = Craft.ui
                    .createButton({
                        label: visibleLabel,
                        ariaLabel: ariaLabel,
                        spinner: true,
                        role: role,
                    })
                    .addClass('submit add icon')
                    .appendTo(this.$newPodcastBtnGroup);

                this.addListener(this.$newPodcastBtn, 'click mousedown', (ev) => {
                    // If this is the element index, check for Ctrl+clicks and middle button clicks
                    if (
                        this.settings.context === 'index' &&
                        ((ev.type === 'click' && Garnish.isCtrlKeyPressed(ev)) ||
                            (ev.type === 'mousedown' && ev.originalEvent.button === 1))
                    ) {
                        window.open(Craft.getUrl(`podcasts/${selectedPodcastFormat.handle}/new`));
                    } else if (ev.type === 'click') {
                        this._createPodcast(selectedPodcastFormat.id);
                    }
                });

                if (this.availablePodcastFormats.length > 1) {
                    $menuBtn = $('<button/>', {
                        type: 'button',
                        class: 'btn submit menubtn btngroup-btn-last',
                        'aria-controls': menuId,
                        'data-disclosure-trigger': '',
                        'aria-label': Craft.t('app', 'New podcast, choose a podcast type'),
                    }).appendTo(this.$newPodcastBtnGroup);
                }
            } else {
                this.$newPodcastBtn = $menuBtn = Craft.ui
                    .createButton({
                        label: Craft.t('app', 'New podcast'),
                        ariaLabel: Craft.t('app', 'New podcast, choose a podcast type'),
                        spinner: true,
                    })
                    .addClass('submit add icon menubtn btngroup-btn-last')
                    .attr('aria-controls', menuId)
                    .attr('data-disclosure-trigger', '')
                    .appendTo(this.$newPodcastBtnGroup);
            }
            this.addButton(this.$newPodcastBtnGroup);

            if ($menuBtn) {
                const $menuContainer = $('<div/>', {
                    id: menuId,
                    class: 'menu menu--disclosure',
                }).appendTo(this.$newPodcastBtnGroup);
                const $ul = $('<ul/>').appendTo($menuContainer);

                for (const podcastFormat of this.availablePodcastFormats) {
                    const anchorRole =
                        this.settings.context === 'index' ? 'link' : 'button';
                    if (
                        (this.settings.context === 'index' &&
                            $.inArray(this.siteId, podcastFormat.sites) !== -1) ||
                        (this.settings.context !== 'index' && podcastFormat !== selectedPodcastFormat)
                    ) {
                        const $li = $('<li/>').appendTo($ul);
                        const $a = $('<a/>', {
                            role: anchorRole === 'button' ? 'button' : null,
                            href: '#', // Allows for click listener and tab order
                            type: anchorRole === 'button' ? 'button' : null,
                            text: Craft.t('site', 'New {podcastFormat} podcast', {
                                podcastFormat: podcastFormat.name,
                            }),
                        }).appendTo($li);
                        this.addListener($a, 'click', () => {
                            $menuBtn.data('trigger').hide();
                            this._createPodcast(podcastFormat.id);
                        });

                        if (anchorRole === 'button') {
                            this.addListener($a, 'keydown', (event) => {
                                if (event.keyCode === Garnish.SPACE_KEY) {
                                    event.preventDefault();
                                    $menuBtn.data('trigger').hide();
                                    this._createPodcast(podcastFormat.id);
                                }
                            });
                        }
                    } else if (this.settings.context === 'index' &&
                        $.inArray(this.siteId, podcastFormat.sites) === -1) {
                        // Remove the old button, if there is one
                        if (this.$newPodcastBtnGroup) {
                            //this.$newPodcastBtnGroup.remove();
                        }
                    }
                }

                new Garnish.DisclosureMenu($menuBtn);
            }
        }

        // Update the URL if we're on the Episodes index
        // ---------------------------------------------------------------------

        if (this.settings.context === 'index') {
            let uri = 'studio/podcasts';

            if (handle) {
                uri += '/' + handle;
            }

            Craft.setPath(uri);
        }
    },

    _createPodcast: function (podcastFormatId) {
        if (this.$newPodcastBtn.hasClass('loading')) {
            console.warn('New podcast creation already in progress.');
            return;
        }

        // Find the podcast
        const podcastFormat = this.availablePodcastFormats.find((s) => s.id === podcastFormatId);

        if (!podcastFormat) {
            throw `Invalid podcast type ID: ${podcastFormatId}`;
        }

        this.$newPodcastBtn.addClass('loading');

        Craft.sendActionRequest('POST', 'studio/podcasts/create', {
            data: {
                siteId: this.siteId,
                podcastFormat: podcastFormat.handle,
            },
        })
            .then(({data}) => {
                if (this.settings.context === 'index') {
                    document.location.href = Craft.getUrl(data.cpEditUrl, { fresh: 1 });
                } else {
                    const slideout = Craft.createElementEditor(this.elementType, {
                        siteId: this.siteId,
                        elementId: data.podcast.id,
                        draftId: data.podcast.draftId,
                        params: {
                            fresh: 1,
                        },
                    });
                    slideout.on('submit', () => {
                        // Make sure the right podcast format is selected
                        const podcastFormatSourceKey = `podcastFormat:${podcastFormat.uid}`;

                        if (this.sourceKey !== podcastFormatSourceKey) {
                            this.selectSourceByKey(podcastFormatSourceKey);
                        }

                        this.clearSearch();
                        this.selectElementAfterUpdate(data.podcast.id);
                        this.updateElements();
                    });
                }
            })
            .finally(() => {
                this.$newPodcastBtn.removeClass('loading');
            });
    },
});

// Register it!
Craft.registerElementIndexClass('vnali\\studio\\elements\\Podcast', Studio.PodcastIndex);
