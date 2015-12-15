<?php
/**
 * Ajax Controller Module
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2010.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  Controller
 * @author   Chris Hallberg <challber@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_a_controller Wiki
 */
namespace VuFind\Controller;
use VuFind\Exception\Auth as AuthException;
use VuFind\MultipartList;

// Beluga Controller
use \Zend\View\Helper\AbstractHelper;
use Zend\View\HelperPluginManager as ServiceManager;
use VuFind\Search\Factory\PrimoBackendFactory;
use VuFind\Search\Factory\SolrDefaultBackendFactory;
use VuFind\RecordDriver\SolrMarc;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\ServiceManager\ServiceManagerAwareInterface;
use Zend\ServiceManager\ServiceManagerInterface;
use VuFindSearch\Query\Query;

/**
 * This controller handles global AJAX functionality
 *
 * @category VuFind2
 * @package  Controller
 * @author   Chris Hallberg <challber@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_a_controller Wiki
 */
class AjaxController extends AbstractBase
{
    // define some status constants
    const STATUS_OK = 'OK';                  // good
    const STATUS_ERROR = 'ERROR';            // bad
    const STATUS_NEED_AUTH = 'NEED_AUTH';    // must login first

    /**
     * Type of output to use
     *
     * @var string
     */
    protected $outputMode;

    /**
     * Array of PHP errors captured during execution
     *
     * @var array
     */
    protected static $php_errors = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        // Add notices to a key in the output
        set_error_handler(['VuFind\Controller\AjaxController', "storeError"]);
    }

    /**
     * Handles passing data to the class
     *
     * @return mixed
     */
    public function jsonAction()
    {
        // Set the output mode to JSON:
        $this->outputMode = 'json';

        // Call the method specified by the 'method' parameter; append Ajax to
        // the end to avoid access to arbitrary inappropriate methods.
        $callback = [$this, $this->params()->fromQuery('method') . 'Ajax'];
        if (is_callable($callback)) {
            try {
                return call_user_func($callback);
            } catch (\Exception $e) {
                $debugMsg = ('development' == APPLICATION_ENV)
                    ? ': ' . $e->getMessage() : '';
                return $this->output(
                    $this->translate('An error has occurred') . $debugMsg,
                    self::STATUS_ERROR
                );
            }
        } else {
            return $this->output(
                $this->translate('Invalid Method'), self::STATUS_ERROR
            );
        }
    }

    /**
     * Load a recommendation module via AJAX.
     *
     * @return \Zend\Http\Response
     */
    public function recommendAction()
    {
        $this->writeSession();  // avoid session write timing bug
        // Process recommendations -- for now, we assume Solr-based search objects,
        // since deferred recommendations work best for modules that don't care about
        // the details of the search objects anyway:
        $rm = $this->getServiceLocator()->get('VuFind\RecommendPluginManager');
        $module = $rm->get($this->params()->fromQuery('mod'));
        $module->setConfig($this->params()->fromQuery('params'));
        $results = $this->getResultsManager()->get('Solr');
        $params = $results->getParams();
        $module->init($params, $this->getRequest()->getQuery());
        $module->process($results);

        // Set headers:
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-type', 'text/html');
        $headers->addHeaderLine('Cache-Control', 'no-cache, must-revalidate');
        $headers->addHeaderLine('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT');

        // Render recommendations:
        $recommend = $this->getViewRenderer()->plugin('recommend');
        $response->setContent($recommend($module));
        return $response;
    }

    /**
     * Get the contents of a lightbox; note that unlike most methods, this
     * one actually returns HTML rather than JSON.
     *
     * @return mixed
     */
    protected function getLightboxAjax()
    {
        // Turn layouts on for this action since we want to render the
        // page inside a lightbox:
        $this->layout()->setTemplate('layout/lightbox');

        // Call the requested action:
        return $this->forwardTo(
            $this->params()->fromQuery('submodule'),
            $this->params()->fromQuery('subaction')
        );
    }

    /**
     * Support method for getItemStatuses() -- filter suppressed locations from the
     * array of item information for a particular bib record.
     *
     * @param array $record Information on items linked to a single bib record
     *
     * @return array        Filtered version of $record
     */
    protected function filterSuppressedLocations($record)
    {
        static $hideHoldings = false;
        if ($hideHoldings === false) {
            $logic = $this->getServiceLocator()->get('VuFind\ILSHoldLogic');
            $hideHoldings = $logic->getSuppressedLocations();
        }

        $filtered = [];
        foreach ($record as $current) {
            if (!in_array($current['location'], $hideHoldings)) {
                $filtered[] = $current;
            }
        }
        return $filtered;
    }


    /**
     * Get Item Statuses
     *
     * This is responsible for printing the holdings information for a
     * collection of records in JSON format.
     *
     * @todo 2015-10-13
     * - When getItemStatusTUBFullAjax() is fine, remove the redundant parts here
     *   => "// Load callnumber and location settings: ..."
     *   => "if ($locationSetting == "group") { ..."
     *   => "if ($showFullStatus) {..."
     * - Changing these config options would break displayHoldingGuide() in
     *   check_item_statuses.js anyway (since a long time now)
     * 
     * @return \Zend\Http\Response
     * @author Chris Delis <cedelis@uillinois.edu>
     * @author Tuan Nguyen <tuan@yorku.ca>
     */
    protected function getItemStatusesAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $catalog = $this->getILS();
        $ids = $this->params()->fromQuery('id');
        $results = $catalog->getStatuses($ids);

        if (!is_array($results)) {
            // If getStatuses returned garbage, let's turn it into an empty array
            // to avoid triggering a notice in the foreach loop below.
            $results = [];
        }

        // In order to detect IDs missing from the status response, create an
        // array with a key for every requested ID.  We will clear keys as we
        // encounter IDs in the response -- anything left will be problems that
        // need special handling.
        $missingIds = array_flip($ids);

        // Get access to PHP template renderer for partials:
        $renderer = $this->getViewRenderer();

        // Load messages for response:
        $messages = [
            'available' => $renderer->render('ajax/status-available.phtml'),
            'unavailable' => $renderer->render('ajax/status-unavailable.phtml'),
            'unknown' => $renderer->render('ajax/status-unknown.phtml'),
            'notforloan' => $renderer->render('ajax/status-notforloan.phtml')
        ];

        // Load callnumber and location settings:
        $config = $this->getConfig();
        $callnumberSetting = isset($config->Item_Status->multiple_call_nos)
            ? $config->Item_Status->multiple_call_nos : 'msg';
        $locationSetting = isset($config->Item_Status->multiple_locations)
            ? $config->Item_Status->multiple_locations : 'msg';
        $showFullStatus = isset($config->Item_Status->show_full_status)
            ? $config->Item_Status->show_full_status : false;

        // Loop through all the status information that came back
        $statuses = [];
        foreach ($results as $recordNumber => $record) {
            // Filter out suppressed locations:
            $record = $this->filterSuppressedLocations($record);

            // Skip empty records:
            if (count($record)) {
                if ($locationSetting == "group") {
                    $current = $this->getItemStatusGroup(
                        $record, $messages, $callnumberSetting
                    );
                } else {
                    $current = $this->getItemStatus(
                        $record, $messages, $locationSetting, $callnumberSetting
                    );
                }
                // If a full status display has been requested, append the HTML:
                if ($showFullStatus) {
                    $current['full_status'] = $renderer->render(
                        'ajax/status-full.phtml', ['statusItems' => $record]
                    );
                }              
                $current['record_number'] = array_search($current['id'], $ids);
// TZ: Why is it statuses - only one exists. Everything merged as best guess? This has to go awry with multiple locations (?)
                $statuses[] = $current;

                // The current ID is not missing -- remove it from the missing list.
                unset($missingIds[$current['id']]);
            }
        }

// TZ TODO: getPrintedStatuses also calles in getItemStatus() - one could be removed?        
        $link_printed = $this->getPrintedStatuses();
        $linkPrintedHtml = null;
        $parentLinkHtml = null;
        if ($link_printed) {
            $view = ['refId' => $link_printed];
            $linkPrintedHtml = $this->getViewRenderer()->render('ajax/link_printed.phtml', $view);
            $parentLinkHtml = $this->getViewRenderer()->render('ajax/parentlink.phtml', $view);
        }

        $multiVol = $this->getMultiVolumes();

        // If any IDs were missing, send back appropriate dummy data
        /* add?
                'presenceOnly' => $referenceIndicator,
                'electronic' => $electronic, */
        foreach ($missingIds as $missingId => $recordNumber) {
            $statuses[] = [
                'id'                   => $missingId,
                'patronBestOption'     => 'false',
                'bestOptionHref'       => 'false',
                'bestOptionLocation'   => 'false',
                'availability'         => 'false',
                'availability_message' => $messages['unavailable'],
                'location'             => $this->translate('Unknown'),
                'locationList'         => false,
                'reserve'              => 'false',
                'reserve_message'      => $this->translate('Not On Reserve'),
                'callnumber'           => '',
                'missing_data'         => true,
                'link_printed'         => $linkPrintedHtml,
                'link_printed_href'    => $link_printed,
                'parentlink'           => $parentLinkHtml,
                'record_number'        => $recordNumber,
                'reference_location'   => 'false',
                'reference_callnumber' => 'false',
                'multiVols'            => $multiVol
            ];
        }

        // Done
        return $this->output($statuses, self::STATUS_OK);
