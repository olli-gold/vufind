/*global path*/

/**
 * Only show the best action(s) a patron can take for a certain record.
 *
 * @todo  Put all plain text somewhere, where it can be translated
 * @todo  
 * - What good for were variables result.full_status and locationListHTML in checkItemStatuses()
 * - Remove checkItemStatuses() finally
 * -loan4-links (wrong url, no https etc)
 *
 * @todo: Inline way to show options AND show correct record link
 *  - <span class="holdtomes hidden"><a href="<?=$this->recordLink()->getTabUrl($this->driver, 'TomesVolumes')?>#tabnav" class="holdlink fa fa-stack-overflow"> <?=$this->transEsc("See Tomes/Volumes")?></a></span>
 *  - For now stick to generating Buttons and infos here. Decide later to
 *      - either use the template way
 *      - or use stick to the current JS way
 *      - but be consistent, one place to modify css, titles and stuff
 *
 * @todo ILL and Acqisition proposal should be here too
 *  - @see templates/RecordDriver/SolrGBV/result-list.phtml
 *  - @see templates/RecordDriver/Primo/result-list.phtml
 *
 * @return void
 */
function displayHoldingGuide() {
  var id = $.map($('.ajaxItem'), function(i) {
    return $(i).find('.hiddenId')[0].value;
  });
  if (!id.length) {
    return;
  }

  var currentId;
  var record_number;
  var xhr;
  for (var ids in id) {
    currentId = id[ids];
    $.ajax({
        dataType: 'json',
        url: path + '/AJAX/JSON?method=getItemStatuses',
        data: {"id[]":currentId, "record_number":ids},
        beforeSend: function(xhr, settings) { xhr.rid = ids; },
        success: function(response, status, xhr) {
          if(response.status == 'OK') {
          $.each(response.data, function(i, result) {

          //alert( result.id + ':' + xhr.rid );

          var item = $($('.ajaxItem')[xhr.rid]);

          // Early exit: display volumes button (if this item has volumes)
          if (result.multiVols == true) {
              loc_button    = '<a href="../../Record/'+ result.id +'/TomesVolumes#tabnav" title="'+title+'" class="fa fa-stack-overflow holdlink holdtomes"> ' + vufindString.loc_volumes + '</a>';
              loc_modal_link = create_modal(id          = result.id,
                                            loc_code    = 'Multi',
                                            link_title  = vufindString.loc_modal_Title_multi,
                                            modal_title = vufindString.loc_modal_Title_multi,
                                            modal_body  = vufindString.loc_modal_Body_multi,
                                            iframe_src  = '',
                                            modal_foot  = '');
              bestOption = loc_button + ' ' + loc_modal_link;
              item.find('.holdtomes').removeClass('hidden');
              item.find('.holdlocation').empty().append(loc_modal_link);
              // If something has multiple volumes, our voyage ends here already;
              // @todo: It does, doesn't it? It happens only for print (so no E-Only info icon is needed)
              return true;
          }
          
          // Some helper variables
          var loc_abbr;
          var loc_button;
          var loc_modal_title = vufindString.loc_modal_Title_shelf_generic + result.callnumber + ' (' + result.bestOptionLocation + ')';
          var loc_modal_body;
          var loc_shelf   = result.callnumber.substring(0, 2);

          // Add some additional infos for TUBHH holdings
          if (result.bestOptionLocation.indexOf('Lehr') > -1) {
            loc_abbr = 'LBS';   loc_modal_body = vufindString.loc_modal_Body_shelf_lbs + loc_shelf + '.';
          }
          else if (result.bestOptionLocation.indexOf('1') > -1) {
            loc_abbr = 'LS1';   loc_modal_body = vufindString.loc_modal_Body_shelf_ls1 + loc_shelf + '.';
          }
          else if (result.bestOptionLocation.indexOf('2') > -1) {
            loc_abbr = 'LS2';   loc_modal_body = vufindString.loc_modal_Body_shelf_ls2 + loc_shelf + '.';
          }
          else if (result.bestOptionLocation.indexOf('Sonderstandort') > -1) {
            loc_abbr = 'SO';    loc_modal_body = vufindString.loc_modal_Title_service_da;
          }
          else if (result.bestOptionLocation.indexOf('Semesterapparat') > -1) {
            loc_abbr = 'SEM';   loc_modal_body = vufindString.loc_modal_Body_sem + '.';
          }
          else if (result.electronic == '1') {
            loc_abbr = 'DIG';   loc_modal_body = vufindString.loc_modal_Body_eonly;
          }
          else {
            loc_abbr = 'Umm?';
           // alert('Hier ist ein komischer Fall bei '+result.callnumber);
          }

          var bestOption = '';
          switch(result.patronBestOption) {
            case 'e_only':
              // No button to show. Show only if it is no broken record
              if (result.missing_data !== true && result.bestOptionLocation != 'Unknown') {
                loc_modal_link = create_modal(id          = result.id, 
                                              loc_code    = loc_abbr,
                                              link_title  = vufindString.loc_modal_Title_eonly,
                                              modal_title = result.bestOptionLocation,
                                              modal_body  = loc_modal_body,
                                              iframe_src  = '',
                                              modal_foot  = '',
                                              icon_class  = 'tub_fa-info_e');
                bestOption = loc_modal_link;
              }
              break;
            case 'shelf': //fa-hand-lizard-o is nice too (but only newest FA)
              loc_button    = '<a href="../../Record/'+ result.id +'/Holdings#tabnav" title="' + loc_modal_body + '" class="fa fa-map-marker holdlink holdshelf"> ' + loc_abbr + ' ' + result.callnumber + '</a>';
              loc_modal_link = create_modal(id          = result.id, 
                                            loc_code    = loc_abbr,
                                            link_title  = loc_modal_body,
                                            modal_title = loc_modal_title,
                                            modal_body  = loc_modal_body,
                                            iframe_src  = '',
                                            modal_foot  = '');
              bestOption = loc_button + ' ' + loc_modal_link;
              break;
            case 'order':
              loc_button    = '<a href="'+result.bestOptionHref+'" target="_blank" title="'+vufindString.loc_btn_Hover_order+'" class="fa fa-upload holdlink holdorder"> '+vufindString.hold_place+'</a>';
              loc_modal_link = create_modal(id          = result.id, 
                                            loc_code    = 'Magazin',
                                            link_title  = vufindString.loc_btn_Hover_order,
                                            modal_title = vufindString.loc_modal_Title_order,
                                            modal_body  = vufindString.loc_modal_Body_order,
                                            iframe_src  = result.bestOptionHref,
                                            modal_foot  = '');
              bestOption = loc_button + ' ' + loc_modal_link;
              break;
            case 'reserve_or_local':
              // just continue, don't break;
            case 'reserve':
              title = vufindString.loc_modal_Title_reserve + result.duedate;
              loc_button    = '<a href="'+result.bestOptionHref+'" target="_blank" title="'+title+'" class="fa fa-clock-o holdlink holdreserve"> '+vufindString.recall_this+'</a>';
              loc_modal_link = create_modal(id          = result.id, 
                                            loc_code    = 'Loaned',
                                            link_title  = title,
                                            modal_title = title,
                                            modal_body  = vufindString.loc_modal_Body_reserve,
                                            iframe_src  = result.bestOptionHref,
                                            modal_foot  = '');
              bestOption = loc_button + ' ' + loc_modal_link;
              if (result.patronBestOption !== 'reserve_or_local') break;
            case 'local':
              // Todo: is it necessary to use result.reference_callnumber and result.reference_location. It might be...?
              title = loc_modal_body+ '\n' + vufindString.loc_modal_Title_refonly_generic;
              loc_button    = '<a href="../../Record/'+ result.id +'/Holdings#tabnav" title="'+title+'" class="fa fa-home holdlink holdrefonly"> ' + loc_abbr + ' ' + result.callnumber + '</a>';
              loc_modal_link = create_modal(id          = result.id, 
                                            loc_code    = loc_abbr,
                                            link_title  = title,
                                            modal_title = loc_modal_title,
                                            modal_body  = loc_modal_body+' ' + vufindString.loc_modal_Title_refonly_generic,
                                            iframe_src  = '',
                                            modal_foot  = '');
              bestOption = bestOption + loc_button + ' ' + loc_modal_link;
              break;
            case 'service_desk':
              loc_button    = '<a href="../../Record/'+ result.id +'/Holdings#tabnav" title="'+vufindString.loc_modal_Title_service_da+'" class="fa fa-frown-o holdlink"> SO ' + result.callnumber + '</a>';
              loc_modal_link = create_modal(id          = result.id, 
                                            loc_code    = loc_abbr,
                                            link_title  = vufindString.loc_modal_Title_service_da,
                                            modal_title = vufindString.loc_modal_Title_service_da,
                                            modal_body  = vufindString.loc_modal_Body_service_da,
                                            iframe_src  = '',
                                            modal_foot  = '');
              bestOption = loc_button + ' ' + loc_modal_link;
              break;
            case 'false':
              // Remove the "Loading..." - bestoption is and stays empty
              break;
            default:
              loc_button    = '<a href="../../Record/'+ result.id +'/Holdings#tabnav" title="'+vufindString.loc_modal_Title_service_else+'" class="fa fa-frown-o holdlink"> '+vufindString.loc_modal_Title_service_else+'</a>';
              loc_modal_link = create_modal(id          = result.id, 
                                            loc_code    = 'Unknown',
                                            link_title  = vufindString.loc_modal_Title_service_else,
                                            modal_title = vufindString.loc_modal_Title_service_else,
                                            modal_body  = vufindString.loc_modal_Body_service_da,
                                            iframe_src  = '',
                                            modal_foot  = '');
              bestOption = loc_button + ' ' + loc_modal_link;              
          } 

          // Show link to printed edition for electronic edition (if available)
          // Todo: can we show the exact location?
          if (result.link_printed != null) {
              loc_button = '<a href="'+ result.link_printed_href +'" title="'+vufindString.loc_modal_Title_printEdAvailable+'" class="fa fa-book holdlink"> '+vufindString.available_printed+'</a>';
              loc_modal_link = create_modal(id          = result.id,
                                            loc_code    = loc_abbr,
                                            link_title  = vufindString.loc_modal_Title_printEdAvailable,
                                            modal_title = vufindString.loc_modal_Title_printEdAvailable,
                                            modal_body  = vufindString.loc_modal_Body_printEdAvailable,
                                            iframe_src  = '',
                                            modal_foot  = '');
            bestOption = loc_button + loc_modal_link;
            // Change the link to article container into parentlink (the journal this article has been published in)
            item.find('.parentlink').attr('href', result.parentlink);
          }

          // Add clarifying text for some availability information
          /* Not needed anymore - see above + the buttons tell everything a patron needs to know
              if      (result.presenceOnly == '1') {item.find('.status').append(' Nur Präsenznutzung');}
              else if (result.presenceOnly == '2') {item.find('.status').append(' Siehe Vollanzeige');}
              else if (result.presenceOnly == '3') {item.find('.status').append(' Auch Präsenzexemplar verfügbar');}
          }
          */

          // SFX-Hack: If nothing is found, a very small dummy gif is returned.
          // If so, hide the controls (or just the image), so everything else around
          // is displayed nicely (not indented etc.). Maybe better in \themes\bootstrap3-tub\js\openurl.js
          sfx_fix = item.find('.openUrlControls');
          if (sfx_fix.innerWidth() < 10) sfx_fix.hide();

          // Show our final result!
          item.find('.holdlocation').empty().append(bestOption);

          // Fulltext-Hack
          // Seriously shouldn't be here, but the idea is not that bad, basically? :)
          // Next step: put it into a modal, so the full link can easily be shown - or links (I think there are such cases?)
          // Remove > append to oa-fulltextes > add button classes
          item.find('.grab-fulltext1').detach().appendTo(item.find('.oa-fulltextes')).addClass('fa holdlink');

          // - Call number must be 7 characters long (=reading room), it must be available (else = reserve button, )
          // - https://coderwall.com/p/6uxw7w/simple-multilanguage-with-jquery - might even nearly use the language inis)
          // - 2015-09-04: Vufind already has a JS way to translate; example in \themes\bootstrap3-tub\templates\layout\layout.phtml
          // TODO (Finally)
          // - Readd the location as part of the bibliographic information somewhere else (if it is a DOI, URN or maybe a publisher direct link)
//          } else if (result.callnumber.indexOf('D') === 0 && result.availability == 'false') {
//            alert('DA');
//          else if (result.callnumber != 'Unknown' && result.callnumber.length == 7 || result.availability == 'false') {
        });
      } else {
        // display the error message on each of the ajax status place holder
        item.find('.holdlocation').empty().append(response.data);
      }
      // (Why?)
      //item.find('.holdlocation').removeClass('holdlocation');

    }
  });
  }
}


