(function() {
    $(".convertTo select").change(function() {
        field = $(this).closest('tr').data('id');
        container = $(this).closest('tr').find('.container select').val();
        if (this.value == '') {
            var selectize = $('tr[data-id="' + field + '"] .selectfield select').get(0).selectize;
            selectize.clearOptions();
        } else {
            selectField(field, this.value, container);
        }
    });

})();

function selectField(field, convertTo, container, selected = '') {

    var item = $('tr[data-id="'+field+'"]').data('item');
    var itemId = null;
    if (item == 'podcast' || item == 'episode') {
        var itemId = $('#podcastFormatId').val();
    }
    var data = {
        'convertTo' : convertTo,
        'fieldContainer': container,
        'limitFieldsToLayout': 'true',
        'item' : $('tr[data-id="'+field+'"]').data('item'),
        'itemId' : itemId
    };
    var selectize = $('tr[data-id="'+field+'"] .selectfield select').get(0).selectize;

    $.ajax({
        method: "GET",
        url: Craft.getUrl("studio/default/fields-filter" + "?=_" + new Date().getTime()),
        data: data,
        dataType: 'json',
        success: function (data) {
            selectize.clear();
            selectize.clearOptions();
            $.each(data, function(i, item) {
                selectize.addOption({
                    value: item.value,
                    text: item.label
                });
            });
            selectize.addItem(selected);
        }
    });
}