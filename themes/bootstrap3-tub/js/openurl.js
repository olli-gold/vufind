/*global extractClassParams, path*/

function loadResolverLinks($target, openUrl) {
    $target.addClass('ajax_availability');
    var url = path + '/AJAX/JSON?' + $.param({method:'getResolverLinks',openurl:openUrl});
    $.ajax({
        dataType: 'json',
        url: url,
        success: function(response) {
            if (response.status == 'OK') {
                $target.removeClass('ajax_availability')
                    .empty().append(response.data);
            } else {
                $target.removeClass('ajax_availability').addClass('error')
                    .empty().append(response.data);
            }
        }
    });
}

function embedOpenUrlLinks(element) {
    // Extract the OpenURL associated with the clicked element:
    var openUrl = element.children('span.openUrl:first').attr('title');

    // Hide the controls now that something has been clicked:
    var controls = element.parents('.openUrlControls');
    controls.removeClass('openUrlEmbed').addClass('hidden');

    // Locate the target area for displaying the results:
    var target = controls.next('div.resolver');

    // If the target is already visible, a previous click has populated it;
    // don't waste time doing redundant work.
    if (target.hasClass('hidden')) {
        loadResolverLinks(target.removeClass('hidden'), openUrl);
    }
}

function checkFulltextButtons() {
    var id = $.map($('.hiddenId'), function(i) {
        return $(i).attr('value');
    });
    var currentId;
    for (var ids in id) {
        currentId = id[ids];
        checkImage(currentId);
    }
}

function checkImage(currentId) {
    var ouimageArr = $('*[data-recordid="'+currentId+'"]');
    var ouimage = ouimageArr[0];
    var parentArr = $('*[record-id="'+currentId+'"]')
    var parent = parentArr[0];
    if (ouimage.complete) {
        var height = ouimage.height;
        var width = ouimage.width;
        if (width > 1 && height > 1) {
            $('.urllabel').removeClass('hidden');
            // disable links in result list view
            $(parent).find('.holdelectro').addClass('hidden');
            // disable links in detailed record view
            $('.externalurl').addClass('hidden');
            // hide additional SFX button in PrimoTab
            $(parent).find('.holdlink.fulltext').addClass('hidden');
            $(parent).find('.holdlink.holddirectdl').addClass('hidden');
            // optionally display MARC links
            $('.marclinks').prepend('<br/><span class="showmore">'+vufindString.show_more_links+'</span> <span class="showless">'+vufindString.show_less_links+'</span>');
            $('.showless').addClass('hidden');
            $('.showmore').click( function () {
                $('.externalurl').removeClass('hidden');
                $('.showmore').addClass('hidden');
                $('.showless').removeClass('hidden');
            });
            $('.showless').click( function () {
                $('.externalurl').addClass('hidden');
                $('.showmore').removeClass('hidden');
                $('.showless').addClass('hidden');
            });
        }
    }
    // Hiding status unclear button if we have any kind of fulltextbutton
    // I know, that does not belong here, but it works here...
    if (!$(parent).find('.holdlink.fulltext').hasClass('hidden')) {
        $(parent).find('.holdelectro').addClass('hidden');
    }
}

$(document).ready(function() {
    if ($('.marclinks').html() == undefined) {
        $('.urllabel').addClass('hidden');
    }
    // assign action to the openUrlWindow link class
    $('a.openUrlWindow').click(function(){
        var params = extractClassParams(this);
        var settings = params.window_settings;
        window.open($(this).attr('href'), 'openurl', settings);
        return false;
    });

    // assign action to the openUrlEmbed link class
    $('.openUrlEmbed a').click(function() {
        embedOpenUrlLinks($(this));
        return false;
    });

//    checkFulltextButtons(); // 2015-12-09: maybe better move this to check_item_statuses.js
    $('.openUrlEmbed.openUrlEmbedAutoLoad a').trigger("click");
});