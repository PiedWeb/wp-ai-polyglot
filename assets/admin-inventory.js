/* global jQuery, polyglotInventory */
(function ($) {
    $(function () {
        if (typeof polyglotInventory === 'undefined') {
            return;
        }
        var d = polyglotInventory;
        $('#_manage_stock, #_stock, #_stock_status').prop('disabled', true);
        $('.inventory_options').prepend(
            $('<div class="notice notice-warning inline"><p></p></div>').find('p').append(
                $('<strong></strong>').text('SHADOW [' + d.locale + ']'),
                document.createTextNode(' — ' + d.label + ' (ID ' + d.masterId + '). '),
                $('<a></a>').attr('href', d.masterUrl).text(d.editLabel + ' →')
            ).end()
        );
    });
})(jQuery);
