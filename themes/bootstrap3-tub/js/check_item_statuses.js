/*global path*/

/**
 * Only show the best action(s) a patron can take for a certain record.
 *
 * @note  This function is refactored from checkItemStatuses(). Since the
 *              purpose changed to the one given in the tagline, some things
 *        didn't make it over here. There is no more a check for:
 *        1.    result.full_status
 *              Enabled via $config->Item_Status->show_full_status
 *        2.  result.locationList
 *              Enabled via $config->Item_Status->multiple_locations
 *              Both were/are rendered via AjaxController::getItemStatusesAjax() +
 *              some otheres.
 *        The original checkItemStatuses() was removed. As reference for above
 *        checks checkItemStatuses_ref_oldstyle() remains
 *
 * @note
 *
 * @note Stuff to remember, that might be of use even though not used (anymore)
 * - Call number must be 7 characters long (=reading room), it must be available (else = reserve button, )
 * - https://coderwall.com/p/6uxw7w/simple-multilanguage-with-jquery - might even nearly use the language inis)
 *
 * @todo: Inline way to show options AND show correct record link
 *  - <span class="holdtomes hidden"><a href="<?=$this->recordLink()->getTabUrl($this->driver, 'TomesVolumes')?>#tabnav" class="holdlink fa fa-stack-overflow"> <?=$this->transEsc("See Tomes/Volumes")?></a></span>
 *  - For now stick to generating Buttons and infos here. Decide later to
 *      - either use the template way
 *      - or use stick to the current JS way
 *      - but be consistent, one place to modify css, titles and stuff
 * - Readd the location as part of the bibliographic information somewhere else (if it is a DOI, URN or maybe a publisher direct link)
 *
 * @todo ILL and Acqisition proposal should be here too
 *  - @see templates/RecordDriver/SolrGBV/result-list.phtml
 *  - @see templates/RecordDriver/Primo/result-list.phtml
 *
 * @note Since 2015-10-12 Daia return the full callnumber with location
 * (xx:xxxx-xxxx)
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

          // Here we start figuring out what we have to show on implicit information
          // Some helper variables
          var loc_abbr;
          var loc_button;
          var loc_shelf  = result.callnumber.substring(0,2);
          var loc_callno = result.callnumber;
          var loc_modal_title = vufindString.loc_modal_Title_shelf_generic + loc_callno + ' (' + result.bestOptionLocation + ')';
          var loc_modal_body;

          // Add some additional infos for TUBHH holdings
          if (result.bestOptionLocation.indexOf('Lehr') > -1) {
            loc_abbr = 'LBS';  loc_modal_body = vufindString.loc_modal_Body_shelf_lbs + loc_shelf + '.';
          }
          else if (result.bestOptionLocation.indexOf('Lesesaal 1') > -1) {
            loc_abbr = 'LS1';  loc_modal_body = vufindString.loc_modal_Body_shelf_ls1 + loc_shelf + '.';
          }
          else if (result.bestOptionLocation.indexOf('Lesesaal 2') > -1) {
            loc_abbr = 'LS2';  loc_modal_body = vufindString.loc_modal_Body_shelf_ls2 + loc_shelf + '.';
          }
          else if (result.bestOptionLocation.indexOf('Sonderstandort') > -1) {
            loc_abbr = 'SO';    loc_modal_body = vufindString.loc_modal_Title_service_da;
          }
          else if (result.bestOptionLocation.indexOf('Semesterapparat') > -1) {
            loc_abbr = 'SEM';  loc_modal_body = vufindString.loc_modal_Body_sem + '.';
          }
          /* 2015-10-01 added @see http://redmine.tub.tuhh.de/issues/624 */
          else if (result.electronic == '1' && (result.locHref !== '' && result.locHref !== false)) {
            loc_abbr = 'WEB';  loc_modal_body = vufindString.loc_modal_Body_eMarc21;
          }
          // Electronic, but without link; pretty special case. It IS electronic, but let's handle it as false
          // Example case (cd rom): http://lincl1.b.tu-harburg.de:81/vufind2-test/Record/268707642
          else if (result.electronic == '1') {
            loc_abbr = 'DIGfail';  loc_modal_body = vufindString.loc_modal_Body_eonly;
            result.patronBestOption = false;
          }
          else if (result.bestOptionLocation.indexOf('Shipping') > -1) {
            loc_abbr = 'ACQ';  loc_modal_body = vufindString.loc_modal_Body_acquired;
          }
          else {
            loc_abbr = 'Undefined';
           // alert('Hier ist ein komischer Fall bei '+loc_callno);
          }


          // Early exit: display VOLUMES button (if this item has volumes)
          if (result.multiVols == true) {
            /* 2015-12-03: only create modal
            loc_button = create_button(href   = path + '/Record/'+ result.id +'/TomesVolumes#tabnav',
                                       hover  = vufindString.loc_modal_Title_multi,
                                       text   = vufindString.loc_volumes,
                                       icon   = 'fa-stack-overflow',
                                       css_classes = 'holdtomes');
            loc_modal_link = create_modal(id          = result.id,
                                          loc_code    = 'Multi',
                                          link_title  = vufindString.loc_modal_Title_multi,
                                          modal_title = vufindString.loc_modal_Title_multi,
                                          modal_body  = vufindString.loc_modal_Body_multi + xy(),
                                          iframe_src  = '',
                                          modal_foot  = '');
            bestOption = loc_button + ' ' + loc_modal_link;
            */
            // Create a readin room button (last 5 years) - use same button as for case 'local'
            loc_modal_button_last5years = '';
            title = loc_modal_body+ '\n' + vufindString.loc_modal_Title_refonly_generic;
            if (loc_abbr == 'LS1' || loc_abbr == 'LS2') {
                loc_modal_button_last5years = create_modal(id = result.id,
                                            loc_code    = loc_abbr,
                                            link_title  = title,
                                            modal_title = loc_modal_title,
                                            modal_body  = loc_modal_body+' ' + vufindString.loc_modal_Title_refonly_generic,
                                            iframe_src  = '',
                                            modal_foot  = '',
                                            icon_class  = 'holdrefonly',
                                            icon        = 'fa-home',
                                            text        = loc_abbr + ' ' + loc_callno);
            }
            // Add button "See volumes"
            loc_modal_button_volumes = create_modal(id = result.id,
                                          loc_code    = 'Multi',
                                          link_title  = vufindString.loc_modal_Title_multi,
                                          modal_title = vufindString.loc_modal_Title_multi,
                                          modal_body  = vufindString.loc_modal_Body_multi,
                                          iframe_src  = '',
                                          modal_foot  = '',
                                          icon_class  = 'holdtomes',
                                          icon        = 'fa-stack-overflow',
                                          text        = vufindString.loc_volumes);
            bestOption = loc_modal_button_last5years + loc_modal_button_volumes;
            //item.find('.holdtomes').removeClass('hidden');
            item.find('.holdlocation').empty().append(bestOption);
            // If something has multiple volumes, our voyage ends here already;
            // @todo: It does, doesn't it? It happens only for print (so no E-Only info icon is needed)

            // preload volume list
            // @todo: loads too much
            setTimeout(function(){
                $.ajax({
                    dataType: 'json',
                    url: path + '/AJAX/JSON?method=loadVolumeList',
                    data: {"id":result.id}
                });
            }, 500);

            return true;
          }
          // Future: Here we would like another "early exit" for "e-only"


          // Do the job as intended - no more early exits
          // Return the one BEST OPTION as JSon - create button and info modal
          var bestOption = '';
          var fallbackOption = ''; // we need this, because the sfx button comes from another source - and we have to check, if it exists before adding buttons in some cases
          switch(result.patronBestOption) {
            case 'e_only':
              // No button to show. Show only if it is no broken record AND no SFX button exists (see sfx_fix)
              if (result.missing_data !== true && result.bestOptionLocation != 'Unknown' && result.locHref != '') {
                /* 2015-09-28: MOVED to sfx_fix below
                This check should work. But we have a better way. Indirectly SFX
                tells us if no electronic versions is found by returning a 1px
                image. Thus we already have everything to display a hint next to
                the button.
                @Note: We could also add the hint in the template itself, but
                here we don't have to do it for each driver.
                */

                /* 2015-10-01 @see http://redmine.tub.tuhh.de/issues/624 */
                title = loc_abbr;
                if (result.bestOptionLocation == result.locHref) {
                  title_modal = title;
                } else {
                  title_modal = result.bestOptionLocation;
                }

                loc_button = create_button(href   = result.locHref,
                                           hover  = vufindString.loc_modal_Title_eMarc21,
                                           text   = title,
                                           icon   = 'fa-download',
                                           css_classes = 'holdelectronic');
                loc_modal_link = create_modal(id          = result.id,
                                              loc_code    = loc_abbr,
                                              link_title  = vufindString.loc_modal_Title_eMarc21,
                                              modal_title = vufindString.loc_modal_Title_eMarc21 +': '+title_modal,
                                              modal_body  = vufindString.loc_modal_Body_eMarc21,
                                              iframe_src  = result.locHref,
                                              modal_foot  = '');
                //Dont's show loc_abbr = WEB in addition to SFX-link
                fallbackOption = loc_button + ' ' + loc_modal_link;
              }
              break;
            case 'shelf': //fa-hand-lizard-o is nice too (but only newest FA)
              /* 2015-12-03: only create modal
              loc_button = create_button(href   = path + '/Record/'+ result.id +'/Holdings#tabnav',
                                         hover  = loc_modal_body,
                                         text   = loc_abbr + ' ' + loc_callno,
                                         icon   = 'fa-map-marker',
                                         css_classes = 'holdshelf');
              loc_modal_link = create_modal(id          = result.id,
                                            loc_code    = loc_abbr,
                                            link_title  = loc_modal_body,
                                            modal_title = loc_modal_title,
                                            modal_body  = loc_modal_body,
                                            iframe_src  = '',
                                            modal_foot  = '');
              bestOption = loc_button + ' ' + loc_modal_link;
              */
              loc_modal_button = create_modal(id          = result.id,
                                            loc_code    = loc_abbr,
                                            link_title  = loc_modal_body,
                                            modal_title = loc_modal_title,
                                            modal_body  = loc_modal_body,
                                            iframe_src  = '',
                                            modal_foot  = '',
                                            icon_class  = 'holdshelf',
                                            icon        = 'fa-map-marker',
                                            text        = loc_abbr + ' ' + loc_callno);
              bestOption = loc_modal_button;
              break;
            case 'order':
              /* 2015-12-03: only create modal
              loc_button = create_button(href   = result.bestOptionHref,
                                         hover  = vufindString.loc_btn_Hover_order,
                                         text   = vufindString.hold_place,
                                         icon   = 'fa-upload',
                                         css_classes = 'holdorder',
                                         target = '_blank');
              loc_modal_link = create_modal(id          = result.id,
                                            loc_code    = 'Magazin',
                                            link_title  = vufindString.loc_btn_Hover_order,
                                            modal_title = vufindString.loc_modal_Title_order,
                                            modal_body  = vufindString.loc_modal_Body_order,
                                            iframe_src  = result.bestOptionHref,
                                            modal_foot  = '');
              bestOption = loc_button + ' ' + loc_modal_link;
              */
              loc_modal_button = create_modal(id = result.id,
                                            loc_code    = 'Magazin',
                                            link_title  = vufindString.loc_btn_Hover_order,
                                            modal_title = vufindString.loc_modal_Title_order,
                                            modal_body  = vufindString.loc_modal_Body_order,
                                            iframe_src  = result.bestOptionHref,
                                            modal_foot  = '',
                                            icon_class  = 'holdorder',
                                            icon        = 'fa-upload',
                                            text        = vufindString.hold_place);
              bestOption = loc_modal_button;
              break;
            case 'reserve_or_local':
              // just continue, don't break;
            case 'reserve':
              title = vufindString.loc_modal_Title_reserve + result.duedate;
              /* 2015-12-03: only create modal
              loc_button = create_button(href   = result.bestOptionHref,
                                         hover  = title,
                                         text   = vufindString.recall_this,
                                         icon   = 'fa-clock-o',
                                         css_classes = 'holdreserve',
                                         target = '_blank');
              loc_modal_link = create_modal(id          = result.id,
                                            loc_code    = 'Loaned',
                                            link_title  = title,
                                            modal_title = title,
                                            modal_body  = vufindString.loc_modal_Body_reserve,
                                            iframe_src  = result.bestOptionHref,
                                            modal_foot  = '');
              bestOption = loc_button + ' ' + loc_modal_link;
              */
              loc_modal_button = create_modal(id = result.id,
                                            loc_code    = 'Loaned',
                                            link_title  = title,
                                            modal_title = title,
                                            modal_body  = vufindString.loc_modal_Body_reserve,
                                            iframe_src  = result.bestOptionHref,
                                            modal_foot  = '',
                                            icon_class  = 'holdreserve',
                                            icon        = 'fa-clock-o',
                                            text        = vufindString.recall_this);
              bestOption = loc_modal_button;
              if (result.patronBestOption !== 'reserve_or_local') break;
            case 'local':
              // Todo: is it necessary to use result.reference_callnumber and result.reference_location. It might be...?
              title = loc_modal_body+ '\n' + vufindString.loc_modal_Title_refonly_generic;
              /* 2015-12-03: only create modal
              loc_button = create_button(href   = path + '/Record/'+ result.id +'/Holdings#tabnav',
                                         hover  = title,
                                         text   = loc_abbr + ' ' + loc_callno,
                                         icon   = 'fa-home',
                                         css_classes = 'holdrefonly');
              loc_modal_link = create_modal(id          = result.id,
                                            loc_code    = loc_abbr,
                                            link_title  = title,
                                            modal_title = loc_modal_title,
                                            modal_body  = loc_modal_body+' ' + vufindString.loc_modal_Title_refonly_generic,
                                            iframe_src  = '',
                                            modal_foot  = '');
              bestOption = bestOption + loc_button + ' ' + loc_modal_link;
              */
              loc_modal_button = create_modal(id = result.id,
                                            loc_code    = loc_abbr,
                                            link_title  = title,
                                            modal_title = loc_modal_title,
                                            modal_body  = loc_modal_body+' ' + vufindString.loc_modal_Title_refonly_generic,
                                            iframe_src  = '',
                                            modal_foot  = '',
                                            icon_class  = 'holdrefonly',
                                            icon        = 'fa-home',
                                            text        = loc_abbr + ' ' + loc_callno);
              bestOption = loc_modal_button;
              break;
            case 'acquired':
              /* 2015-12-03: only create modal
              loc_button = create_button(href   = path + '/Record/'+ result.id +'/Holdings#tabnav',
                                         hover  = vufindString.loc_btn_Hover_acquired,
                                         text   = vufindString.loc_modal_Title_acquired,
                                         icon   = 'fa-plane',
                                         css_classes = 'holdacquired');
              loc_modal_link = create_modal(id          = result.id,
                                            loc_code    = loc_abbr,
                                            link_title  = vufindString.loc_btn_Hover_acquired,
                                            modal_title = vufindString.loc_modal_Title_acquired,
                                            modal_body  = loc_modal_body,
                                            iframe_src  = 'https://katalog.b.tuhh.de/DB=1/'+vufindString.opclang+'/PPN?PPN='+result.id,
                                            modal_foot  = '');
              bestOption = bestOption + loc_button + ' ' + loc_modal_link;
              */
              loc_modal_button = create_modal(id = result.id,
                                            loc_code    = loc_abbr,
                                            link_title  = vufindString.loc_btn_Hover_acquired,
                                            modal_title = vufindString.loc_modal_Title_acquired,
                                            modal_body  = loc_modal_body,
                                            iframe_src  = 'https://katalog.b.tuhh.de/DB=1/'+vufindString.opclang+'/PPN?PPN='+result.id,
                                            modal_foot  = '',
                                            icon_class  = 'holdacquired',
                                            icon        = 'fa-plane',
                                            text        = vufindString.loc_modal_Title_acquired);
              bestOption = loc_modal_button;
              break;
            case 'service_desk':
              /* 2015-12-03: only create modal
              loc_button = create_button(href   = path + '/Record/'+ result.id +'/Holdings#tabnav',
                                         hover  = vufindString.loc_modal_Title_service_da,
                                         text   = 'SO ' + loc_callno,
                                         icon   = 'fa-frown-o',
                                         css_classes = 'x');
              loc_modal_link = create_modal(id          = result.id,
                                            loc_code    = loc_abbr,
                                            link_title  = vufindString.loc_modal_Title_service_da,
                                            modal_title = vufindString.loc_modal_Title_service_da,
                                            modal_body  = vufindString.loc_modal_Body_service_da,
                                            iframe_src  = '',
                                            modal_foot  = '');
              bestOption = loc_button + ' ' + loc_modal_link;
              */
              loc_modal_button = create_modal(id = result.id,
                                            loc_code    = loc_abbr,
                                            link_title  = vufindString.loc_modal_Title_service_da,
                                            modal_title = vufindString.loc_modal_Title_service_da,
                                            modal_body  = vufindString.loc_modal_Body_service_da,
                                            iframe_src  = '',
                                            modal_foot  = '',
                                            icon_class  = 'x',
                                            icon        = 'fa-frown-o',
                                            text        = 'SO ' + loc_callno);
              bestOption = loc_modal_button;
              break;
            case 'false':
              // Remove the "Loading..." - bestoption is and stays empty
              // break;
            default:
              loc_button = create_button(href   = path + '/Record/'+ result.id +'/Holdings#tabnav',
                                         hover  = vufindString.loc_modal_Title_service_else,
                                         text   = vufindString.loc_modal_Title_service_else,
                                         icon   = 'fa-frown-o',
                                         css_classes = 'x');
              loc_modal_link = create_modal(id          = result.id,
                                            loc_code    = 'Undefined',
                                            link_title  = vufindString.loc_modal_Title_service_else,
                                            modal_title = vufindString.loc_modal_Title_service_else,
                                            modal_body  = vufindString.loc_modal_Body_service_else,
                                            iframe_src  = '',
                                            modal_foot  = '');
              fallbackOption = loc_button + ' ' + loc_modal_link;
          }

          // Show link to printed edition for electronic edition (if available)
          // Todo: can we show the exact location?
          if (result.link_printed != null) {
            loc_button = create_button(href   = path + '/Record/'+result.link_printed_href,
                                       hover  = vufindString.loc_modal_Title_printEdAvailable,
                                       text   = vufindString.available_printed,
                                       icon   = 'fa-book',
                                       css_classes = 'holdprinted');
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

          // Show our final result!
          item.find('.holdlocation').empty().append(bestOption);

          // SFX-Hack: If nothing is found, a very small dummy gif is returned.
          // If so, hide the controls (or just the image), so everything else around
          // is displayed nicely (not indented etc.). Maybe better in \themes\bootstrap3-tub\js\openurl.js
          // @todo: sometimes this check is too fast and the help show up...
          // correct way would be like https://api.jquery.com/ajaxSuccess/ or https://stackoverflow.com/a/9865124
          sfx_fix = item.find('.imagebased');
          sfx_available = true;
          if (sfx_fix.innerWidth() < 10) {
            sfx_fix.hide();
            sfx_available = false;

            // Fulltext-Hack
            // <strike>Seriously</strike> :) shouldn't be here, but the idea is not that bad, basically? :)
            // Next step: put it into a modal, so the full link can easily be shown - or links (I think there are such cases?)
            // Remove > append to oa-fulltextes > add button classes
            item.find('.grab-fulltext1').detach().appendTo(item.find('.oa-fulltextes')).addClass('fa holdlink');
          }
          // Alway show help if Electronic
          else {
            loc_modal_link = create_modal(id          = result.id,
                                          loc_code    = 'DIG',
                                          link_title  = vufindString.loc_modal_Title_eonly,
                                          modal_title = vufindString.loc_modal_Title_eonly,
                                          modal_body  = vufindString.loc_modal_Body_eonly,
                                          iframe_src  = '',
                                          modal_foot  = '',
                                          icon_class  = 'tub_fa-info_e');
            item.find('.imagebased').after(loc_modal_link);
          }

          // Final cleanup
          // If neither sfx is available and our fulltext hack didn't fill in
          // something, then show our fallback information
          if (sfx_available == false && item.find('.holdlink').length == 0) {
            item.find('.holdlocation').empty().append(fallbackOption);
          }

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



/**
 * Create a simple button for an action
 *
 * @param href          \b STR  Link to open on click
 * @param hover         \b STR  Title to show on hovering the link
 * @param text          \b STR  The link text
 * @param icon          \b STR  Some FontAwesome icon
 * @param css_classes   \b STR  All links get the classes "fa holdlink"
 *                              (+ the icon param); add some special class
 * @param target        \b STR  opt: target for link; leave empty for self
 * @param id            \b STR  opt: element id
 * @param custom        \b STR  opt: any other a tag parameter part
 *
 * @todo: id and custom only added for cart - not too nice
 *
 * @return \b STR link html
 */
function create_button(href, hover, text, icon, css_classes, target, id, custom) {
  //target = target || '';
  if (typeof target !== 'undefined') { target = 'target="'+target+'"'; }
  if (typeof id     !== 'undefined') { id = 'id="'+id+'"'; }
  custom = custom || '';
  var button;

  button    = '<a href="'+href+'" '+id+' title="'+hover+'" class="fa holdlink '+css_classes+'" '+target+' '+custom+'><i class="fa '+icon+'"></i> <span class="btn_text">' + text + '<span></a>';

  return button;
}



/**
 * Create a generic "Infomation" modal by function or a button.
 *
 * @note: It becomes a button if parameter text is supplied, otherwise the "i"-icon is used.
 *
 * Creating the modals inline got messy. This function isn't beautiful as well,
 * but for now the better way.
 *
 * @note: 2015-09-29: Argh, only Firefox support default values for paramters
 * (https://stackoverflow.com/questions/19699257/uncaught-syntaxerror-unexpected-token-in-google-chrome/19699282#19699282)
 *
 * @param id            \b STR  Some unique id for the modal (not used yet)
 * @param loc_code      \b STR  Used by $(document).ready bewlow; use some
 *                              speaking name (besides the location abbrevation
 *                              like LS1 etc., currently used: Multi, Magazin, Loaned, Unknown; ext_ill, ext_acqusition; holddirectdl)
 * @param link_title    \b STR  The title displayed on hovering the modal
 * @param modal_title   \b STR  The title displayed in the modal header
 * @param modal_body    \b STR  The modal "body"
 * @param iframe_src    \b STR  optional: if you add an url, it will be loaded in
 *                              an iframe below the modal_body part
 * @param modal_foot    \b STR  ERRRRM - not used really...
 * @param icon_class    \b STR  optional: the link to open a modal has always the
 *                              classes "fa fa-info-circle". Add a custom one
 * @param icon          \b STR  optional: Icon before button text
 * @param text          \b STR  optional: Button text
 *
 * @return \b STR modal html
 */
function create_modal(id, loc_code, link_title, modal_title, modal_body, iframe_src, modal_foot, icon_class, icon, text) {
  // Set function defaults if empty
  iframe_src = iframe_src || '';
  modal_foot = modal_foot || '';
  icon_class = icon_class || 'tub_fa-info_p';
  icon = icon || 'fa-info-circle';
  text = text || '';

  var href_class = '';
  var modal;
  var iframe = '';

  // If text is given, add our Button class. Otherwise it's just an info icon
  if (text != '') href_class = 'holdlink';


  if (iframe_src != '') {
    iframe = ' data-iframe="'+iframe_src+'" ';
  }

  modal = '<a href="#" id="info-'+id+'" title="' + link_title + '" class="locationInfox '+href_class+' modal-link hidden-print"><i class="fa '+icon+' '+icon_class+'"></i> ' + text + '<span data-title="' + modal_title + '" data-location="' + loc_code +'" '+iframe+' class="modal-dialog hidden">'+modal_body+modal_foot+'</span></a>';

  return modal;
}



/**
 * JQuery ready stuff
 *
 * - call displayHolding
 * - add trigger/listener for modal(s)
 *
 * @return void
 */
$(document).ready(function() {
  // Get all the buttons
  displayHoldingGuide();

  /* 2015-12-09: Wait until sfx buttons are loaded; makes sfx_fix in
  // displayHoldingGuide() safer
  // See comment in openurl.JS with same date
  // (note: this would maybe require a timeout - if resolving dies)
  $.when( checkFulltextButtons() ).done(function() {
    displayHoldingGuide();
    alert( "We got what we came for!" );
  });
  */


  /**
   * Listen to clicks on volume button in multivolume modal
   */
  $('.modal-content').on('click', '.get_volum_items', function() {
    multiVolPPN = $(this).attr('id');
    get_holding_tab(multiVolPPN, '.volume_'+multiVolPPN);
  });


  /**
   * Show modal on button click
   *
   * //https://stackoverflow.com/questions/1359018/in-jquery-how-to-attach-events-to-dynamic-html-elements
   */
  $('body').on('click', 'a.locationInfox', function(event) {
    event.preventDefault();

	// TMP: Test Postloading Holding/Volumes
	// Get full-status only on clicking link; add the result into span with class "data-postload_ajax" (part of modal-body)
	recPPN = $(this).attr('id').replace('info-', ''); // Strip the info that is set in createModal()
	//get_holding_tab(x);
	// END TMP: Test Postloading Holding

    var loc = $(this).children('span').attr('data-location');
    var additional_content = '';
    var modal_iframe_href;
    var modal_frame = '';
    var preload_animation = '';
    var force_logoff_loan4 = false;

    // @todo: Errm, if there's a lot text above, well then this matters ;)
    var frameMaxHeight = window.innerHeight - 250;
    if (frameMaxHeight > 550) frameMaxHeight = 550;
    modal_iframe_href = $(this).children('span').attr('data-iframe');

    // Create iframe if available
    if (modal_iframe_href !== undefined && modal_iframe_href.length > 0) {
      modal_frame = '<iframe id="modalIframe" name="modalIframe" src="' + modal_iframe_href + '" width="100%" min-height="465px" height="'+frameMaxHeight+'px"/>';
    }

    if (loc == 'Loaned') {
      additional_content = '';
      force_logoff_loan4 = false;
    }
    else if (loc == 'Magazin') {
      additional_content = '';
      force_logoff_loan4 = false;
    }
    else if (loc == 'Multi') {
      preload_animation = '<i class="tub_loading fa fa-circle-o-notch fa-spin"></i> Loading...';
      get_volume_tab(recPPN); //TEST - reicht für LS-Sachen, wenn überhaupt sinnvoll
    }
    else if (loc == 'SO' || loc == 'ACQ') {
      //
    }
    else if (loc === 'Undefined') {
      //
    }
    else if (loc == 'DIGfail') {
      // 
    }
    else if (loc == 'WEB') {
      //
    }
    else if (loc == 'TUBdok') {
      //
    }
    else {
      preload_animation = '<i class="tub_loading fa fa-circle-o-notch fa-spin"></i> Loading...';
      get_holding_tab(recPPN); //TEST - reicht für LS-Sachen, wenn überhaupt sinnvoll

      // Got shelf location
      var roomMap = [];
      roomMap['LS1'] = path + '/themes/bootstrap3-tub/images/tub/LS1_main.jpg';
      roomMap['LS2'] = path + '/themes/bootstrap3-tub/images/tub/LS2_main.jpg';
      roomMap['LBS'] = path + '/themes/bootstrap3-tub/images/tub/LS1_lbs.jpg';
      roomMap['SEM'] = path + '/themes/bootstrap3-tub/images/tub/LS2_sem.jpg';
      additional_content = (roomMap[loc]) ? '<img src="'+ roomMap[loc] +'" />' : '';
    }

    // TODO: Lightbox has methods to do this?
    $('#modalTitle').html($(this).children('span').attr('data-title'));
    $('.modal-body').html('<p>'+ $(this).children('span').text() + '</p><span class="data-modal_postload_ajax" id="'+recPPN+'">'+preload_animation+'</span>' + additional_content + modal_frame);


    // Remove iframe - prevents browser history
    function closeModalIframe() {
      $('#modalIframe').remove();
    }

    // NOTE: it's default to stay logged in unless the close link is clicked OR
    //  the session times out in loan4 (the forced log off would be new, albeit
    //  could be a hassle for patrons that want to request multiple items in sucession)
    function closeLoan4() {
      // TEST: Force loan4 logoff, delay it a little so the iframe can be reloaded with the logoff url
      $('#modalIframe').attr("src", 'https://katalog.b.tuhh.de/LBS_WEB/j_spring_security_logout');
      // Argh, with something like alert after the src change it works, timeout
      // etc. does not. Ok, fix this later, already solved this some time ago somewhere else...
      // Problem is most likely ajax timing like for fix_sfx
      // correct way would be like https://api.jquery.com/ajaxSuccess/ or https://stackoverflow.com/a/9865124
      alert('Logged off');
    }

    // Add generic function as close action if modal_iframe_href is used
    if (modal_iframe_href !== undefined) {
      Lightbox.addCloseAction(closeModalIframe);
    }

    // Add special function as close action if loan4 is opened
    if (force_logoff_loan4 === true) {
      Lightbox.addCloseAction(closeLoan4);
    }

    // Show everything
    $('#modal').modal('show');

  });

});



/**
 * Load full status (per copy information) into a modal on request
 *
 * @todo
 * - Make it simpler, no array needed ever
 * - Finally replace it by the tab view used in themes/bootstrap3-tub/templates/record/view.phtml
 *   (prepared in themes/bootstrap3-tub/templates/record/view-tabs.phtml)
 *   > Plus add buttons if called from get_volume_tab
 *
 * @param recID     The PPN
 * @param target    Tag with class or id the result shall be appended to
 *
 * @return Populates data-modal_postload_ajax (@see Jquery.document.ready above)
 */
function get_holding_tab(recID, target) {
    var target = target || '.data-modal_postload_ajax';
    var currentId;
    var record_number;
    var xhr;

    currentId = recID;
    $.ajax({
        dataType: 'json',
        url: path + '/AJAX/JSON?method=getItemStatusTUBFull',
        data: {"id[]":currentId, "record_number":currentId},
        beforeSend: function(xhr, settings) { xhr.rid = currentId; },
        success: function(response, status, xhr) {
            if(response.status == 'OK') {
            $.each(response.data, function(i, result) {

            //var item = $($('.ajaxItem')[xhr.rid]);
            if (typeof(result.full_status) != 'undefined' && result.full_status.length > 0) {
                // Full status mode is on -- display the HTML and hide extraneous junk:
                $(target).empty().append(result.full_status);
            }

            // Prepare location list
            // @note: getItemStatusTUBFullAjax always returns locationList; still
            // here as part of refactoring
            if (result.locationList) {
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
                    locationListHTML += (result.locationList[x].callnumbers) ?  result.locationList[x].callnumbers : '';
                    locationListHTML += '</div>';
                }
                // Show location list
                //$(target).append(locationListHTML);
            }
        });
      }
    }
  });
}