$x = '<pre>' . var_export($results) . '</pre>';
//$x = '<pre>' . var_export($statuses) . '</pre>';
return $this->output($x, self::STATUS_OK);
    }


    /**
     * Support method for getItemStatuses() -- when presented with multiple values,
     * pick which one(s) to send back via AJAX.
     *
     * @param array  $list        Array of values to choose from.
     * @param string $mode        config.ini setting -- first, all or msg
     * @param string $msg         Message to display if $mode == "msg"
     * @param string $transPrefix Translator prefix to apply to values (false to
     * omit translation of values)
     *
     * @return string
     */
    protected function pickValue($list, $mode, $msg, $transPrefix = false)
    {
        // Make sure array contains only unique values:
        $list = array_unique($list);

        // If there is only one value in the list, or if we're in "first" mode,
        // send back the first list value:
        if ($mode == 'first' || count($list) == 1) {
            if (!$transPrefix) {
                return $list[0];
            } else {
                return $this->translate($transPrefix . $list[0], [], $list[0]);
            }
        } else if (count($list) == 0) {
            // Empty list?  Return a blank string:
            return '';
        } else if ($mode == 'all') {
            // Translate values if necessary:
            if ($transPrefix) {
                $transList = [];
                foreach ($list as $current) {
                    $transList[] = $this->translate(
                        $transPrefix . $current, [], $current
                    );
                }
                $list = $transList;
            }
            // All values mode?  Return comma-separated values:
            return implode(', ', $list);
        } else {
            // Message mode?  Return the specified message, translated to the
            // appropriate language.
            return $this->translate($msg);
        }
    }


    /**
     * Support method for getItemStatuses() -- process a single bibliographic record
     * for location settings other than "group".
     *
     * @note: 2015-09-13
     * -  The aim is: get only the location which serves the patron best
     *
     * @todo: 2015-09-19
     * -  CD-Roms might be categorized as e_only - this is nearly ok, since no
     *    location or call number is available. But we'd want to give the patron
     *    some clue - currently there is nothing. Example (search for)
     *    http://lincl1.b.tu-harburg.de:81/vufind2-test/Record/268707642
     *
     * @todo: 2015-10-09
     * -  Logic for $bestOptionLocation is really bad and got even worse by
     *    adding "TUBHH-Hack for bestLocation" (search for comment). Think about
     *    a better way to pry the information from the data.
     *    What multiple_locations and show_full_status do _might_ be a much
     *    better way for handling this.
     *
     * @param array  $record            Information on items linked to a single bib
     *                                  record
     * @param array  $messages          Custom status HTML
     *                                  (keys = available/unavailable)
     * @param string $locationSetting   The location mode setting used for
     *                                  pickValue()
     * @param string $callnumberSetting The callnumber mode setting used for
     *                                  pickValue()
     *
     * @return array                    Summarized availability information
     */
    protected function getItemStatus($record, $messages, $locationSetting, $callnumberSetting) {
        $tmp = ''; // for quick debugging output via json
        // Keep track of different kinds of (physical) access to the copies of the current title
        // Note: for completeness we could also track/calculate the total unavailable items, but _seems_ pointless
        $totalCount      = count($record); // All item (available and not available)
        $availableCount  = 0;   // Total items available (Reference only + Borrowable + Closed Stack Order); reservce collection is implicitly Ref only
        $referenceCount  = 0;   // > Subset thereof: available but reference only
        $lentCount       = 0;   // > Subset thereof: total of all available items being on loan (can be RESERVED aka "Recall this")
        $borrowableCount = 0;   // > Subset thereof: items immediatly available for take-away (including $stackorderCount)
        $stackorderCount = 0;   // (not subset; part of $availableCount calculation): total of all available items that have to be ORDERED from a closed stack (aka "Place a hold")
        $electronicCount = 0;
        $dienstappCount  = 0;

        // Summarize call number, location and availability info across all items:
        $available = false;                     // Track and set to true if at least one item is available
        $availability = false;                  // Human readable detail for the $available status
        $availability_message = false;          // Hmm... (note: available really is more like item status)
        $additional_availability_message = '';  // Hmm...
        $electronic = false;
        $referenceIndicator = '0';              // Some info about the ratio referenceOnly:borrowable
        $use_unknown_status = false;            // Hmm...
        $reservationLink = '';                  // If possible - either reserve or order

        // Some special tracking with arrays
        $callNumbers = array();
        $locations = array();
        $timestamp = array();
        $tr = array();

        // Determine the best option for the patron; order is important
        // Note: This should give a very reasonable range of possible options for
        // further processing. Maybe value can be added by using actual values
        // instead of boolean indicators
        // Note2: These options only refer to physical copies UNLESS there are ONLY electronic items
        $patronOptions['e_only']             = false;
        $patronOptions['shelf']              = false;
        $patronOptions['order']              = false;
        $patronOptions['reserve_or_local']   = false;
        $patronOptions['reserve']            = false;
        $patronOptions['local']              = false;
        $patronOptions['acquired']           = false; // Added 2015-10-14; title bought but not yet arrived
        $patronOptions['service_desk']       = false;
        $patronOptions['false']              = false;

        // These variables should provide everything needed to create a useful html output in the result
        $patronBestOption   = false;
        $bestOptionHref     = false;    // Href is really a little misleading - it's more like "action url". Either 'order' or 'reserve' which is specified via $patronBestOption
        $bestOptionLocation = false;    // Try to only show the best (not the first) location; Note: for TUBHH call numbers "should" always be correct without explicit tracking. Todo: Check with a title that is Reading room as well as special location (DA); example so far: DAC-372 & DAG-046 (everything is fine)
        $bestLocationPriority[0] = '';  // This nearly has the same logic as $patronOptions - hmm, maybe make $patronOptions multi dimensional?

        // Remember some special copies for the $patronOptions['reserve_or_local'] case
        $referenceCallnumber = false;
        $referenceLocation   = false;
        
        
        // Analyze each item of the record (title)
        foreach ($record as $info) {
            // Keep track of the due dates to finally return the one with the least waiting time
            $key = $info['id'];
            if (array_key_exists('duedate', $info)) {
                $tr[] = $info;
                $timestamp[$key]  = strtotime($info['duedate']);
            }

            // Find an available copy
            if ($info['availability']) {
                $available = true;
                $availableCount++;
                // Our best option = get it from the shelf. If it is a shelf
                // item we can only determine implicitly. So this only sticks
                // if this copy isn't to be ordered/reserved/reference only/e-only
                $bestLocationPriority[0] = $info['location'];

                // Check if this copy has a recallhref
                // TZ 2015-09-19: Damn, this also includes lent items that can be rerserved. 
                //      So $stackorderCount really would be $stackorderCount - $lentCount or 
                //      $info['ilslink'] but not $info['duedate']; let's try (even though it 
                //      should not have affected the logic as it is so far)
                if ($info['ilslink']) {
                    if (!$info['duedate']) $stackorderCount++;
                    $placeaholdhref = $info['ilslink'];
                    // Order - ok, location isn't really interesting anymore. Remember anyway (who knows?)
                    if ($bestLocationPriority[0] == $info['location']) $bestLocationPriority[0] = '';
                    $bestLocationPriority[1] = $info['location'];
                }

                // TZ: if ($info['status'] == 'only copy') would work obviously,
                // but is meant as a interlibrary loan information
                // @note Aquired items are marked as "presence_use_only" too
                if ($info['itemnotes'][0] == 'presence_use_only') {
                    $referenceCount++;
                    // Remember call number and location if $patronOptions['reserve_or_local']
                    // finally is our best option
                    $tmp_referenceCallnumber = $info['callnumber'];
                    $tmp_referenceLocation   = $info['location'];

                    if ($bestLocationPriority[3] == $info['location']) $bestLocationPriority[0] = '';
                    $bestLocationPriority[1] = $info['location'];
                }

                // Is it an electronic item? If we got not ilslink, it is not a physical item (?)
                // Hmm, no. Ahh!! Physical items always have a call number (how else to fetch them?)...
                // Just the 'Unknown' is a little vague - set somewhere above
                // Very special case (CD-ROM): http://lincl1.b.tu-harburg.de:81/vufind2-test/Record/268707642 / https://katalog.b.tuhh.de/DB=1/XMLPRS=N/PPN?PPN=268707642
                // and http://lincl1.b.tu-harburg.de:81/vufind2-test/Record/175989125 / https://katalog.b.tuhh.de/DB=1/XMLPRS=N/PPN?PPN=175989125 (siehe Baende ist hier die Aktion!)
                // ...really, really special...
//                if (stripos('opac-de-830', $info['item_id']) == -1) {
                if ($info['callnumber'] == 'Unknown') {
                    $electronicCount++;
                    $electronic = true;
                    // Online - ok, location isn't really interesting anymore. Remember anyway (who knows?)
                    if ($bestLocationPriority[0] == $info['location']) $bestLocationPriority[0] = '';
                    $bestLocationPriority[4] = $info['location'];
                }
                // TUBHH-Hack for bestLocation; @see method description
                // Ok, TUBHH reading room call numbers are always 7 characters long.
                // And below we make $patronOptions['shelf'] the most preferable
                // result (electronic is an exception). So force any shelf call
                // number on top here.
                // ...
                // HMM, else it must be a "good" call number as well as a good
                // location, but keep the TUB spefic way for now
                // else {
                // 2015-10-27: New DAIA way - location is now part of the callnumber
                // string, thus a valid reading room no is now 11 (e.g. LBS:MSB-100)
                // 2015-12-03: New DAIA way - It's again 7 (e.g. MSB-100)
                elseif (strlen($info['callnumber']) == 7 && $info['callnumber'] != 'Unknown') {                    // Ok, TUBHH reading room call numbers are always 7 characters long. And below we make $patronOptions['shelf']
                    // the most preferable result (electronic is an exception). So force any shelf call number on top here.
                    if ($bestLocationPriority[0] == $info['location']) $bestLocationPriority[0] = '';
                    $bestLocationPriority[-1] = $info['location'];
                }
            }
            // Not available cases
            else {
                // 2015-11-10: Dienstapparate are the only special case
                if (strlen($info['callnumber']) ==7 && substr($info['callnumber'], 0, 1) == 'D') {
                    $dienstappCount++;
                    if ($bestLocationPriority[0] == $info['location']) $bestLocationPriority[0] = '';
                    $bestLocationPriority[4] = $info['location'];                   
                }                
            }

            // @todo  Can it exist without being set? Otherwise it's redundant
            // with the if at the foreach start
            if ($info['duedate']) {
                $lentCount++;
                // Reserve - ok, location isn't really interesting anymore. Remember anyway (who knows?)
                if ($bestLocationPriority[0] == $info['location']) $bestLocationPriority[0] = '';
                $bestLocationPriority[2] = $info['location'];
            }
            
            // TODO: Find cases, see what happens
            if      ($info['status'] === 'missing') {$availability = 'missing';}
            elseif  ($info['status'] === 'lost')    {$availability = 'lost';}

            // Check for a use_unknown_message flag
            if (isset($info['use_unknown_message']) && $info['use_unknown_message'] == true) {
                $use_unknown_status = true;
            }

            // Store call number/location info:
            $callNumbers[] = $info['callnumber'];
            $locations[] = $info['location'];
        }

// TZ: Problem/idea: pickValue() should use a priority list; what does ILSHoldLogic do?
// - For location it would be really the best way; @tubhh only really has 3 (!) 
// - Multiple (different) call numbers - "should" not happen @tubhh? If it does - usually only reading room matters
// tubhh setting: multiple_call_nos = first
// @todo 2015-10-10: Hmm, first only works because TUBHH call numbers are "nice"
// (e.g. no numerus currens appended)
        // Determine call number string based on findings:
        $callNumber = $this->pickValue($callNumbers, $callnumberSetting, 'Multiple Call Numbers');

        // Determine location string based on findings:
        // tubhh setting: multiple_locations = msg
        // @todo 2015-10-10: Errrm, how does this work!?! Where is 'location_'
        // Anyway, it just picks the first one and that might be wrong
        //    Example: http://lincl1.b.tu-harburg.de:81/vufind2-test/Search/Results?lookfor=496536605&type=AllFields&limit=20&sort=relevance
        // Sticking to $bestLocationPriority - this here is not needed therefore
        // (Any other setting but single result is of no use in this method anyway)
//        $location = $this->pickValue($locations, $locationSetting, 'Multiple Locations', 'location_');

        // TUBHH Extension fields
        // Sort the records with their duedate timestamp ascending
        $recallhref = '';
        $duedate = '';
        array_multisort($timestamp, SORT_ASC, $tr);
        foreach ($tr as $rec) {
            if ($rec['ilslink'] && $recallhref === '') {
                $recallhref = $rec['ilslink'];
                if ($rec['duedate']) {
                    $duedate = $rec['duedate'];
                }
            }
        }

        // Check if all available items can be used reference only. Note: Maybe
        // use more speaking values instead of numbers (like 'Only', 'Some',
        // 'OnlyIntimeChoice' - errm...)
        // $borrowableCount means "it can be fetched immediatly by a patron (a
        // closed stack item we count as "very soon" = immediatly - so we don't
        // substract $stackorderCount as well)
        // Note: $info['itemnotes'][0] == 'presence_use_only' includes elecronic items
        $borrowableCount = $totalCount - ($referenceCount + $lentCount + $dienstappCount);
        if ($referenceCount > 0 && $referenceCount != $electronicCount) {
            // Case a) Yes, ALL items are reference only
            if ($referenceCount === $availableCount && $availableCount == $totalCount) {
                $referenceIndicator = '1';
                $patronOptions['local'] = true;
            }
            // Case b) No, not all items are reference only and just SOME of the available items are loaned
            elseif ($referenceCount !== $availableCount && $borrowableCount !== 0 && $lentCount > 0) {
                $referenceIndicator = '2';
            }
            // Case c) No, not all items are reference only but ALL available
            // items are borrowable (btw. that available != borrowable makes it
            // really hard :))
            // Note: For now I keep '2', because currently I don't see a point
            // for giving different messages for b) and c)
            elseif ($referenceCount !== $availableCount && $borrowableCount > 0 && $lentCount === 0) {
                $referenceIndicator = '2';
            }
            // Case d) No, not all items are reference BUT ALL borrowable items are loaned
            // Important case. This way you can guide patrons to copies when all items are loaned
//            elseif ($referenceCount !== $availableCount && $borrowableCount === 0 && $lentCount > 0) {
else              {
                $referenceIndicator = '3';
                $patronOptions['reserve_or_local'] = true; // If this option sticks (no better if finally available) the option to use a reference only item is an useful ADDITIONAL info for the patron
                // Supply the location for ref only too
                $referenceCallnumber = $tmp_referenceCallnumber;
                $referenceLocation   = $tmp_referenceLocation;
                $reservationLink = ' <a class="holdlink" href="'.htmlspecialchars($placeaholdhref).'" target="_blank">'.$this->translate('Place a Hold').' (ref only avail)</a>';
                $bestOptionHref  = $placeaholdhref;
            }
        }

        // Ok determine remaining best options + set link
        // No reference only, but borrowable shelf items available
        // (also) Note: $info['itemnotes'][0] == 'presence_use_only' includes elecronic items
        if ($electronicCount > 0 && $electronicCount === $totalCount) {
            $patronOptions['e_only'] = true;
        }
        elseif ($borrowableCount > 0 && ($borrowableCount - $stackorderCount) > 0) {
            $patronOptions['shelf'] = true;
        }
        // Ok, everything is in the closed stack?
        elseif ($borrowableCount > 0 && $borrowableCount == $stackorderCount) {
            $patronOptions['order'] = true;
            $reservationLink = ' <a class="holdlink" href="'.htmlspecialchars($placeaholdhref).'" target="_blank">'.$this->translate('Place a Hold').'</a>';
            $bestOptionHref  = $placeaholdhref;
        }
        // Hmm, can we reserve something?
        elseif ($lentCount > 0) {
            $patronOptions['reserve'] = true;
            $reservationLink = ' <a class="reservationlink" href="'.htmlspecialchars($recallhref).'" target="_blank">'.$this->translate('Recall this').'</a>';
            $bestOptionHref  = $recallhref;
        }
        // Ok, so we should for sure have a false status for availability
        elseif ($available == false) {
            $patronOptions['service_desk'] = true;
        }
        else {
            $patronOptions['false'] = true;
        }
        
        // Finally, ignore all but the perfect option (=first option that is true)
        foreach ($patronOptions AS $option => $isSet) {
            if ($isSet) {
                $patronBestOption = $option;
                break;
            }
        }

        // Also get the best location
        // @note 2015-10-10: IF there were translations check:item_status.js would break currently
        ksort($bestLocationPriority);
        foreach ($bestLocationPriority AS $priority => $location) {
           if ($location) {
                $bestOptionLocation = $location;
                //$bestOptionLocation = $this->pickValue(array($location), $locationSetting, 'Multiple Locations', 'location_');
                break;
            }
        }

        // Collect details about links to show in result list
        // TZ: Gosh, this is really hard to read
        $availability_message = $use_unknown_status
            ? $messages['unknown']
            : $messages[$available ? 'available' : 'unavailable'];

        if ($available) {
            // TZ: It's unimportant what is set, as long as something is set (see json return)
            $availability = 'available';
        }
        else if ($duedate === '') {
            $availability_message = $messages['notforloan'];
        }
        else {
            // (TZ: Here availability is "missing" or "lost" as seen above?)
            $additional_availability_message = $availability;
        }
        
        // Add locationhref for Marc21 link (one of them)
        $locHref = $rec['locationhref'];
        
        // @todo  Check if it is necessary here - already/also called in
        // getItemStatusesAjax() ?!? /TZ
        $link_printed = $this->getPrintedStatuses();
        $linkPrintedHtml = null;
        $parentLinkHtml = null;
        if ($link_printed) {
            $view = ['refId' => $link_printed];
            $linkPrintedHtml = $this->getViewRenderer()->render('ajax/link_printed.phtml', $view);
            $parentLinkHtml = $this->getViewRenderer()->render('ajax/parentlink.phtml', $view);
        }

        // if we got no location, the best guess is, that it is a special location
        // ("Sonderstandort") that DAIA doesn't get correctly.
        // TUB-Note: Possibilities are: DA or SEM (or are there more?). If so,
        // at the current state of vufind we determine it this way
        // 2015-10-14: Another special case is items being acquired. Daia returns
        // something like 
        // array ('status' => '', 'availability' => true, 'duedate' => NULL, 
        //  'requests_placed' => '', 'id' => '662452461', 
        //  'item_id' => 'http://uri.gbv.de/document/opac-de-830:epn:1252720203',
        //  'ilslink' => NULL, 'number' => 1, 'barcode' => '1', 'reserve' => 'N',
        //  'callnumber' => 'bestellt Ref. 5', 'location' => 'Unknown',
        //  'locationhref' => '',   'itemnotes' => ... ... 'presence_use_only' => true,)
        // The lbs4 opac has better information, but with the logic in this 
        // method the DAIA information qualify as 'Sonderstandort: Semesterapparat'.
        // So we make an override via the first if here to get this case covered.
        // && $bestOptionLocation === 'Unknown'
        if (strpos(strtolower($callNumber), 'bestellt') !== false && $patronBestOption !== 'e_only') {
            $patronBestOption   = 'acquired';
            $bestOptionLocation = 'Shipping';
        }
        else if (!$bestOptionLocation && $patronBestOption != 'e_only') {
            $bestOptionLocation = 'Sonderstandort: Dienstapparat';
        }
        else if ($bestOptionLocation === 'Unknown' && $patronBestOption !== 'e_only') {
            $bestOptionLocation = 'Sonderstandort: Semesterapparat';
        }

        $multiVol = $this->getMultiVolumes();

        // Quick check if all calculation are valid
        $tmp .= "totalCount: $totalCount
                availableCount: $availableCount 
                borrowableCount: $borrowableCount
                referenceCount: $referenceCount
                lentCount: $lentCount
                stackorderCount: $stackorderCount
                electronicCount: $electronicCount
                dienstappCount: $dienstappCount";
        // */

        // Send back the collected details:
//TZ: Todo: take advantage of patronBestOption in check_item_statuses.js
// Note: reference_location and reference_callnumber are false unless $patronOptions['reserve_or_local'] is true
// TODO: These can be removed, since the best options imply their information: 
// - 'availability', 'location', 'reserve', 'reserve_message', 'reservationUrl'      
// TODO: For these I don't know what they ever where good for
// - 'locationList', 'availability_message' (might be important)       
// TODO: Finally chose better naming (instead of "best")
        return [
            'id' => $record[0]['id'],
            'patronBestOption' => $patronBestOption,
            'bestOptionHref' => $bestOptionHref,
            'bestOptionLocation' => $bestOptionLocation,
            'availability' => ($available ? 'true' : 'false'),
            'availability_message' => $availability_message,
            'additional_availability_message' => $additional_availability_message,
            'locHref' => $locHref,
            'callnumber' => htmlentities($callNumber, ENT_COMPAT, 'UTF-8'),
            'duedate' => $duedate,
            'presenceOnly' => $referenceIndicator,
            'electronic' => $electronic,
            'link_printed' => $linkPrintedHtml,
            'link_printed_href'    => $link_printed,
            'reference_location' => $referenceLocation,
            'reference_callnumber' => $referenceCallnumber,
            'multiVols' => $multiVol,
            'tmp' => $tmp //implode('  --  ', $bestLocationPriority)
        ];

/* Original
        return [
            'id' => $record[0]['id'],
            'patronBestOption' => $patronBestOption,
            'bestOptionHref' => $bestOptionHref,
            'bestOptionLocation' => $bestOptionLocation,
            'availability' => ($available ? 'true' : 'false'),
            'availability_message' => $availability_message,
            'additional_availability_message' => $additional_availability_message,
            'location' => $location,
            'locationList' => false,
            'reserve' => ($record[0]['reserve'] == 'Y' ? 'true' : 'false'),
            'reserve_message' => $record[0]['reserve'] == 'Y'
                ? $this->translate('on_reserve')
                : $this->translate('Not On Reserve'),
            'callnumber' => htmlentities($callNumber, ENT_COMPAT, 'UTF-8'),
            'reservationUrl' => $reservationLink,
            'duedate' => $duedate,
            'presenceOnly' => $referenceIndicator,
            'electronic' => $electronic,
            'link_printed' => $link_printed,
            'reference_location' => $referenceLocation,
            'reference_callnumber' => $referenceCallnumber,
            'tmp_test' => $tmp,
            'multiVols' => $multiVol
        ];   
*/             
    }

    /**
     * Support method for getItemStatuses() -- process a single bibliographic record
     * for "group" location setting.
     *
     * @param array  $record            Information on items linked to a single
     *                                  bib record
     * @param array  $messages          Custom status HTML
     *                                  (keys = available/unavailable)
     * @param string $callnumberSetting The callnumber mode setting used for
     *                                  pickValue()
     *
     * @return array                    Summarized availability information
     */
    protected function getItemStatusGroup($record, $messages, $callnumberSetting)
    {
        // Summarize call number, location and availability info across all items:
        $locations =  [];
        $use_unknown_status = $available = false;
        foreach ($record as $info) {
            // Find an available copy
            if ($info['availability']) {
                $available = $locations[$info['location']]['available'] = true;
            }
            // Check for a use_unknown_message flag
            if (isset($info['use_unknown_message'])
                && $info['use_unknown_message'] == true
            ) {
                $use_unknown_status = true;
            }
            // Store call number/location info:
            $locations[$info['location']]['callnumbers'][] = $info['callnumber'];
        }

        // Build list split out by location:
        $locationList = false;
        foreach ($locations as $location => $details) {
            $locationCallnumbers = array_unique($details['callnumbers']);
            // Determine call number string based on findings:
            $locationCallnumbers = $this->pickValue(
                $locationCallnumbers, $callnumberSetting, 'Multiple Call Numbers'
            );
            $locationInfo = [
                'availability' =>
                    isset($details['available']) ? $details['available'] : false,
                'location' => htmlentities(
                    $this->translate('location_' . $location, [], $location),
                    ENT_COMPAT, 'UTF-8'
                ),
                'callnumbers' =>
                    htmlentities($locationCallnumbers, ENT_COMPAT, 'UTF-8')
            ];
            $locationList[] = $locationInfo;
        }

        $availability_message = $use_unknown_status
            ? $messages['unknown']
            : $messages[$available ? 'available' : 'unavailable'];

        // Send back the collected details:
        return [
            'id' => $record[0]['id'],
            'availability' => ($available ? 'true' : 'false'),
            'availability_message' => $availability_message,
            'location' => false,
            'locationList' => $locationList,
            'reserve' =>
                ($record[0]['reserve'] == 'Y' ? 'true' : 'false'),
            'reserve_message' => $record[0]['reserve'] == 'Y'
                ? $this->translate('on_reserve')
                : $this->translate('Not On Reserve'),
            'callnumber' => false
        ];
    }

    /**
     * Get additional item information
     *
     * This is responsible for printing any additional information for a
     * collection of records in JSON format.
     *
     * @return \Zend\Http\Response
     * @author Oliver Goldschmidt <o.goldschmidt@tuhh.de>
     */
    protected function getAdditionalItemInformationAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $catalog = $this->getILS();
        $ids = $this->params()->fromQuery('id');
        $results = $catalog->getStatuses($ids);

        if (!is_array($results)) {
            // If getStatuses returned garbage, let's turn it into an empty array
            // to avoid triggering a notice in the foreach loop below.
            $results = [];
        }

        // In order to detect IDs missing from the status response, create an
        // array with a key for every requested ID.  We will clear keys as we
        // encounter IDs in the response -- anything left will be problems that
        // need special handling.
        $missingIds = array_flip($ids);

        // Get access to PHP template renderer for partials:
        $renderer = $this->getViewRenderer();

        // Load messages for response:
        $messages = [
            'available' => $renderer->render('ajax/status-available.phtml'),
            'unavailable' => $renderer->render('ajax/status-unavailable.phtml'),
            'unknown' => $renderer->render('ajax/status-unknown.phtml'),
            'notforloan' => $renderer->render('ajax/status-notforloan.phtml')
        ];

        // Load callnumber and location settings:
        $config = $this->getConfig();
        $callnumberSetting = isset($config->Item_Status->multiple_call_nos)
            ? $config->Item_Status->multiple_call_nos : 'msg';
        $locationSetting = isset($config->Item_Status->multiple_locations)
            ? $config->Item_Status->multiple_locations : 'msg';
        $showFullStatus = isset($config->Item_Status->show_full_status)
            ? $config->Item_Status->show_full_status : false;

        // Loop through all the status information that came back
        $statuses = [];
        foreach ($results as $recordNumber => $record) {
            // Filter out suppressed locations:
            $record = $this->filterSuppressedLocations($record);

            // Skip empty records:
            if (count($record)) {
                if ($locationSetting == "group") {
                    $current = $this->getItemStatusGroup(
                        $record, $messages, $callnumberSetting
                    );
                } else {
                    $current = $this->getItemStatus(
                        $record, $messages, $locationSetting, $callnumberSetting
                    );
                }
                // If a full status display has been requested, append the HTML:
                if ($showFullStatus) {
                    $current['full_status'] = $renderer->render(
                        'ajax/status-full.phtml', ['statusItems' => $record]
                    );
                }              
                $current['record_number'] = array_search($current['id'], $ids);
// TZ: Why is it statuses - only one exists. Everything merged as best guess? This has to go awry with multiple locations (?)
                $statuses[] = $current;

                // The current ID is not missing -- remove it from the missing list.
                unset($missingIds[$current['id']]);
            }
        }