function create_modal(id, loc_code, link_title, modal_title, modal_body, iframe_src = '', modal_foot = '', icon_class='tub_fa-info_p') {
  var modal;
  var iframe = '';
  
  if (iframe_src != '') {
    iframe = ' data-iframe="'+iframe_src+'" ';
  }
  
  modal = '<a href="#" id="info-'+id+'" title="' + link_title + '" style="float: right" class="locationInfox modal-link hidden-print"><i class="fa fa-info-circle '+icon_class+'"></i><span data-title="' + modal_title + '" data-location="' + loc_code +'" '+iframe+' class="modal-dialog hidden">'+modal_body+modal_foot+'</span></a>';
  
  return modal;
}


$(document).ready(function() {
//  checkItemStatuses();
  displayHoldingGuide();

  //https://stackoverflow.com/questions/1359018/in-jquery-how-to-attach-events-to-dynamic-html-elements
  // Todo: 
  // - Maybe don't use a (skip "(event) {event.preventDefault();...")
  $('.tub_holdingguide').on('click', 'a.locationInfox', function(event) {
    event.preventDefault();

    var loc = $(this).children('span').attr('data-location');
    var additional_content = '';
    var loan4_url;
    
    var frameMaxHeight = window.innerHeight - 250;
    if (frameMaxHeight > 550) frameMaxHeight = 550;

    if (loc == 'Loaned') {
      loan4_url = $(this).children('span').attr('data-iframe');
      additional_content = 'DAS IST NUR EIN TEST ERSTMAL (eigentlich steht hier nur der vorangegangene Text)<br /><iframe id="loan4" src="' + loan4_url + '" width="100%" min-height="465px" height="'+frameMaxHeight+'px"/>';
    }
    else if (loc == 'Magazin') {
      loan4_url = $(this).children('span').attr('data-iframe');
      additional_content = 'DAS IST NUR EIN TEST ERSTMAL (eigentlich steht hier nur der vorangegangene Text)<br /><iframe id="loan4" src="' + loan4_url + '" width="100%" min-height="465px" height="'+frameMaxHeight+'px"/>';
    }
    else if (loc == 'SO' || loc == 'Multi') {
        //
    }
    else if (loc == 'DIG') {
//      additional_content = 'Angehörige der TU (Mitarbeiter und Studenten) können von zu Hause auf solche Ressourcen via VPN-Client (<a href="https://www.tuhh.de/rzt/vpn/" target="_blank">Informationen des RZ</a>) zugreifen. In eiligen Fällen empfehlen wir das <a href="https://webvpn.rz.tu-harburg.de/" target="_blank">WebVPN</a>. Melden Sie sich dort mit ihrer TU-Kennung an und beginnen dann ihre Suche im Katalog dort.';
    }
    else {
      // Got shelf location
      var roomMap = [];
      roomMap['LS1'] = 'https://www.tub.tuhh.de/wp-content/uploads/2012/08/LS1web_neu1.jpg';
      roomMap['LS2'] = 'https://www.tub.tuhh.de/wp-content/uploads/2012/08/LS2web_neu1.jpg';
      roomMap['LBS'] = roomMap['LS1'];
      roomMap['SEM'] = roomMap['LS2'];
      additional_content = '<img src="'+ roomMap[loc] +'" />';
    }

    // TODO: Lightbox has methods to do this?
    $('#modalTitle').html($(this).children('span').attr('data-title'));
    $('.modal-body').html('<p>'+ $(this).children('span').text() + '</p>' + additional_content);


    // TEST: Force loan4 logoff, delay it a little so the iframe can be reloaded with the logoff url
    // Argh, with something like alert after the src change it works, timeout etc. does not. Ok, fix this later, already solved this some time ago somewhere else...
    // NOTE - JUST REMOVE ID 'loan4' - should be the way
    // NOTE: it's default to stay logged in unless the close link is clicked OR 
    //  the session times out in loan4 (the forced log off would be new, albeit 
    //  could be a hassle for patrons that want to request multiple items in sucession)
    function closeLoan4() {
      $('#loan4').attr("src", 'https://katalog.b.tuhh.de/LBS_WEB/j_spring_security_logout');
      //.delay(2500);
      //$('#loan4').remove();
      alert('Logged off');
    }
    // Add the function as close action if loan4_url is used
    if (loan4_url !== undefined) Lightbox.addCloseAction(closeLoan4);

    // Show everything
    return $('#modal').modal('show');
  });
});







