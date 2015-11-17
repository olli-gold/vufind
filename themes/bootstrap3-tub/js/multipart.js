/**
 * Functions for multipart lists
 */

var tablePager = {
    init: function (elem) {
        var iterator = 1;
        var factor = 5;
        // populating table is happening in PHP
        //tablePager.populate();
        var sum = elem.children('tbody').children('tr').length;
        $('#volcount').append(sum);
        if (sum > factor) {
            tablePager.showListEvents(elem);
            tablePager.hideListMinimizeEvents(elem);
            tablePager.setSumEntries(sum);
            tablePager.listenEvents(elem, iterator, factor, sum);
        } else {
            tablePager.hidePager(elem);
        }
    },
    populate: function() {
        var pathnames = window.location.pathname.split('/');
        if (pathnames[2] != 'Record') {
            return false;
        }
        ppnlink = pathnames[3];
        jQuery.ajax({
            url:path+'/AJAX/JSON?method=getMultipart&id='+ppnlink+'&start=0&length=10000',
            dataType:'json',
            success:function(data, textStatus) {
                var volcount = data.data.length;
                var visibleCount = Math.min(5, volcount);
                if (visibleCount == 0) {
                    jQuery('.multipartlist').html('');
                    return false;
                }
                for (var index = 0; index < visibleCount; index++) {
                    var entry = data.data[index];
                    jQuery('.multipartlist').append('<tr><td><a href="'+path+'/Record/'+entry.id+'">'+entry.partNum+'</a></td><td><a href="'+path+'/Record/'+entry.id+'">'+entry.title+'</a></td><td><a href="'+path+'/Record/'+entry.id+'">'+entry.date+'</a></td></tr>');
                }
                if (volcount > visibleCount) {
                    for (var index = visibleCount; index < data.data.length; index++) {
                        var entry = data.data[index];
                        jQuery('.multipartlist').append('<tr class="offscreen"><td><a href="'+path+'/Record/'+entry.id+'">'+entry.partNum+'</a></td><td><a href="'+path+'/Record/'+entry.id+'">'+entry.title+'</a></td><td><a href="'+path+'/Record/'+entry.id+'">'+entry.date+'</a></td></tr>');
                    }
                }
            }
        });
    },
    getNextListEntries: function (elem, iterator, factor, sum) {
        var stop = (sum > (factor*iterator)) ? (factor*iterator) : sum;
        for (var i = (factor*(iterator-1)); i < stop; i++) {
            elem.children('tbody').children('tr').eq(i).removeClass('offscreen');
        }
        if (sum <= (factor*iterator)) {
            tablePager.showListMinimizeEvents(elem);
            tablePager.hideListEvents(elem);
        }
        tablePager.listenEvents(elem, iterator, factor, sum);
    },
    getAllListEntries: function (elem, iterator, factor, sum) {
        tablePager.showListMinimizeEvents(elem);
        tablePager.hideListEvents(elem);
        for (var i = (factor*(iterator-1)); i < sum; i++) {
            elem.children('tbody').children('tr').eq(i).removeClass('offscreen');
        }
        tablePager.listenEvents(elem, iterator, factor, sum);
    },
    listenEvents: function (elem, iterator, factor, sum) {
        $('.next-parts').click( function () {
            tablePager.getNextListEntries(elem, iterator + 1, factor, sum);
        });
        $('.all-parts').click( function () {
            tablePager.getAllListEntries(elem, iterator +1 , factor, sum);
        });
        $('.setback-parts').click( function () {
            tablePager.init(elem);
        });
    },
    showListEvents: function () {
        $('ul .next-parts').show();
        $('ul .all-parts').show();
    },
    hideListEvents: function () {
        $('ul .next-parts').hide();
        $('ul .all-parts').hide();
    },
    showListMinimizeEvents: function () {
        $('ul .setback-parts').show();
    },
    hideListMinimizeEvents: function () {
        $('ul .setback-parts').hide();
    },
    setSumEntries: function (sum){
        if ($('ul.pager li.hits').length == 0) {
            $.getJSON(path + '/AJAX/JSON?method=getTranslation', {"id": "", "str": "Showing"}, function(response) {
                $('ul.pager li').eq(3).after('<li class="hits">'+ response.data.translation + ': ' + sum + '</li>');
            });
        }
    },
    hidePager: function () {
        $('ul.pager').remove();
    }
}

$(document).ready(function() {
    // add tablepager
    if ($('table.extended').length > 0) {
        $('table.extended').each(function( i, elem ) {
              tablePager.init( $(elem) );
        });
    }

});

