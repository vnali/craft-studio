(function ($) {
    /** global: Craft */
    /** global: Garnish */
    var $siteRows = $('#podcastSitesSettings').children('tbody').children();
    var $lightswitches = $siteRows
        .children('th:nth-child(2)')
        .children('.lightswitch');

    function updateSites() {
        $siteRows.each(function (i) {
            updateSite($(this), i);
        });
    }

    function updateSite($site, i) {
        // Is it disabled?
        var $lightswitch = $lightswitches.eq(i);
        if ($lightswitch.length) {
            const $tds = $lightswitch.parent().nextAll('td');
            var $tds2 = $('#episodeSitesSettings').children('tbody').children().eq(i);
            const $inputs = $tds.find('textarea, input, .lightswitch');
            const $inputs2 = $tds2.find('textarea, input, .lightswitch');
            if (!$lightswitch.data('lightswitch').on) {
                $tds.addClass('disabled');
                $tds2.addClass('disabled');
                $inputs.attr({
                    tabindex: '-1',
                    readonly: 'readonly',
                });
                $inputs2.attr({
                    tabindex: '-1',
                    readonly: 'readonly',
                });
                $inputs.on('focus.preventFocus', (event) => {
                    $(event.currentTarget).blur();
                    event.preventDefault();
                    event.stopPropagation();
                });
                $inputs2.on('focus.preventFocus', (event) => {
                    $(event.currentTarget).blur();
                    event.preventDefault();
                    event.stopPropagation();
                });
                return;
            }
            $tds.removeClass('disabled');
            $tds2.removeClass('disabled');
            $inputs.removeAttr('tabindex');
            $inputs2.removeAttr('tabindex');
            $inputs.removeAttr('readonly');
            $inputs2.removeAttr('readonly');
            $inputs.off('focus.preventFocus');
            $inputs2.off('focus.preventFocus');
        }
    }

    $lightswitches.on('change', updateSites);

    Garnish.$doc.ready(function () {
        updateSites();
    });
})(jQuery);