// Deprecated
// TODO: What is - and why would we (still) ever want
// - result.full_status
// - locationListHTML
function checkItemStatuses() {
  var id = $.map($('.ajaxItem'), function(i) {
    return $(i).find('.hiddenId')[0].value;
  });
  if (!id.length) {
    return;
  }

  $(".ajax-availability").removeClass('hidden');
  var currentId;
  var record_number;
  var xhr;
  for (var ids in id) {
    currentId = id[ids];
    $.ajax({
        dataType: 'json',
        url: path + '/AJAX/JSON?method=getItemStatuses',
        data: {"id[]":currentId, "record_number":ids},
        beforeSend: function(xhr, settings) { xhr.rid = ids; },
        success: function(response, status, xhr) {
          if(response.status == 'OK') {
          $.each(response.data, function(i, result) {

          //alert( result.id + ':' + xhr.rid );

          var item = $($('.ajaxItem')[xhr.rid]);

          item.find('.status').empty().append(result.availability_message);
          item.find('.status').append(vufindString[result.additional_availability_message]);
          if (typeof(result.full_status) != 'undefined'
            && result.full_status.length > 0
            && item.find('.callnumAndLocation').length > 0
          ) {
            // Full status mode is on -- display the HTML and hide extraneous junk:
            item.find('.callnumAndLocation').empty().append(result.full_status);
            item.find('.callnumber').addClass('hidden');
            item.find('.location').addClass('hidden');
            item.find('.hideIfDetailed').addClass('hidden');
            item.find('.status').addClass('hidden');
          } else if (typeof(result.missing_data) != 'undefined'
            && result.missing_data
          ) {
            // No data is available -- hide the entire status area:
            item.find('.callnumAndLocation').addClass('hidden');
            item.find('.status').addClass('hidden');
          } else if (result.locationList) {
            // We have multiple locations -- build appropriate HTML and hide unwanted labels:
            // TODO TZ 2015-08-28: If availability is given per location, we should use a priority list,
            // showing the first with availability. Like  "Lehrbuchsammlung" then "Lesesaal" then
            // "Magazin" - just show the fastest way. Would also solve the problem, that otherwise the
            // link for the location might go to "Dienstapparat" while this is the least useful location
            item.find('.callnumber').addClass('hidden');
            item.find('.hideIfDetailed').addClass('hidden');
            item.find('.location').addClass('hidden');

            var locationListHTML = "";
            for (var x=0; x<result.locationList.length; x++) {
              locationListHTML += '<div class="groupLocation">';
              if (result.locationList[x].availability) {
                locationListHTML += '<i class="fa fa-ok text-success"></i> <span class="text-success">'
                  + result.locationList[x].location + '</span> ';
              } else {
                locationListHTML += '<i class="fa fa-remove text-error"></i> <span class="text-error"">'
                  + result.locationList[x].location + '</span> ';
              }
              locationListHTML += '</div>';
              locationListHTML += '<div class="groupCallnumber">';
              locationListHTML += (result.locationList[x].callnumbers)
                   ?  result.locationList[x].callnumbers : '';
              locationListHTML += '</div>';
            }
            item.find('.locationDetails').removeClass('hidden');
            item.find('.locationDetails').empty().append(locationListHTML);
          } else {
            // Default case -- load call number and location into appropriate containers:
            item.find('.callnumber').empty().append(result.callnumber+'<br/>');
            item.find('.location').empty().append(
              result.reserve == 'true'
              ? result.reserve_message
              : result.location
            );
          }
          
          // Show link to printed edition for eletronic edition (if available)
          if (result.link_printed != null) {
            item.find('.callnumAndLocation').removeClass('hidden');
            item.find('.printedItem').removeClass('hidden');
            item.find('.printedItem').empty().append(result.link_printed);
            item.find('.parentlink').attr('href', result.parentlink);
          }
          
          // Hide location if not available
          if (result.location == '') {
              item.find('.location').addClass('hidden');
              item.find('.locationLabel').addClass('hidden');
          }
          
          // Hide location if not available; @note: Currently never applied. It's always at least 'Unknown'
          if (result.callnumber == '') {
              item.find('.callnumber').addClass('hidden');
              item.find('.hideIfDetailed').addClass('hidden');
          }
                   
          // Show the link for reserving the item (if it exists)
          if (result.reservationUrl) {
              item.find('.order').empty().append(result.reservationUrl);
              item.find('.order a').addClass('fa fa-clock-o');
              item.find('.order a').prepend(' '); // why is reservationUrl the complete html?
// TODO b: This should not be put in the same position as the stack order button - logically bad              
if (result.availability == 'true') {
var loc_modal_href = item.find('.order a').attr('href');
$(' <a href="#" id="info-'+result.id+'" title="Magazinbestellung" style="float: right" class="locationInfox modal-link hidden-print"><i class="fa fa-info-circle tub_fa-info"></i><span data-title="Magazinbestellung" data-location="Magazin" data-iframe="'+loc_modal_href+'" class="modal-dialog hidden">Das Medium befindet sich im Magazin. Nach dem Bestellen können Sie es in 30 Minuten an den Serviceplätzen abholen</span></a>').insertAfter(item.find('.order'));
}          
          }
          else {
              item.find('.order').empty();
          }

          // Show due date if item can be reserved
          if (result.duedate && result.availability == 'false' && result.reserve != 'true') {
              item.find('.status').append(' bis '+result.duedate);
              //item.find('.order').empty().append(' <a href="https://katalog.b.tu-harburg.de/LBS_WEB/titleReservation.htm?BES=1&LAN=DU&USR=1000&PPN='+result.id+'">Titel vormerken</a>');
// TODO a: This should not be put in the same position as the stack order button - logically bad              
var loc_modal_href = item.find('.order a').attr('href');
$(' <a href="#" id="info-'+result.id+'" title="Medium vormerken (verfügbar ab '+result.duedate+')" style="float: right" class="locationInfox modal-link hidden-print"><i class="fa fa-info-circle tub_fa-info"></i><span data-title="Medium vormerken (verfügbar ab '+result.duedate+')" data-location="Loaned" data-iframe="'+loc_modal_href+'" class="modal-dialog hidden">Das Medium ist derzeit entliehen. Sie können es vormerken. Beachten Sie, dass Gebühren von 0,80 EUR entstehen.</span></a>').insertAfter(item.find('.order'));
          }
          
          // Add clarifying text for some availability information
          if (result.electronic == '1') {
              item.find('.status').append(' evtl. nur im TUHH-Intranet');
          }
          else {
              if (result.presenceOnly == '1') {
                item.find('.status').append(' Nur Präsenznutzung');
              }
              else if (result.presenceOnly == '2') {
                item.find('.status').append(' Siehe Vollanzeige');
              }
              else if (result.presenceOnly == '3') {
                item.find('.status').append(' Auch Präsenzexemplar verfügbar');
              }
          }
          
          // Add some button like "fetch it yourself" with link to reading room overview
          // Call number must be 7 characters long (=reading room), it must be available (else = reserve button, )
          //  > NOTE: Otherwise Status & Call Number & Location are nearly alway useless for a patron - why bother to irritate him/her?
          //  > TODO: Call number is fine. Now we need a way to uniquly, easily and without wasting space identifiy LS1, LS2 and LBS ...
          //          hmmm, we got no translations, so it's only three checks for now...
          //          (btw: https://coderwall.com/p/6uxw7w/simple-multilanguage-with-jquery - might even nearly use the language inis)
          //          2015-09-04: Vufind already has a JS way to translate; example in \themes\bootstrap3-tub\templates\layout\layout.phtml
          // > TODO2: A loc_modal_bodyshould be added to all buttons (Hold, Inter library Loan etc...)
          // > TODO3: Institute (call )
          if (result.callnumber != 'Unknown' && result.callnumber.length == 7 && !result.duedate && result.availability == 'true' && result.reserve != 'true') {
            var loc_abbr;
            var loc_modal_;

            var loc_plain = 'Signatur: ' + result.callnumber + ' (' + $(result.location).text() + ')';
            var loc_shelf = result.callnumber.substring(0, 2); 
            
            if (result.location.indexOf('Lehr') > -1) {
              loc_abbr = 'LBS';   loc_modal_body= 'Sie finden das Medium in der Lehrbuchsammlung (LBS) im Regal '+loc_shelf+'. Die Lehrbuchsammlung ist im Lesesaal 1 auf der Seite des Eingangs.';
            }
            else if (result.location.indexOf('1') > -1) {
              loc_abbr = 'LS1';   loc_modal_body= 'Sie finden das Medium im Lesesaal 1 (LS1) im Regal '+loc_shelf+'.';
            }
            else if (result.location.indexOf('2') > -1) {
              loc_abbr = 'LS2';   loc_modal_body= 'Sie finden das Medium im Lesesaal 2 (LS2) im Regal '+loc_shelf+'.';
            }
            else {
              loc_abbr = 'Umm? Sem-App?';
              alert('Hier ist ein komischer Fall bei '+result.callnumber);
            }

            // Location button
            item.find('.holdlocation').empty().append('<a href="../../Record/'+ result.id +'/Holdings#tabnav" class="fa fa-map-marker holdlink"> ' + loc_abbr + ' ' + result.callnumber + '</a>').removeClass('hidden');

            // Info link; yeah dumb positioning - proof of concept... :)
            var loc_modal_href = item.find('.location a').attr('href'); // DUMB, just proof of concept - where to get the link in the first place?
            $(' <a href="#" id="info-'+result.id+'" title="' + loc_modal_body+ '" style="float: right" class="locationInfox modal-link hidden-print"><i class="fa fa-info-circle tub_fa-info"></i><span data-title="' + loc_plain + '" data-location="' + loc_abbr +'" class="modal-dialog hidden">'+loc_modal_+'</span></a>').insertAfter(item.find('.holdlocation'));

            // Showing the table is useless now - the buttons cover it all. To verfy it, don't hide everything yet 
            // TODO (Finally)
            // - Remove the table html
            // - Remove all associated styles and scripts
            // - Readd the location as part of the bibliographic information somewhere else (if it is a DOI, URN or maybe a publisher direct link)
            item.find('.tub_titlestatus').hide();
          } else if (result.callnumber.indexOf('D') === 0 && result.availability == 'false') {
            //alert('DA');
            item.find('.holdlocation').empty().append('<a href="../../Record/'+ result.id +'/Holdings#tabnav" class="fa fa-frown-o holdlink"> Sonderstandort DA</a>').removeClass('hidden');

$(' <a href="#" id="info-'+result.id+'" title="Dienstapparat-Exemplar" style="float: right" class="locationInfox modal-link hidden-print"><i class="fa fa-info-circle tub_fa-info"></i><span data-title="Dienstapparat-Exemplar: Nicht entleihbar" data-location="DA" class="modal-dialog hidden">Das Medium befindet sich im Dienstapparat eines Instituts und ist nicht entleihbar. Sollten Sie dennoch dringenden Bedarf an genau diesem Buch haben: <ul><li>Wenden Sie sich an den Serviceplatz</li><li>Oder schreiben Sie uns eine Mail mit dem Betreff \"DA '+result.callnumber+'\" an bibliothek@tuhh.de [HIER GLEICH FORMULAR REIN]</li><li>Oder rufen Sie uns an xyz</li></ul>Ganz eilig?<ul><li>Prüfen Sie selber, ob evtl. eine andere Ausgabe verfügbar ist (wenn wir geRDAt sind, sagen wir Ihnen das hier direkt)</li><li>Erstellen Sie einen <x href="#">Buchwunsch</x></li></ul></span></a>').insertAfter(item.find('.order'));
            item.find('.tub_titlestatus').hide();
          }
          else if (result.callnumber != 'Unknown' && result.callnumber.length == 7 || result.availability == 'false') {
            // See TODO above
            $('<strong style="font-size: 0.7em">Hier fehlt noch eine Button-Lösung (Leihfristende anzeigen, Nicht entleihbar was machen...)</strong>').insertBefore(item.find('table.tub_titlestatus'));
          } else {
            // See TODO above
            // Very sure - everything can safely be hidden (resp. removed finally)
            item.find('.tub_titlestatus').hide();
          }
          
        });
      } else {
        // display the error message on each of the ajax status place holder
        $(".ajax-availability").empty().append(response.data);
      }
      $(".ajax-availability").removeClass('ajax-availability');
    }
  });
  }
}