/**
 * Load volume list into a modal on request
 *
 * @todo
 * - This view and the tab view used in themes/bootstrap3-tub/templates/record/view.phtml
 *   (prepared in themes/bootstrap3-tub/templates/record/view-tabs.phtml) should 
 *   just use the same template (most likely best place: themes/bootstrap3-tub/templates/ajax)
 *   > hmm, just include themes/bootstrap3-tub/templates/record/hold.phtml somehow?
 * - (Multilanguage table header)
 *
 * @note:
 * - rip off of themes/bootstrap3-tub/js/multipart.js
 * - Related
 *   > module/VuFind/src/VuFind/Controller/AjaxController.php
 *   > module/VuFind/src/VuFind/MultipartList.php
 *   > module/VuFind/src/VuFind/RecordTab/TomesVolumes.php
 *   > module/VuFind/src/VuFind/RecordDriver/SolrGBV.php    > getMultipartChildren()?
 *   > themes/bootstrap3-tub/templates/RecordTab/tomesvolumes.phtml
 *
 * @param recID     The PPN (of a multivolume item)
 *
 * @return Populates data-modal_postload_ajax (@see Jquery.document.ready above)
 */
function get_volume_tab(recID) {
    ppnlink = recID;
    var volume_rows = [""];

    jQuery.ajax({
        //http://lincl1.b.tu-harburg.de:81/vufind2-test/AJAX/JSON?method=getMultipart&id=680310649&start=0&length=10000
        url:path+'/AJAX/JSON?method=getMultipart&id='+ppnlink+'&start=0&length=10000',
        dataType:'json',
        success:function(data, textStatus) {
            var volcount = data.data.length;
            var visibleCount = Math.min(10, volcount);

            if (visibleCount == 0) {
                return false;
            }
            for (var index = 0; index < visibleCount; index++) {
                var entry = data.data[index];
                var volume_ajax_row = '<tr><td class="volume_'+entry.id+'" colspan="4"></td></tr>';

                volume_rows.push('<tr><td><a href="'+path+'/Record/'+entry.id+'">'+entry.partNum+'</a></td><td><a href="'+path+'/Record/'+entry.id+'">'+entry.title+'</a></td><td><a href="'+path+'/Record/'+entry.id+'">'+entry.date+'</a></td><td class="holdlink get_volum_items" id="'+entry.id+'"><i class="fa fa-bars"> Exemplare</td></tr>'+volume_ajax_row);
            }
            if (volcount > visibleCount) {
                for (var index = visibleCount; index < data.data.length; index++) {
                    var entry = data.data[index];
                    volume_rows.push('<tr class="offscreen"><td><a href="'+path+'/Record/'+entry.id+'">'+entry.partNum+'</a></td><td><a href="'+path+'/Record/'+entry.id+'">'+entry.title+'</a></td><td><a href="'+path+'/Record/'+entry.id+'">'+entry.date+'</a></td><td class="btn get_volum_items" id="'+entry.id+'">Exemplare</td></tr>'+volume_ajax_row);
                }
            }

            // Append to modal and return
            var myreturn = '<table class="datagrid extended"><thead><tr><th>Band</th><th>Titel</th><th>Jahr</th><th>Exemplare</th></tr></thead><tbody>' + volume_rows.join('') + '</tbody></table>';
            $('.data-modal_postload_ajax').empty().append(myreturn);
            return true;
        }
    });

}