// TZ TODO: getPrintedStatuses also calles in getItemStatus() - one could be removed?        
        $link_printed = $this->getPrintedStatuses();
        $linkPrintedHtml = null;
        $parentLinkHtml = null;
        if ($link_printed) {
            $view = ['refId' => $link_printed];
            $linkPrintedHtml = $this->getViewRenderer()->render('ajax/link_printed.phtml', $view);
            $parentLinkHtml = $this->getViewRenderer()->render('ajax/parentlink.phtml', $view);
        }

        // If any IDs were missing, send back appropriate dummy data
        /* add?
                'presenceOnly' => $referenceIndicator,
                'electronic' => $electronic, */
        foreach ($missingIds as $missingId => $recordNumber) {
            $statuses[] = [
                'id'                   => $missingId,
                'patronBestOption'     => 'false',
                'bestOptionHref'       => 'false',
                'bestOptionLocation'   => 'false',
                'availability'         => 'false',
                'availability_message' => $messages['unavailable'],
                'location'             => $this->translate('Unknown'),
                'locationList'         => false,
                'reserve'              => 'false',
                'reserve_message'      => $this->translate('Not On Reserve'),
                'callnumber'           => '',
                'missing_data'         => true,
                'link_printed'         => $linkPrintedHtml,
                'parentlink'           => $parentLinkHtml,
                'record_number'        => $recordNumber,
                'reference_location'   => 'false',
                'reference_callnumber' => 'false'                
            ];
        }

        // Done
        return $this->output($statuses, self::STATUS_OK);
