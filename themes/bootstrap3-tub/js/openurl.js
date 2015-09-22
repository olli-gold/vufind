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

$(document).ready(function() {
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

    checkFulltextButtons();
    $('.openUrlEmbed.openUrlEmbedAutoLoad a').trigger("click");
});

function checkFulltextButtons() {
    var id = $.map($('#record_id'), function(i) {
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
    if (ouimage) {
        ouimage.onload = function(){
            if (ouimage.complete){
                var height = ouimage.height;
                var width = ouimage.width;
                if (width <= 1 && height <= 1 && $('.externalurl').length == 0) {
                    $('.urllabel').addClass('hidden');
                    $('.openUrlControls').addClass('hidden');
                }
            }
        }
    }
}