$x = '<pre>' . var_export($results) . '</pre>';
//$x = '<pre>' . var_export($statuses) . '</pre>';
return $this->output($x, self::STATUS_OK);
    }




    /**
     * Get FULL Item Status (single item)
     *
     * This is responsible for printing the holdings information for a
     * collection of records in JSON format.
     *
     * @todo 2015-10-13
     * - chose a better method name
     * @todo 2015-12-11
     * - replace with rendering recordTabs/holdingsils.phtml
     *
     * @return \Zend\Http\Response
     * @author Chris Delis <cedelis@uillinois.edu>
     * @author Tuan Nguyen <tuan@yorku.ca>
     */
    protected function getItemStatusTUBFullAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $catalog = $this->getILS();
        $ids = $this->params()->fromQuery('id');
        $results = $catalog->getStatuses($ids);

        // Get access to PHP template renderer for partials:
        $renderer = $this->getViewRenderer();

// START This should be enough IF I knew what var is missing for DAIA and where to get it
    //$current['full_status'] = $renderer->render('recordTabs/holdingsils.phtml', ['statusItems' => $ids]);
    //return $current;
// END

        // Load messages for response:
        $messages = [
            'available' => $renderer->render('ajax/status-available.phtml'),
            'unavailable' => $renderer->render('ajax/status-unavailable.phtml'),
            'unknown' => $renderer->render('ajax/status-unknown.phtml'),
            'notforloan' => $renderer->render('ajax/status-notforloan.phtml')
        ];

        // Load callnumber and location settings:
        // Overrides for config settings (as used in getItemStatusAjax())
        $showFullStatus = true;
        $locationSetting = 'group';

        // Loop through all the status information that came back
        $statuses = [];
        foreach ($results as $recordNumber => $record) {
            // Filter out suppressed locations:
            $record = $this->filterSuppressedLocations($record);

            // Skip empty records:
            if (count($record)) {
                if ($locationSetting == "group") {
                    $current = $this->getItemStatusGroup(
                        $record, $messages, $callnumberSetting
                    );
                };

                // If a full status display has been requested, append the HTML:
                if ($showFullStatus) {
                    $current['full_status'] = $renderer->render(
                        'ajax/status-full.phtml', ['statusItems' => $record]
//                    $current['full_status'] = $renderer->render(
//                        'record/view-tabs.phtml', ['statusItems' => $record]
                    );
                }
                $current['record_number'] = array_search($current['id'], $ids);
                $statuses[] = $current;
            }
        }

        // Done
        return $this->output($statuses, self::STATUS_OK);
    }


    /**
     * Check one or more records to see if they are saved in one of the user's list.
     *
     * @return \Zend\Http\Response
     */
    protected function getSaveStatusesAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        // check if user is logged in
        $user = $this->getUser();
        if (!$user) {
            return $this->output(
                $this->translate('You must be logged in first'),
                self::STATUS_NEED_AUTH
            );
        }

        // loop through each ID check if it is saved to any of the user's lists
        $result = [];
        $ids = $this->params()->fromQuery('id', []);
        $sources = $this->params()->fromQuery('source', []);
        if (!is_array($ids) || !is_array($sources)) {
            return $this->output(
                $this->translate('Argument must be array.'),
                self::STATUS_ERROR
            );
        }
        foreach ($ids as $i => $id) {
            $source = isset($sources[$i]) ? $sources[$i] : DEFAULT_SEARCH_BACKEND;
            $data = $user->getSavedData($id, null, $source);
            if ($data) {
                // if this item was saved, add it to the list of saved items.
                foreach ($data as $list) {
                    $result[] = [
                        'record_id' => $id,
                        'record_source' => $source,
                        'resource_id' => $list->id,
                        'list_id' => $list->list_id,
                        'list_title' => $list->list_title,
                        'record_number' => $i
                    ];
                }
            }
        }
        return $this->output($result, self::STATUS_OK);
    }

    /**
     * Send output data and exit.
     *
     * @param mixed  $data     The response data
     * @param string $status   Status of the request
     * @param int    $httpCode A custom HTTP Status Code
     *
     * @return \Zend\Http\Response
     * @throws \Exception
     */
    protected function output($data, $status, $httpCode = null)
    {
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Cache-Control', 'no-cache, must-revalidate');
        $headers->addHeaderLine('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT');
        if ($httpCode !== null) {
            $response->setStatusCode($httpCode);
        }
        if ($this->outputMode == 'json') {
            $headers->addHeaderLine('Content-type', 'application/javascript');
            $output = ['data' => $data, 'status' => $status];
            if ('development' == APPLICATION_ENV && count(self::$php_errors) > 0) {
                $output['php_errors'] = self::$php_errors;
            }
            $response->setContent(json_encode($output));
            return $response;
        } else if ($this->outputMode == 'plaintext') {
            $headers->addHeaderLine('Content-type', 'text/plain');
            $response->setContent($data ? $status . " $data" : $status);
            return $response;
        } else {
            throw new \Exception('Unsupported output mode: ' . $this->outputMode);
        }
    }

    /**
     * Store the errors for later, to be added to the output
     *
     * @param string $errno   Error code number
     * @param string $errstr  Error message
     * @param string $errfile File where error occurred
     * @param string $errline Line number of error
     *
     * @return bool           Always true to cancel default error handling
     */
    public static function storeError($errno, $errstr, $errfile, $errline)
    {
        self::$php_errors[] = "ERROR [$errno] - " . $errstr . "<br />\n"
            . " Occurred in " . $errfile . " on line " . $errline . ".";
        return true;
    }

    /**
     * Generate the "salt" used in the salt'ed login request.
     *
     * @return string
     */
    protected function generateSalt()
    {
        return str_replace(
            '.', '', $this->getRequest()->getServer()->get('REMOTE_ADDR')
        );
    }

    /**
     * Send the "salt" to be used in the salt'ed login request.
     *
     * @return \Zend\Http\Response
     */
    protected function getSaltAjax()
    {
        return $this->output($this->generateSalt(), self::STATUS_OK);
    }

    /**
     * Login with post'ed username and encrypted password.
     *
     * @return \Zend\Http\Response
     */
    protected function loginAjax()
    {
        // Fetch Salt
        $salt = $this->generateSalt();

        // HexDecode Password
        $password = pack('H*', $this->params()->fromPost('password'));

        // Decrypt Password
        $password = base64_decode(\VuFind\Crypt\RC4::encrypt($salt, $password));

        // Update the request with the decrypted password:
        $this->getRequest()->getPost()->set('password', $password);

        // Authenticate the user:
        try {
            $this->getAuthManager()->login($this->getRequest());
        } catch (AuthException $e) {
            return $this->output(
                $this->translate($e->getMessage()),
                self::STATUS_ERROR
            );
        }

        return $this->output(true, self::STATUS_OK);
    }

    /**
     * Tag a record.
     *
     * @return \Zend\Http\Response
     */
    protected function tagRecordAjax()
    {
        $user = $this->getUser();
        if ($user === false) {
            return $this->output(
                $this->translate('You must be logged in first'),
                self::STATUS_NEED_AUTH
            );
        }
        // empty tag
        try {
            $driver = $this->getRecordLoader()->load(
                $this->params()->fromPost('id'),
                $this->params()->fromPost('source', DEFAULT_SEARCH_BACKEND)
            );
            $tag = $this->params()->fromPost('tag', '');
            $tagParser = $this->getServiceLocator()->get('VuFind\Tags');
            if (strlen($tag) > 0) { // don't add empty tags
                if ('false' === $this->params()->fromPost('remove', 'false')) {
                    $driver->addTags($user, $tagParser->parse($tag));
                } else {
                    $driver->deleteTags($user, $tagParser->parse($tag));
                }
            }
        } catch (\Exception $e) {
            return $this->output(
                ('development' == APPLICATION_ENV) ? $e->getMessage() : 'Failed',
                self::STATUS_ERROR
            );
        }

        return $this->output($this->translate('Done'), self::STATUS_OK);
    }

    /**
     * Get all tags for a record.
     *
     * @return \Zend\Http\Response
     */
    protected function getRecordTagsAjax()
    {
        $user = $this->getUser();
        $is_me_id = null === $user ? null : $user->id;
        // Retrieve from database:
        $tagTable = $this->getTable('Tags');
        $tags = $tagTable->getForResource(
            $this->params()->fromQuery('id'),
            $this->params()->fromQuery('source', DEFAULT_SEARCH_BACKEND),
            0, null, null, 'count', $is_me_id
        );

        // Build data structure for return:
        $tagList = [];
        foreach ($tags as $tag) {
            $tagList[] = [
                'tag'   => $tag->tag,
                'cnt'   => $tag->cnt,
                'is_me' => $tag->is_me == 1 ? true : false
            ];
        }

        // Set layout to render the page inside a lightbox:
        $this->layout()->setTemplate('layout/lightbox');
        $view = $this->createViewModel(
            [
                'tagList' => $tagList,
                'loggedin' => null !== $user
            ]
        );
        $view->setTemplate('record/taglist');
        return $view;
    }

    /**
     * Get map data on search results and output in JSON
     *
     * @param array $fields Solr fields to retrieve data from
     *
     * @author Chris Hallberg <crhallberg@gmail.com>
     * @author Lutz Biedinger <lutz.biedinger@gmail.com>
     *
     * @return \Zend\Http\Response
     */
    protected function getMapDataAjax($fields = ['long_lat'])
    {
        $this->writeSession();  // avoid session write timing bug
        $results = $this->getResultsManager()->get('Solr');
        $params = $results->getParams();
        $params->initFromRequest($this->getRequest()->getQuery());

        $facets = $results->getFullFieldFacets($fields, false);

        $markers = [];
        $i = 0;
        $list = isset($facets['long_lat']['data']['list'])
            ? $facets['long_lat']['data']['list'] : [];
        foreach ($list as $location) {
            $longLat = explode(',', $location['value']);
            $markers[$i] = [
                'title' => (string)$location['count'], //needs to be a string
                'location_facet' =>
                    $location['value'], //needed to load in the location
                'lon' => $longLat[0],
                'lat' => $longLat[1]
            ];
            $i++;
        }
        return $this->output($markers, self::STATUS_OK);
    }

    /**
     * Get entry information on entries tied to a specific map location
     *
     * @author Chris Hallberg <crhallberg@gmail.com>
     * @author Lutz Biedinger <lutz.biedinger@gmail.com>
     *
     * @return mixed
     */
    public function resultgooglemapinfoAction()
    {
        $this->writeSession();  // avoid session write timing bug
        // Set layout to render the page inside a lightbox:
        $this->layout()->setTemplate('layout/lightbox');

        $results = $this->getResultsManager()->get('Solr');
        $params = $results->getParams();
        $params->initFromRequest($this->getRequest()->getQuery());

        return $this->createViewModel(
            [
                'results' => $results,
                'recordSet' => $results->getResults(),
                'recordCount' => $results->getResultTotal(),
                'completeListUrl' => $results->getUrlQuery()->getParams()
            ]
        );
    }

    /**
     * AJAX for timeline feature (PubDateVisAjax)
     *
     * @param array $fields Solr fields to retrieve data from
     *
     * @author Chris Hallberg <crhallberg@gmail.com>
     * @author Till Kinstler <kinstler@gbv.de>
     *
     * @return \Zend\Http\Response
     */
    protected function getVisDataAjax($fields = ['publishDate'])
    {
        $this->writeSession();  // avoid session write timing bug
        $results = $this->getResultsManager()->get('Solr');
        $params = $results->getParams();
        $params->initFromRequest($this->getRequest()->getQuery());
        foreach ($this->params()->fromQuery('hf', []) as $hf) {
            $params->getOptions()->addHiddenFilter($hf);
        }
        $params->getOptions()->disableHighlighting();
        $params->getOptions()->spellcheckEnabled(false);
        $filters = $params->getFilters();
        $dateFacets = $this->params()->fromQuery('facetFields');
        $dateFacets = empty($dateFacets) ? [] : explode(':', $dateFacets);
        $fields = $this->processDateFacets($filters, $dateFacets, $results);
        $facets = $this->processFacetValues($fields, $results);
        foreach ($fields as $field => $val) {
            $facets[$field]['min'] = $val[0] > 0 ? $val[0] : 0;
            $facets[$field]['max'] = $val[1] > 0 ? $val[1] : 0;
            $facets[$field]['removalURL']
                = $results->getUrlQuery()->removeFacet(
                    $field,
                    isset($filters[$field][0]) ? $filters[$field][0] : null,
                    false
                );
        }
        return $this->output($facets, self::STATUS_OK);
    }

    /**
     * Support method for getVisData() -- extract details from applied filters.
     *
     * @param array                       $filters    Current filter list
     * @param array                       $dateFacets Objects containing the date
     * ranges
     * @param \VuFind\Search\Solr\Results $results    Search results object
     *
     * @return array
     */
    protected function processDateFacets($filters, $dateFacets, $results)
    {
        $result = [];
        foreach ($dateFacets as $current) {
            $from = $to = '';
            if (isset($filters[$current])) {
                foreach ($filters[$current] as $filter) {
                    if (preg_match('/\[[\d\*]+ TO [\d\*]+\]/', $filter)) {
                        $range = explode(' TO ', trim($filter, '[]'));
                        $from = $range[0] == '*' ? '' : $range[0];
                        $to = $range[1] == '*' ? '' : $range[1];
                        break;
                    }
                }
            }
            $result[$current] = [$from, $to];
            $result[$current]['label']
                = $results->getParams()->getFacetLabel($current);
        }
        return $result;
    }

    /**
     * Support method for getVisData() -- filter bad values from facet lists.
     *
     * @param array                       $fields  Processed date information from
     * processDateFacets
     * @param \VuFind\Search\Solr\Results $results Search results object
     *
     * @return array
     */
    protected function processFacetValues($fields, $results)
    {
        $facets = $results->getFullFieldFacets(array_keys($fields));
        $retVal = [];
        foreach ($facets as $field => $values) {
            $newValues = ['data' => []];
            foreach ($values['data']['list'] as $current) {
                // Only retain numeric values!
                if (preg_match("/^[0-9]+$/", $current['value'])) {
                    $newValues['data'][]
                        = [$current['value'], $current['count']];
                }
            }
            $retVal[$field] = $newValues;
        }
        return $retVal;
    }

    /**
     * Get Autocomplete suggestions.
     *
     * @return \Zend\Http\Response
     */
    protected function getACSuggestionsAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $query = $this->getRequest()->getQuery();
        $autocompleteManager = $this->getServiceLocator()
            ->get('VuFind\AutocompletePluginManager');
        return $this->output(
            $autocompleteManager->getSuggestions($query), self::STATUS_OK
        );
    }

    /**
     * Check Request is Valid
     *
     * @return \Zend\Http\Response
     */
    protected function checkRequestIsValidAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $id = $this->params()->fromQuery('id');
        $data = $this->params()->fromQuery('data');
        $requestType = $this->params()->fromQuery('requestType');
        if (!empty($id) && !empty($data)) {
            // check if user is logged in
            $user = $this->getUser();
            if (!$user) {
                return $this->output(
                    [
                        'status' => false,
                        'msg' => $this->translate('You must be logged in first')
                    ],
                    self::STATUS_NEED_AUTH
                );
            }

            try {
                $catalog = $this->getILS();
                $patron = $this->getILSAuthenticator()->storedCatalogLogin();
                if ($patron) {
                    switch ($requestType) {
                    case 'ILLRequest':
                        $results = $catalog->checkILLRequestIsValid(
                            $id, $data, $patron
                        );

                        $msg = $results
                            ? $this->translate(
                                'ill_request_place_text'
                            )
                            : $this->translate(
                                'ill_request_error_blocked'
                            );
                        break;
                    case 'StorageRetrievalRequest':
                        $results = $catalog->checkStorageRetrievalRequestIsValid(
                            $id, $data, $patron
                        );

                        $msg = $results
                            ? $this->translate(
                                'storage_retrieval_request_place_text'
                            )
                            : $this->translate(
                                'storage_retrieval_request_error_blocked'
                            );
                        break;
                    default:
                        $results = $catalog->checkRequestIsValid(
                            $id, $data, $patron
                        );

                        $msg = $results
                            ? $this->translate('request_place_text')
                            : $this->translate('hold_error_blocked');
                        break;
                    }
                    return $this->output(
                        ['status' => $results, 'msg' => $msg], self::STATUS_OK
                    );
                }
            } catch (\Exception $e) {
                // Do nothing -- just fail through to the error message below.
            }
        }

        return $this->output(
            $this->translate('An error has occurred'), self::STATUS_ERROR
        );
    }

    /**
     * Comment on a record.
     *
     * @return \Zend\Http\Response
     */
    protected function commentRecordAjax()
    {
        $user = $this->getUser();
        if ($user === false) {
            return $this->output(
                $this->translate('You must be logged in first'),
                self::STATUS_NEED_AUTH
            );
        }

        $id = $this->params()->fromPost('id');
        $comment = $this->params()->fromPost('comment');
        if (empty($id) || empty($comment)) {
            return $this->output(
                $this->translate('An error has occurred'), self::STATUS_ERROR
            );
        }

        $table = $this->getTable('Resource');
        $resource = $table->findResource(
            $id, $this->params()->fromPost('source', DEFAULT_SEARCH_BACKEND)
        );
        $id = $resource->addComment($comment, $user);

        return $this->output($id, self::STATUS_OK);
    }

    /**
     * Delete a comment on a record.
     *
     * @return \Zend\Http\Response
     */
    protected function deleteRecordCommentAjax()
    {
        $user = $this->getUser();
        if ($user === false) {
            return $this->output(
                $this->translate('You must be logged in first'),
                self::STATUS_NEED_AUTH
            );
        }

        $id = $this->params()->fromQuery('id');
        $table = $this->getTable('Comments');
        if (empty($id) || !$table->deleteIfOwnedByUser($id, $user)) {
            return $this->output(
                $this->translate('An error has occurred'), self::STATUS_ERROR
            );
        }

        return $this->output($this->translate('Done'), self::STATUS_OK);
    }

    /**
     * Get list of comments for a record as HTML.
     *
     * @return \Zend\Http\Response
     */
    protected function getRecordCommentsAsHTMLAjax()
    {
        $driver = $this->getRecordLoader()->load(
            $this->params()->fromQuery('id'),
            $this->params()->fromQuery('source', DEFAULT_SEARCH_BACKEND)
        );
        $html = $this->getViewRenderer()
            ->render('record/comments-list.phtml', ['driver' => $driver]);
        return $this->output($html, self::STATUS_OK);
    }

    /**
     * Process an export request
     *
     * @return \Zend\Http\Response
     */
    protected function exportFavoritesAjax()
    {
        $format = $this->params()->fromPost('format');
        $export = $this->getServiceLocator()->get('VuFind\Export');
        $url = $export->getBulkUrl(
            $this->getViewRenderer(), $format,
            $this->params()->fromPost('ids', [])
        );
        $html = $this->getViewRenderer()->render(
            'ajax/export-favorites.phtml',
            ['url' => $url, 'format' => $format]
        );
        return $this->output(
            [
                'result' => $this->translate('Done'),
                'result_additional' => $html,
                'needs_redirect' => $export->needsRedirect($format),
                'export_type' => $export->getBulkExportType($format),
                'result_url' => $url
            ], self::STATUS_OK
        );
    }

    /**
     * Fetch Links from resolver given an OpenURL and format as HTML
     * and output the HTML content in JSON object.
     *
     * @return \Zend\Http\Response
     * @author Graham Seaman <Graham.Seaman@rhul.ac.uk>
     */
    protected function getResolverLinksAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $openUrl = $this->params()->fromQuery('openurl', '');

        $config = $this->getConfig();
        $resolverType = isset($config->OpenURL->resolver)
            ? $config->OpenURL->resolver : 'other';
        $pluginManager = $this->getServiceLocator()
            ->get('VuFind\ResolverDriverPluginManager');
        if (!$pluginManager->has($resolverType)) {
            return $this->output(
                $this->translate("Could not load driver for $resolverType"),
                self::STATUS_ERROR
            );
        }
        $resolver = new \VuFind\Resolver\Connection(
            $pluginManager->get($resolverType)
        );
        if (isset($config->OpenURL->resolver_cache)) {
            $resolver->enableCache($config->OpenURL->resolver_cache);
        }
        $result = $resolver->fetchLinks($openUrl);

        // Sort the returned links into categories based on service type:
        $electronic = $print = $services = [];
        foreach ($result as $link) {
            switch (isset($link['service_type']) ? $link['service_type'] : '') {
            case 'getHolding':
                $print[] = $link;
                break;
            case 'getWebService':
                $services[] = $link;
                break;
            case 'getDOI':
                // Special case -- modify DOI text for special display:
                $link['title'] = $this->translate('Get full text');
                $link['coverage'] = '';
            case 'getFullTxt':
            default:
                $electronic[] = $link;
                break;
            }
        }

        // Get the OpenURL base:
        if (isset($config->OpenURL) && isset($config->OpenURL->url)) {
            // Trim off any parameters (for legacy compatibility -- default config
            // used to include extraneous parameters):
            list($base) = explode('?', $config->OpenURL->url);
        } else {
            $base = false;
        }

        // Render the links using the view:
        $view = [
            'openUrlBase' => $base, 'openUrl' => $openUrl, 'print' => $print,
            'electronic' => $electronic, 'services' => $services
        ];
        $html = $this->getViewRenderer()->render('ajax/resolverLinks.phtml', $view);

        // output HTML encoded in JSON object
        return $this->output($html, self::STATUS_OK);
    }

    /**
     * Keep Alive
     *
     * This is responsible for keeping the session alive whenever called
     * (via JavaScript)
     *
     * @return \Zend\Http\Response
     */
    protected function keepAliveAjax()
    {
        return $this->output(true, self::STATUS_OK);
    }

    /**
     * Get pick up locations for a library
     *
     * @return \Zend\Http\Response
     */
    protected function getLibraryPickupLocationsAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $id = $this->params()->fromQuery('id');
        $pickupLib = $this->params()->fromQuery('pickupLib');
        if (!empty($id) && !empty($pickupLib)) {
            // check if user is logged in
            $user = $this->getUser();
            if (!$user) {
                return $this->output(
                    [
                        'status' => false,
                        'msg' => $this->translate('You must be logged in first')
                    ],
                    self::STATUS_NEED_AUTH
                );
            }

            try {
                $catalog = $this->getILS();
                $patron = $this->getILSAuthenticator()->storedCatalogLogin();
                if ($patron) {
                    $results = $catalog->getILLPickupLocations(
                        $id, $pickupLib, $patron
                    );
                    foreach ($results as &$result) {
                        if (isset($result['name'])) {
                            $result['name'] = $this->translate(
                                'location_' . $result['name'],
                                [],
                                $result['name']
                            );
                        }
                    }
                    return $this->output(
                        ['locations' => $results], self::STATUS_OK
                    );
                }
            } catch (\Exception $e) {
                // Do nothing -- just fail through to the error message below.
            }
        }

        return $this->output(
            $this->translate('An error has occurred'), self::STATUS_ERROR
        );
    }

    /**
     * Get pick up locations for a request group
     *
     * @return \Zend\Http\Response
     */
    protected function getRequestGroupPickupLocationsAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $id = $this->params()->fromQuery('id');
        $requestGroupId = $this->params()->fromQuery('requestGroupId');
        if (!empty($id) && !empty($requestGroupId)) {
            // check if user is logged in
            $user = $this->getUser();
            if (!$user) {
                return $this->output(
                    [
                        'status' => false,
                        'msg' => $this->translate('You must be logged in first')
                    ],
                    self::STATUS_NEED_AUTH
                );
            }

            try {
                $catalog = $this->getILS();
                $patron = $this->getILSAuthenticator()->storedCatalogLogin();
                if ($patron) {
                    $details = [
                        'id' => $id,
                        'requestGroupId' => $requestGroupId
                    ];
                    $results = $catalog->getPickupLocations(
                        $patron, $details
                    );
                    foreach ($results as &$result) {
                        if (isset($result['locationDisplay'])) {
                            $result['locationDisplay'] = $this->translate(
                                'location_' . $result['locationDisplay'],
                                [],
                                $result['locationDisplay']
                            );
                        }
                    }
                    return $this->output(
                        ['locations' => $results], self::STATUS_OK
                    );
                }
            } catch (\Exception $e) {
                // Do nothing -- just fail through to the error message below.
            }
        }

        return $this->output(
            $this->translate('An error has occurred'), self::STATUS_ERROR
        );
    }

    /**
     * Get hierarchical facet data for jsTree
     *
     * Parameters:
     * facetName  The facet to retrieve
     * facetSort  By default all facets are sorted by count. Two values are available
     * for alternative sorting:
     *   top = sort the top level alphabetically, rest by count
     *   all = sort all levels alphabetically
     *
     * @return \Zend\Http\Response
     */
    protected function getFacetDataAjax()
    {
        $this->writeSession();  // avoid session write timing bug

        $facet = $this->params()->fromQuery('facetName');
        $sort = $this->params()->fromQuery('facetSort');
        $operator = $this->params()->fromQuery('facetOperator');

        $results = $this->getResultsManager()->get('Solr');
        $params = $results->getParams();
        $params->addFacet($facet, null, $operator === 'OR');
        $params->initFromRequest($this->getRequest()->getQuery());

        $facets = $results->getFullFieldFacets([$facet], false, -1, 'count');
        if (empty($facets[$facet]['data']['list'])) {
            return $this->output([], self::STATUS_OK);
        }

        $facetList = $facets[$facet]['data']['list'];

        $facetHelper = $this->getServiceLocator()
            ->get('VuFind\HierarchicalFacetHelper');
        if (!empty($sort)) {
            $facetHelper->sortFacetList($facetList, $sort == 'top');
        }

        return $this->output(
            $facetHelper->buildFacetArray(
                $facet, $facetList, $results->getUrlQuery()
            ),
            self::STATUS_OK
        );
    }

    /**
     * Get number of matches for a certain tab
     *
     * @return \Zend\Http\Response
     */
    public function getNumberOfMatchesAjax() {
        if ($_REQUEST['idx'] == 'gbv') {
            $results = $this->getResultsManager()->get('Solr');
            $params = $results->getParams();
            $params->initFromRequest($this->getRequest()->getQuery());
            $recordCount = $results->getResultTotal();
        }
        if ($_REQUEST['idx'] == 'primo') {
            $results = $this->getResultsManager()->get('Primo');
            $params = $results->getParams();
            $params->initFromRequest($this->getRequest()->getQuery());
            $recordCount = $results->getResultTotal();
        }

        return $this->output(array('matches' => $recordCount), self::STATUS_OK);
    }


    /**
     * Load information about multivolumes for this item
     *
     * @return \Zend\Http\Response
     */
    protected function getMultiVolumes()
    {
        try {
            $driver = $this->getRecordLoader()->load(
                $_REQUEST['id'][0]
            );
            return $driver->isMultipartChildren();
        } catch (\Exception $e) {
            // Do nothing -- just return null
            return null;
        }
    }

    /**
     * Load information about multivolumes for this item
     *
     * @return void
     */
    protected function loadVolumeListAjax()
    {
        $mpList = new MultipartList($_REQUEST['id']);
        if (!$mpList->hasList()) {
            $driver = $this->getRecordLoader()->load(
                $_REQUEST['id']
            );
            $driver->cacheMultipartChildren();
        }
        return true;
    }

    /**
     * Get the content of this tab page by page.
     *
     * @return array
     */
    public function getMultipartAjax()
    {
        $retval = array();
        $mpList = new MultipartList($_REQUEST['id']);
        if ($mpList->hasList()) {
            $retval = $mpList->getCachedMultipartChildren();
            // $retval has now the correct order, now set the objects into the same order
/*            $returnObjects = array();
            $recordLoader = $this->getRecordLoader();
            for ($c = $_REQUEST['start']; $c < ($_REQUEST['start']+$_REQUEST['length']); $c++) {
                $object = $retval[$c];
                $returnObjects[] = $recordLoader->load($object['id']);
//                $returnObjects[] = $object['id'];
            }
*/
        }
        return $this->output($retval, self::STATUS_OK);
    }

    /**
     * Load information about printed copies for this item
     *
     * @return \Zend\Http\Response
     */
    protected function getPrintedStatuses()
    {
        try {
            $driver = $this->getRecordLoader()->load(
                $_REQUEST['id'][0],
                $this->params()->fromPost('source', 'Primo')
            );
            $containerID = $driver->getContainerRecordID();
            $ebookLink = $driver->getPrintedEbookRecordID();
        } catch (\Exception $e) {
            // Do nothing -- just return null
            return null;
        }


        $refId = null;
        if(!empty($containerID)) {
            $refId = $containerID;
        }
        else if (!empty($ebookLink)) {
            $refId = $ebookLink;
        }

        return $refId;

/*
        require_once 'sys/EZB.php';
        require_once 'RecordDrivers/PCRecord.php';
        global $interface;
        global $configArray;

        $url = null;
        $core = null;
        $printedSample = array();
        if (array_key_exists('id', $_REQUEST)) {
            if (substr($_REQUEST['id'], 0, 2) == 'PC') {
                $url = isset($configArray['IndexShards']['Primo Central']) ? 'http://'.$configArray['IndexShards']['Primo Central'] : null;
                $url = str_replace('/biblio', '', $url);
                $core = 'biblio';
            }

            // Setup Search Engine Connection
            $db = ConnectionManager::connectToIndex(null, $core, $url);

            // Retrieve the record from the index
            if (!($record = $db->getRecord($_REQUEST['id']))) {
                PEAR::raiseError(new PEAR_Error('Record Does Not Exist'));
            }
            $originalId = array('originalId' => $_REQUEST['id']);
            $recordDriver = RecordDriverFactory::initRecordDriver($record);
            $artFieldedRef = $recordDriver->getArticleFieldedReference();
            $bookFieldedRef = $recordDriver->getEbookFieldedReference();

            $printedSample = $recordDriver->getPrintedSample();

            // Find printed articles
            $articleVol = $recordDriver->searchArticleVolume($artFieldedRef);
            // Find printed ebook
            $printedEbook = $recordDriver->searchPrintedEbook($bookFieldedRef);
        }
        else {
            $originalId = array('originalId' => null);
            $artFieldedRef = array();
            $bookFieldedRef = array();
*/
            /* Parameterverarbeitung */
/*
            $artFieldedRef['title'] = $_REQUEST['rft_jtitle'];
            $bookFieldedRef['title'] = $_REQUEST['rft_btitle'];
            $bookFieldedRef['isbn'] = array();
            $bookFieldedRef['isbn'][] = $_REQUEST['rft_isbn'];
            if ($_REQUEST['rft_eisbn']) $bookFieldedRef['isbn'][] = $_REQUEST['rft_eisbn'];
            $artFieldedRef['issn'] = array();
            $artFieldedRef['issn'][] = $_REQUEST['rft_issn'];
            if ($_REQUEST['rft_eissn']) $artFieldedRef['issn'][] = $_REQUEST['rft_eissn'];
            $artFieldedRef['volume'] = $_REQUEST['rft_volume'];
            $artFieldedRef['issue'] = $_REQUEST['rft_issue'];
            $artFieldedRef['date'] = $_REQUEST['rft_date'];
            $artFieldedRef['service'] = 'external';
            $bookFieldedRef['service'] = 'external';
            
            $p = array();
            $p['issn'] = $_REQUEST['rft_issn'];
            $p['eissn'] = $_REQUEST['rft_eissn'];
            $p['format'] = $_REQUEST['rft_genre'];
            $p['date'] = $_REQUEST['rft_date'];
            $p['jtitle'] = $_REQUEST['rft_jtitle'];
            $p['atitle'] = $_REQUEST['rft_atitle'];
            $p['volume'] = $_REQUEST['rft_volume'];
            $p['issue'] = $_REQUEST['rft_issue'];
            $p['spage'] = $_REQUEST['rft_spage'];
            $p['epage'] = $_REQUEST['rft_epage'];
            
            $ezb = new EZB($p);
            $printedSample = $ezb->getPrintedInformation();
            
            $recordDriver = new PCRecord();
            
            // Find printed articles
            $articleVol = $recordDriver->searchArticleVolume($artFieldedRef);
            // Find printed ebook
            $printedEbook = $recordDriver->searchPrintedEbook($bookFieldedRef);
        }
            
        if ($articleVol) {
            $gbvid = array('id' => $articleVol['docs'][0]['id']);
            // if getPrintedInformation() returns null, array_merge will fail (never merge arrays with null!)
            // so if its not set, build an empty array now.
            if (!$printedSample) $printedSample = array();
            $articleVolRef = array_merge($gbvid, $originalId, $printedSample, $artFieldedRef);
            return $this->output(array($articleVolRef), JSON::STATUS_OK);
        }
        if ($printedEbook) {
            $gbvid = array('id' => $printedEbook['docs'][0]['id']);
            if ($printedEbook['docs'][0]['id']) {
                $gbvid['status'] = "5";
                $gbvid['gbvtitle'] = $printedEbook['docs'][0]['title'][0];
                $gbvid['gbvdate'] = $printedEbook['docs'][0]['publishDate'][0];
            }
            $printedEbookRef = array_merge($gbvid, $originalId, $bookFieldedRef);
            //print_r($printedEbookRef);
            return $this->output(array($printedEbookRef), JSON::STATUS_OK);
        }
        if ($printedSample) {
            $gbvid = array('id' => null);
            // if getPrintedInformation() returns null, array_merge will fail (never merge arrays with null!)
            // so if its not set, build an empty array now.
            $printedRef = array_merge($gbvid, $originalId, $printedSample, $artFieldedRef);
            return $this->output(array($printedRef), JSON::STATUS_OK);
        }
        
        return $this->output(
                translate("No results found!"), JSON::STATUS_ERROR
            );
*/
    }






    /**
     * Check status and return a status message for e.g. a load balancer.
     *
     * A simple OK as text/plain is returned if everything works properly.
     *
     * @return \Zend\Http\Response
     */
    protected function systemStatusAction()
    {
        $this->outputMode = 'plaintext';

        // Check system status
        $config = $this->getConfig();
        if (!empty($config->System->healthCheckFile)
            && file_exists($config->System->healthCheckFile)
        ) {
            return $this->output(
                'Health check file exists', self::STATUS_ERROR, 503
            );
        }

        // Test search index
        try {
            $results = $this->getResultsManager()->get('Solr');
            $params = $results->getParams();
            $params->setQueryIDs(['healthcheck']);
            $results->performAndProcessSearch();
        } catch (\Exception $e) {
            return $this->output(
                'Search index error: ' . $e->getMessage(), self::STATUS_ERROR, 500
            );
        }

        // Test database connection
        try {
            $sessionTable = $this->getTable('Session');
            $sessionTable->getBySessionId('healthcheck', false);
        } catch (\Exception $e) {
            return $this->output(
                'Database error: ' . $e->getMessage(), self::STATUS_ERROR, 500
            );
        }

        // This may be called frequently, don't leave sessions dangling
        $this->getServiceLocator()->get('VuFind\SessionManager')->destroy();

        return $this->output('', self::STATUS_OK);
    }

    /**
     * Convenience method for accessing results
     *
     * @return \VuFind\Search\Results\PluginManager
     */
    protected function getResultsManager()
    {
        return $this->getServiceLocator()->get('VuFind\SearchResultsPluginManager');
    }

   protected $institutions;

   protected $serviceLocator;
   protected $serviceLocatorAwareInterface;

    public function getConnectedRecordsAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $connectedRecords = $this->query('Solr', 'BELUGA_ALL', 'ppnlink:'.$_GET['ppnlink'].' -format:Article', 500);
        $recordData = array('docs' => array(), 'numFound' => $connectedRecords['numFound']);
        $recordSort = array();
        foreach ($connectedRecords['docs'] as $connectedRecord) {
            $recordDate = array();
            $recordDate['sort'] = (isset($connectedRecord['publishDateSort'])) ? 3000 - $connectedRecord['publishDateSort'] : 3000;
            $recordDate['title'] = '';
            $recordDate['extraTitle'] = '';
            if (isset($connectedRecord['topic_title']) && is_array($connectedRecord['topic_title'])) {
                foreach ( $connectedRecord['topic_title'] as $topicTitle) {
                    list(, $issue, ) = explode(' ', $topicTitle);
                    if (preg_match( '/^[0-9]+\.[0-9]{4}$/' , $issue)) {
                        break;
                    } else {
                        $issue = '';
                    }
                }
	        }
            if (isset($issue) && $issue != '') {
                if (isset($connectedRecord['title']) && is_array($connectedRecord['title'])) {
                    $recordDate['title'] = $this->prepareData($connectedRecord['title'][0]);
                } elseif (isset($connectedRecord['title_full']) && is_array($connectedRecord['title_full'])) {
                    $recordDate['title'] = $this->prepareData($connectedRecord['title_full'][0]).' ';
                }
                $recordDate['title'] .= $issue;
            } elseif (isset($connectedRecord['title_short']) && is_array($connectedRecord['title_short'])) {
                $recordDate['title'] = $this->prepareData($connectedRecord['title_short'][0]);
                if (isset($connectedRecord['title']) && is_array($connectedRecord['title'])) {
                    $recordDate['extraTitle'] = ' / '.$this->prepareData($connectedRecord['title'][0]);
                } elseif (isset($connectedRecord['title_full']) && is_array($connectedRecord['title_full'])) {
                    $recordDate['extraTitle'] = ' / '.$this->prepareData($connectedRecord['title_full'][0]);
                }
                if (isset($connectedRecord['author']) && $connectedRecord['author'] != '') {
                    $recordDate['extraTitle'] .= ' '.$this->prepareData($connectedRecord['author']);
                }
                if (isset($connectedRecord['publishDate']) && is_array($connectedRecord['publishDate'])) {
                    $recordDate['extraTitle'] .= ' '.$this->prepareData($connectedRecord['publishDate'][0]);
                }
            } elseif (isset($connectedRecord['title']) && is_array($connectedRecord['title'][0])) {
                $recordDate['title'] = $this->prepareData($connectedRecord['title'][0]);
                if (isset($connectedRecord['author']) && $connectedRecord['author'] != '') {
                    $recordDate['extraTitle'] = ' '.$this->prepareData($connectedRecord['author']);
                }
                if (isset($connectedRecord['publishDate']) && is_array($connectedRecord['publishDate'])) {
                    $recordDate['extraTitle'] .= ' '.$connectedRecord['publishDate'][0];
                }
            } elseif (isset($connectedRecord['title_full']) && is_array($connectedRecord['title_full'][0])) {
                $recordDate['title'] = $this->prepareData($connectedRecord['title_full'][0]);
                if (isset($connectedRecord['author']) && $connectedRecord['author'] != '') {
                    $recordDate['extraTitle'] = ' '.$this->prepareData($connectedRecord['author']);
                }
                if (isset($connectedRecord['publishDate']) && is_array($connectedRecord['publishDate'])) {
                    $recordDate['extraTitle'] .= ' '.$connectedRecord['publishDate'][0];
                }
            } else {
                $solrMarc = new SolrMarc();
                $solrMarc->setRawData($connectedRecord);
                $recordTitle = $solrMarc->getVolumeTitle();
                if (!empty($recordTitle)) {
                    $recordDate['title'] = $recordTitle;
                } else {
                    $recordDate['title'] = 'Band';
                    if (isset($connectedRecord['author']) && $connectedRecord['author'] != '') {
                        $recordDate['extraTitle'] = ' '.$this->prepareData($connectedRecord['author']);
                    }
                    if (isset($connectedRecord['publishDate']) && is_array($connectedRecord['publishDate'])) {
                        $recordDate['extraTitle'] .= ' '.$connectedRecord['publishDate'][0];
                    }
                }
            }
            $recordDate['id'] = $connectedRecord['id'];
            $recordData['docs'][] = $recordDate;
        }
        array_multisort($recordData['docs'], SORT_ASC);
        return $this->output($recordData, self::STATUS_OK);
    }

   /**
     * Set service locator
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator) {
        if ($serviceLocator instanceof ServiceLocatorAwareInterface) {
            $this->serviceLocatorAwareInterface = $serviceLocator;
        } else {
            $this->serviceLocator = $serviceLocator;
        }
    }

    /**
     * Get service locator
     *
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator() {
        return $this->serviceLocator;
    }

    protected function query($class, $institution, $search_terms, $limit = 0) {
/*      if ($institution == 'BELUGA_ALL') {
         $institution = $this->institutions->getInstitutionCodeForBelugaAll();
      }
      if ($class == 'Primo') {
         $terms = array(array('index' => 'AllFields', 'lookfor' => $search_terms));
         $params = array();
         $params['query'][]= array('index' => 'AllFields',
                                   'lookfor' => $search_terms,
                                   'fl' => '*,score',
                                   'spellcheck' => 'true',
                                   //'facet' => 'true',
                                   //'facet.limit' => '2000',
                                   //'facet.field' => array('collection_details', 'building', 'format', 'publishDate', 'language', 'authorStr', 'bklname'),
                                   //'facet.sort' => 'count',
                                   //'facet.mincount' => '1',
                                   //'sort' => 'sort desc',
                                   'hl' => 'true',
                                   'hl.fl' => '*',
                                   'hl.simple.pre' => '{{{{START_HILITE}}}}',
                                   'hl.simple.post' => '{{{{END_HILITE}}}}',
                                   'spellcheck.dictionary' => 'default',
                                   'spellcheck.q' => $search_terms
                                   );
         $params['limit'] = $limit;
         $params['pageNumber'] = 1;
         $params['filterList']['rtype'][] = 'Articles';
         $primo_backend_factory = new PrimoBackendFactory();
         $service = $primo_backend_factory->createService($this->serviceLocatorAwareInterface->getServiceLocator());
         $query_result = $service->getConnector()->query($institution, $terms, $params);
	 return $query_result;
      } else if ($class == 'Solr') { */
         $query = new Query();
         $query->setHandler('AllFields');
         $query->setString($search_terms);
         $solr_backend_factory = new SolrDefaultBackendFactory();
         $service = $solr_backend_factory->createService($this->serviceLocatorAwareInterface->getServiceLocator());
         $query_result = $service->search($query, 0, $limit);
	 return $query_result->getResponse();
      //}
      return false;
   }

    /**
     * handling data from gbv-index
     *
     * @param mixed $data
     *
     * @return string
     */
    protected function prepareData($data)
    {
        $searchArray = array('/A\xcc\x88/', '/A\xcc\x8a/', '/C\xcc\x8c/', '/O\xcc\x88/', '/U\xcc\x88/', '/a\xcc\x88/', '/a\xcc\x8a/', '/c\xcc\x8c/', '/c\xcc\xa6/', '/e\xcc\x8c/', '/e\xcc\x82/', '/o\xcc\x88/', '/u\xcc\x88/', '/\xc2\x98/', '/\xc2\x9c/');
        $translateArray = array('Ä', 'Å', 'Č', 'Ö' ,'Ü' ,'ä' ,'å', 'č', 'ç', 'ě', 'ê', 'ö' ,'ü' ,'' ,'' );
        $data = (is_array($data)) ? $data[0] : $data;
        return preg_replace($searchArray, $translateArray, $data);
    }

}
