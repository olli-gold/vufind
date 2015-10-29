<?php
/**
 * Model for GBV MARC records in Solr.
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
 * @package  RecordDrivers
 * @author   Oliver Goldschmidt <o.goldschmidt@tuhh.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:record_drivers Wiki
 */
namespace VuFind\RecordDriver;
use VuFind\Exception\ILS as ILSException,
    VuFind\View\Helper\Root\RecordLink,
    VuFind\XSLT\Processor as XSLTProcessor;

//use Zend\ServiceManager\ServiceLocatorAwareInterface;
//use Zend\ServiceManager\ServiceLocatorInterface;

use VuFindSearch\Backend\Solr\Backend;
use VuFindSearch\Query\Query;
use VuFindSearch\ParamBag;

use VuFind\Search\Factory\PrimoBackendFactory;
use VuFind\Search\Factory\SolrDefaultBackendFactory;

/**
 * Model for MARC records in Solr.
 *
 * @category VuFind2
 * @package  RecordDrivers
 * @author   Oliver Goldschmidt <o.goldschmidt@tuhh.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:record_drivers Wiki
 */
class SolrGBV extends SolrMarc
{
    /**
     * MARC record
     *
     * @var \File_MARC_Record
     */
    protected $marcRecord;

    /**
     * ILS connection
     *
     * @var \VuFind\ILS\Connection
     */
    protected $ils = null;

    /**
     * Hold logic
     *
     * @var \VuFind\ILS\Logic\Holds
     */
    protected $holdLogic;

    /**
     * Title hold logic
     *
     * @var \VuFind\ILS\Logic\TitleHolds
     */
    protected $titleHoldLogic;

    /**
     * Does the OpenURL configuration indicate that we should display OpenURLs in
     * the specified context?
     *
     * @param string $area 'results', 'record' or 'holdings'
     *
     * @return bool
     */
    // use the default function sice we want to have an openURL independently from an ISSN (which is forced in SolrDefault)
    public function openURLActive($area)
    {
        // Doesn't matter the target area if no OpenURL resolver is specified:
        if (!isset($this->mainConfig->OpenURL->url)) {
            return false;
        }

        // If a setting exists, return that:
        $key = 'show_in_' . $area;
        if (isset($this->mainConfig->OpenURL->$key)) {
            return $this->mainConfig->OpenURL->$key;
        }

        // If we got this far, use the defaults -- true for results, false for
        // everywhere else.
        return ($area == 'results');
    }

    /**
     * Support method for getOpenURL() -- pick the OpenURL format.
     *
     * @return string
     */
    protected function getOpenURLFormat()
    {
        // If we have multiple formats, Book, Journal and Article are most
        // important...
        $formats = $this->getFormats();
        if ($this->isHSS() === true) {
            return 'dissertation';
        }
        if (in_array('Book', $formats) || in_array('eBook', $formats)) {
            return 'book';
        } else if (in_array('Article', $formats) || in_array('Aufsätze', $formats) || in_array('Elektronische Aufsätze', $formats) || in_array('electronic Article', $formats)) {
            return 'article';
        } else if (in_array('Journal', $formats) || in_array('eJournal', $formats)) {
            return 'journal';
        } else if (in_array('Serial Volume', $formats)) {
            return 'SerialVolume';
        } else if (isset($formats[0])) {
            return $formats[0];
        } else if (strlen($this->getCleanISSN()) > 0) {
            return 'journal';
        }
        return 'book';
    }

    /**
     * Get default OpenURL parameters.
     *
     * @return array
     */
    protected function getDefaultOpenURLParams()
    {
        // Get a representative publication date:
        $pubDate = $this->getPublicationDates();
        $pubDate = empty($pubDate) ? '' : $pubDate[0];

        $urls = $this->getUrls();
        $doi = null;
        if ($urls) {
            foreach ($urls as $url => $desc) {
                // check if we have a doi
                if (strstr($url, 'http://dx.doi.org/') !== false) {
                    $doi = 'info:doi/'.substr($url, 18);
                }
            }
        }

        // Start an array of OpenURL parameters:
        return [
            'ctx_ver' => 'Z39.88-2004',
            'ctx_enc' => 'info:ofi/enc:UTF-8',
            'rfr_id' => 'info:sid/' . $this->getCoinsID() . ':generator',
            'rft.title' => $this->getShortTitle(),
            'rft.date' => $pubDate,
            'rft_id' => $doi,
            'rft.genre' => $this->getOpenURLFormat()
        ];
    }

    /**
     * Get OpenURL parameters for a book.
     *
     * @return array
     */
    protected function getBookOpenURLParams()
    {
        $params = $this->getDefaultOpenURLParams();
        $params['rft_val_fmt'] = 'info:ofi/fmt:kev:mtx:book';
//        $params['rft.genre'] = 'book';
        $params['rft.btitle'] = $params['rft.title'];
        $series = $this->getSeries();
        if (count($series) > 0) {
            // Handle both possible return formats of getSeries:
            $params['rft.series'] = is_array($series[0]) ?
                $series[0]['name'] : $series[0];
        }
        $params['rft.au'] = $this->getPrimaryAuthor();
        $publishers = $this->getPublishers();
        if (count($publishers) > 0) {
            $params['rft.pub'] = $publishers[0];
        }
        $params['rft.edition'] = $this->getEdition();
        $params['rft.isbn'] = (string)$this->getCleanISBN();
        return $params;
    }

    /**
     * Get OpenURL parameters for a serial volume.
     *
     * @return array
     */
    protected function getSerialVolumeOpenURLParams()
    {
        $params = $this->getUnknownFormatOpenURLParams('Journal');
        /* This is probably the most technically correct way to represent
         * a journal run as an OpenURL; however, it doesn't work well with
         * Zotero, so it is currently commented out -- instead, we just add
         * some extra fields and to the "unknown format" case. */
        $params['rft_val_fmt'] = 'info:ofi/fmt:kev:mtx:journal';
//        $params['rft.genre'] = 'journal';
        $params['rft.jtitle'] = $params['rft.title'];
        $params['rft.issn'] = $this->getCleanISSN();
        $params['rft.au'] = $this->getPrimaryAuthor();

        //$params['rft.issn'] = (string)$this->getCleanISSN();

        // Including a date in a title-level Journal OpenURL may be too
        // limiting -- in some link resolvers, it may cause the exclusion
        // of databases if they do not cover the exact date provided!
        //unset($params['rft.date']);

        // If we're working with the SFX resolver, we should add a
        // special parameter to ensure that electronic holdings links
        // are shown even though no specific date or issue is specified:
        if (isset($this->mainConfig->OpenURL->resolver)
            && strtolower($this->mainConfig->OpenURL->resolver) == 'sfx'
        ) {
            //$params['sfx.ignore_date_threshold'] = 1;
            $params['disable_directlink'] = "true";
            $params['sfx.directlink'] = "off";
        }
        return $params;
    }


    /**
     * Get OpenURL parameters for an article.
     *
     * @return array
     */
    protected function getArticleOpenURLParams()
    {
        $params = $this->getDefaultOpenURLParams();
        unset($params['rft.date']);
        $params['rft_val_fmt'] = 'info:ofi/fmt:kev:mtx:journal';
//        $params['rft.genre'] = 'article';
        $params['rft.issn'] = (string)$this->getCleanISSN();
        // an article may have also an ISBN:
        $params['rft.isbn'] = (string)$this->getCleanISBN();
        $articleFields = $this->getArticleFieldedReference();
        if ($articleFields['volume']) $params['rft.volume'] = $articleFields['volume'];
        if ($articleFields['issue']) $params['rft.issue'] = $articleFields['issue'];
        if ($articleFields['spage']) $params['rft.spage'] = $articleFields['spage'];
        if ($articleFields['epage']) $params['rft.epage'] = $articleFields['epage'];
        if ($articleFields['date']) $params['rft.date'] = $articleFields['date'];
        $journalTitle = $this->getArticleHReference();
        if ($journalTitle['jref']) $params['rft.jtitle'] = $journalTitle['jref'];
        // unset default title -- we only want jtitle/atitle here:
        unset($params['rft.title']);
        $params['rft.au'] = $this->getPrimaryAuthor();
        $params['rft.atitle'] = $params['rft.title'];

        $params['rft.format'] = 'Article';
        $langs = $this->getLanguages();
        if (count($langs) > 0) {
            $params['rft.language'] = $langs[0];
        }
        return $params;
    }

    /**
     * Get OpenURL parameters for an electronic resource.
     *
     * @return array
     */
    protected function getEresOpenURLParams()
    {
        $params = $this->getDefaultOpenURLParams();
//        $params['rft.genre'] = 'book';
        $params['rft.isbn'] = $this->getCleanISBN();
        $params['rft_val_fmt'] = 'info:ofi/fmt:kev:mtx:dc';
        $params['rft.creator'] = $this->getPrimaryAuthor();
        $publishers = $this->getPublishers();
        if (count($publishers) > 0) {
            $params['rft.pub'] = $publishers[0];
        }
        $params['rft.format'] = $format;
        $langs = $this->getLanguages();
        if (count($langs) > 0) {
            $params['rft.language'] = $langs[0];
        }
        return $params;
    }

    /**
     * Get OpenURL parameters for an unknown format.
     *
     * @param string $format Name of format
     *
     * @return array
     */
    protected function getUnknownFormatOpenURLParams($format)
    {
        $params = $this->getDefaultOpenURLParams();
        $params['rft_val_fmt'] = 'info:ofi/fmt:kev:mtx:dc';
        $params['rft.creator'] = $this->getPrimaryAuthor();
        $publishers = $this->getPublishers();
        if (count($publishers) > 0) {
            $params['rft.pub'] = $publishers[0];
        }
        $params['rft.format'] = $format;
        $langs = $this->getLanguages();
        if (count($langs) > 0) {
            $params['rft.language'] = $langs[0];
        }
        return $params;
    }

    /**
     * Get OpenURL parameters for a journal.
     *
     * @return array
     */
    protected function getJournalOpenURLParams()
    {
        $params = $this->getUnknownFormatOpenURLParams('Journal');
        /* This is probably the most technically correct way to represent
         * a journal run as an OpenURL; however, it doesn't work well with
         * Zotero, so it is currently commented out -- instead, we just add
         * some extra fields and to the "unknown format" case. */
        $params['rft_val_fmt'] = 'info:ofi/fmt:kev:mtx:journal';
//        $params['rft.genre'] = 'journal';
        $params['rft.jtitle'] = $params['rft.title'];
        $params['rft.issn'] = $this->getCleanISSN();
        $params['rft.au'] = $this->getPrimaryAuthor();

        $params['rft.issn'] = (string)$this->getCleanISSN();

        // Including a date in a title-level Journal OpenURL may be too
        // limiting -- in some link resolvers, it may cause the exclusion
        // of databases if they do not cover the exact date provided!
        unset($params['rft.date']);

        // If we're working with the SFX resolver, we should add a
        // special parameter to ensure that electronic holdings links
        // are shown even though no specific date or issue is specified:
        if (isset($this->mainConfig->OpenURL->resolver)
            && strtolower($this->mainConfig->OpenURL->resolver) == 'sfx'
        ) {
            $params['sfx.ignore_date_threshold'] = 1;
            $params['disable_directlink'] = "true";
            $params['sfx.directlink'] = "off";
        }
        return $params;
    }

    /**
     * TUBHH Enhancement for GBV Discovery
     * Return the reference of one article
     * An array will be returned with keys=volume, issue, startpage [spage], endpage [epage] and publication year [date].
     *
     * @access  public
     * @return  array
     */
    public function getArticleFieldedReference()
    {
        $retVal = array();
        $retVal['volume'] = $this->getVolume();
        $retVal['issue'] = $this->getIssue();
        $pages = $this->getPages();
        $pagesArr = explode('-', $pages);
        $retVal['spage'] = $pagesArr[0];
        $retVal['epage'] = $pagesArr[1];
        $retVal['date'] = $this->getRefYear();
        return $retVal;
    }

    /**
     * Get the reference of the article including its link.
     *
     * @access  protected
     * @return  array
     */
    protected function getArticleHReference()
    {
        if (in_array('Article', $this->getFormats()) === true) {
            $vs = null;
            $vs = $this->marcRecord->getFields('773');
            if (count($vs) > 0) {
                $refs = array();
                foreach($vs as $v) {
                    $journalRef = null;
                    $articleRef = null;
                    $inRefField = $v->getSubfields('i');
                    if (count($inRefField) > 0) {
                        $inRef = $inRefField[0]->getData();
                    }
                    else {
                        $inRef = "in:";
                    }
                    $journalRefField = $v->getSubfields('t');
                    if (count($journalRefField) > 0) {
                        $journalRef = $journalRefField[0]->getData();
                    }
                    $articleRefField = $v->getSubfields('g');
                    if (count($articleRefField) > 0) {
                        $articleRef = $articleRefField[0]->getData();
                    }
                    $a_names = $v->getSubfields('w');
                    if (count($a_names) > 0) {
                        $idArr = explode(')', $a_names[0]->getData());
                        $hrefId = $this->addNLZ($idArr[1]);
                    }
                    if ($journalRef || $articleRef) {
                        $refs[] = array('inref' => $inRef, 'jref' => $journalRef, 'aref' => $articleRef, 'hrefId' => $hrefId);
                    }
                }
                return $refs;
            }
        }
        return null;
    }

    /**
     * Get information about the volume stocks.
     *
     * @access  public
     * @return  array
     */
    public function getVolumeStock()
    {
        $vs = null;
        $stock = array();
        $vs = $this->marcRecord->getFields('980');
        if (count($vs) > 0) {
            $refs = array();
            foreach($vs as $v) {
                $stockInfo = '';
                $idx = '';
                $libField = $v->getSubfields('2');
                if (count($libField) > 0) {
                    $lib = $libField[0]->getData();
                }
                if ($lib == '23') {
                    $stockField = $v->getSubfields('g');
                    if (count($stockField) > 0) {
                        $stockInfo = $stockField[0]->getData();
                    }
                    $callnoField = $v->getSubfields('d');
                    if (count($callnoField) > 0) {
                        $idx = $callnoField[0]->getData();
                    }
                    $stock[$idx] = $stockInfo;
                }
            }
        }
        return $stock;
    }

    /**
     * Get note(s) about the volume stocks.
     *
     * @access  public
     * @return  string
     */
    public function getVolumeStockNote()
    {
        $vs = null;
        $stock = '';
        $vs = $this->marcRecord->getFields('980');
        if (count($vs) > 0) {
            $refs = array();
            foreach($vs as $v) {
                $libField = $v->getSubfields('2');
                if (count($libField) > 0) {
                    $lib = $libField[0]->getData();
                }
                if ($lib == '23') {
                    $stockField = $v->getSubfields('k');
                    if (count($stockField) > 0) {
                        $stock .= $stockField[0]->getData();
                    }
                }
            }
        }
        return $stock;
    }

    /**
     * TUBHH Enhancement
     * Return the title (period) and the signature of a volume
     * An array will be returned with key=signature, value=title.
     *
     * @access  public
     * @return  array
     */
    public function getVolume()
    {
        return $this->getFirstFieldValue('952', array('d'));
    }

    /**
     * Determines if this record is a scholarly paper
     *
     * @return boolean
     */
    protected function isHSS()
    {
        return ($this->getFirstFieldValue('502', array('a'))) ? true : false;
    }

    /**
     * Get the title of the item
     *
     * @access  public
     * @return  string
     */
    public function getTitle() {
         return $this->getTitleAdvanced();
    }

    /**
     * Get the title of the item
     *
     * @access  protected
     * @return  array
     */
    public function getTitleAdvanced() {
        $return = '';
        if ($this->getFirstFieldValue('245', array('a'))) $return = $this->getFirstFieldValue('245', array('a'));
        if ($this->getFirstFieldValue('245', array('a')) && $this->getFirstFieldValue('245', array('b')) && substr(trim($this->getFirstFieldValue('245', array('a'))), -1) !== ':' && substr(trim($this->getFirstFieldValue('245', array('b'))), 0, 1) !== ':') $return .= " :";
        if ($this->getFirstFieldValue('245', array('b'))) $return .= " ".$this->getFirstFieldValue('245', array('b'));
        if ($this->getFirstFieldValue('245', array('n')) || $this->getFirstFieldValue('245', array('p'))) $return .= " (";
        if ($this->getFirstFieldValue('245', array('n'))) $return .= $this->getFirstFieldValue('245', array('n'));
        if ($this->getFirstFieldValue('245', array('n')) && $this->getFirstFieldValue('245', array('p'))) $return .= ";";
        if ($this->getFirstFieldValue('245', array('p'))) $return .= " ".$this->getFirstFieldValue('245', array('p'));
        if ($this->getFirstFieldValue('245', array('n')) || $this->getFirstFieldValue('245', array('p'))) $return .= ")";
        if ($return !== '') return $return;
        if ($this->getFirstFieldValue('490', array('a'))) $return = $this->getFirstFieldValue('490', array('a'));
        if ($this->getFirstFieldValue('490', array('v'))) $return .= " (".$this->getFirstFieldValue('490', array('v')).")";
        if ($return !== '') return $return;
        if ($this->getFirstFieldValue('773', array('t'))) $return = $this->getFirstFieldValue('773', array('t'));
        return $return;
    }

    /**
     * TUBHH Enhancement
     * Return the title (period) and the signature of a volume
     * An array will be returned with key=signature, value=title.
     *
     * @access  public
     * @return  array
     */
    public function getIssue()
    {
        return $this->getFirstFieldValue('952', array('e'));
    }

    /**
     * TUBHH Enhancement
     * Return the title (period) and the signature of a volume
     * An array will be returned with key=signature, value=title.
     *
     * @access  public
     * @return  array
     */
    public function getPages()
    {
        return $this->getFirstFieldValue('952', array('h'));
    }

    /**
     * TUBHH Enhancement
     * Return the title (period) and the signature of a volume
     * An array will be returned with key=signature, value=title.
     *
     * @access  public
     * @return  array
     */
    public function getRefYear()
    {
        return $this->getFirstFieldValue('952', array('j'));
    }

    /**
     * Set raw data to initialize the object.
     *
     * @param mixed $data Raw data representing the record; Record Model
     * objects are normally constructed by Record Driver objects using data
     * passed in from a Search Results object.  In this case, $data is a Solr record
     * array containing MARC data in the 'fullrecord' field.
     *
     * @return void
     */
    public function setRawData($data)
    {
        // Call the parent's set method...
        parent::setRawData($data);

        // Also process the MARC record:
        $marc = trim($data['fullrecord']);

        // check if we are dealing with MARCXML
        if (substr($marc, 0, 1) == '<') {
            $marc = new \File_MARCXML($marc, \File_MARCXML::SOURCE_STRING);
        } else {
            // When indexing over HTTP, SolrMarc may use entities instead of certain
            // control characters; we should normalize these:
            $marc = str_replace(
                ['#29;', '#30;', '#31;'], ["\x1D", "\x1E", "\x1F"], $marc
            );
            $marc = new \File_MARC($marc, \File_MARC::SOURCE_STRING);
        }

        $this->marcRecord = $marc->next();
        if (!$this->marcRecord) {
            throw new \File_MARC_Exception('Cannot Process MARC Record');
        }
    }

    /**
     * Get access restriction notes for the record.
     *
     * @return array
     */
    public function getAccessRestrictions()
    {
        return $this->getFieldArray('506');
    }

    /**
     * Get all subject headings associated with this record.  Each heading is
     * returned as an array of chunks, increasing from least specific to most
     * specific.
     *
     * @return array
     */
    public function getAllSubjectHeadings()
    {
        // These are the fields that may contain subject headings:
        $fields = [
            '600', '610', '611', '630', '648', '650', '651', '653', '655', '656'
        ];

        // This is all the collected data:
        $retval = [];

        // Try each MARC field one at a time:
        foreach ($fields as $field) {
            // Do we have any results for the current field?  If not, try the next.
            $results = $this->marcRecord->getFields($field);
            if (!$results) {
                continue;
            }

            // If we got here, we found results -- let's loop through them.
            foreach ($results as $result) {
                // Start an array for holding the chunks of the current heading:
                $current = [];

                // Get all the chunks and collect them together:
                $subfields = $result->getSubfields();
                if ($subfields) {
                    foreach ($subfields as $subfield) {
                        // Numeric subfields are for control purposes and should not
                        // be displayed:
                        if (!is_numeric($subfield->getCode())) {
                            $current[] = $subfield->getData();
                        }
                    }
                    // If we found at least one chunk, add a heading to our result:
                    if (!empty($current)) {
                        $retval[] = $current;
                    }
                }
            }
        }

        // Send back everything we collected:
        return $retval;
    }

    /**
     * Get award notes for the record.
     *
     * @return array
     */
    public function getAwards()
    {
        return $this->getFieldArray('586');
    }

    /**
     * Get the bibliographic level of the current record.
     *
     * @return string
     */
    public function getBibliographicLevel()
    {
        $leader = $this->marcRecord->getLeader();
        $biblioLevel = strtoupper($leader[7]);

        switch ($biblioLevel) {
        case 'M': // Monograph
            return "Monograph";
        case 'S': // Serial
            return "Serial";
        case 'A': // Monograph Part
            return "MonographPart";
        case 'B': // Serial Part
            return "SerialPart";
        case 'C': // Collection
            return "Collection";
        case 'D': // Collection Part
            return "CollectionPart";
        default:
            return "Unknown";
        }
    }

    /**
     * Get notes on bibliography content.
     *
     * @return array
     */
    public function getBibliographyNotes()
    {
        return $this->getFieldArray('504');
    }

    /**
     * Get the main corporate author (if any) for the record.
     *
     * @return string
     */
    public function getCorporateAuthor()
    {
        // Try 110 first -- if none found, try 710 next.
        $main = $this->getFirstFieldValue('110', ['a', 'b']);
        if (!empty($main)) {
            return $main;
        }
        return $this->getFirstFieldValue('710', ['a', 'b']);
    }

    /**
     * Return an array of all values extracted from the specified field/subfield
     * combination.  If multiple subfields are specified and $concat is true, they
     * will be concatenated together in the order listed -- each entry in the array
     * will correspond with a single MARC field.  If $concat is false, the return
     * array will contain separate entries for separate subfields.
     *
     * @param string $field     The MARC field number to read
     * @param array  $subfields The MARC subfield codes to read
     * @param bool   $concat    Should we concatenate subfields?
     *
     * @return array
     */
    protected function getFieldArray($field, $subfields = null, $concat = true)
    {
        // Default to subfield a if nothing is specified.
        if (!is_array($subfields)) {
            $subfields = ['a'];
        }

        // Initialize return array
        $matches = [];

        // Try to look up the specified field, return empty array if it doesn't
        // exist.
        $fields = $this->marcRecord->getFields($field);
        if (!is_array($fields)) {
            return $matches;
        }

        // Extract all the requested subfields, if applicable.
        foreach ($fields as $currentField) {
            $next = $this->getSubfieldArray($currentField, $subfields, $concat);
            $matches = array_merge($matches, $next);
        }

        return $matches;
    }

    /**
     * Get notes on finding aids related to the record.
     *
     * @return array
     */
    public function getFindingAids()
    {
        return $this->getFieldArray('555');
    }

    /**
     * Get the first value matching the specified MARC field and subfields.
     * If multiple subfields are specified, they will be concatenated together.
     *
     * @param string $field     The MARC field to read
     * @param array  $subfields The MARC subfield codes to read
     *
     * @return string
     */
    protected function getFirstFieldValue($field, $subfields = null)
    {
        $matches = $this->getFieldArray($field, $subfields);
        return (is_array($matches) && count($matches) > 0) ?
            $matches[0] : null;
    }

    /**
     * Get general notes on the record.
     *
     * @return array
     */
    public function getGeneralNotes()
    {
        return $this->getFieldArray('500');
    }

    /**
     * Get human readable publication dates for display purposes (may not be suitable
     * for computer processing -- use getPublicationDates() for that).
     *
     * @return array
     */
    public function getHumanReadablePublicationDates()
    {
        return $this->getPublicationInfo('c');
    }

    /**
     * Get an array of newer titles for the record.
     *
     * @return array
     */
    public function getNewerTitles()
    {
        // If the MARC links are being used, return blank array
        $fieldsNames = isset($this->mainConfig->Record->marc_links)
            ? array_map('trim', explode(',', $this->mainConfig->Record->marc_links))
            : [];
        return in_array('785', $fieldsNames) ? [] : parent::getNewerTitles();
    }

    /**
     * Get the item's publication information
     *
     * @param string $subfield The subfield to retrieve ('a' = location, 'c' = date)
     *
     * @return array
     */
    protected function getPublicationInfo($subfield = 'a')
    {
        // First check old-style 260 field:
        $results = $this->getFieldArray('260', [$subfield]);

        // Now track down relevant RDA-style 264 fields; we only care about
        // copyright and publication places (and ignore copyright places if
        // publication places are present).  This behavior is designed to be
        // consistent with default SolrMarc handling of names/dates.
        $pubResults = $copyResults = [];

        $fields = $this->marcRecord->getFields('264');
        if (is_array($fields)) {
            foreach ($fields as $currentField) {
                $currentVal = $currentField->getSubfield($subfield);
                $currentVal = is_object($currentVal)
                    ? $currentVal->getData() : null;
                if (!empty($currentVal)) {
                    switch ($currentField->getIndicator('2')) {
                    case '1':
                        $pubResults[] = $currentVal;
                        break;
                    case '4':
                        $copyResults[] = $currentVal;
                        break;
                    }
                }
            }
        }
        if (count($pubResults) > 0) {
            $results = array_merge($results, $pubResults);
        } else if (count($copyResults) > 0) {
            $results = array_merge($results, $copyResults);
        }

        return $results;
    }

    /**
     * Get the item's places of publication.
     *
     * @return array
     */
    public function getPlacesOfPublication()
    {
        return $this->getPublicationInfo();
    }

    /**
     * Get an array of playing times for the record (if applicable).
     *
     * @return array
     */
    public function getPlayingTimes()
    {
        $times = $this->getFieldArray('306', ['a'], false);

        // Format the times to include colons ("HH:MM:SS" format).
        for ($x = 0; $x < count($times); $x++) {
            $times[$x] = substr($times[$x], 0, 2) . ':' .
                substr($times[$x], 2, 2) . ':' .
                substr($times[$x], 4, 2);
        }

        return $times;
    }

    /**
     * Get an array of previous titles for the record.
     *
     * @return array
     */
    public function getPreviousTitles()
    {
        // If the MARC links are being used, return blank array
        $fieldsNames = isset($this->mainConfig->Record->marc_links)
            ? array_map('trim', explode(',', $this->mainConfig->Record->marc_links))
            : [];
        return in_array('780', $fieldsNames) ? [] : parent::getPreviousTitles();
    }

    /**
     * Get credits of people involved in production of the item.
     *
     * @return array
     */
    public function getProductionCredits()
    {
        return $this->getFieldArray('508');
    }

    /**
     * Get an array of publication frequency information.
     *
     * @return array
     */
    public function getPublicationFrequency()
    {
        return $this->getFieldArray('310', ['a', 'b']);
    }

    /**
     * Get an array of strings describing relationships to other items.
     *
     * @return array
     */
    public function getRelationshipNotes()
    {
        return $this->getFieldArray('580');
    }

    /**
     * Get an array of all series names containing the record.  Array entries may
     * be either the name string, or an associative array with 'name' and 'number'
     * keys.
     *
     * @return array
     */
    public function getSeries()
    {
        $matches = [];

        // First check the 440, 800 and 830 fields for series information:
        $primaryFields = [
            '440' => ['a', 'p'],
            '800' => ['a', 'b', 'c', 'd', 'f', 'p', 'q', 't'],
            '830' => ['a', 'p']];
        $matches = $this->getSeriesFromMARC($primaryFields);
        if (!empty($matches)) {
            return $matches;
        }

        // Now check 490 and display it only if 440/800/830 were empty:
        $secondaryFields = ['490' => ['a']];
        $matches = $this->getSeriesFromMARC($secondaryFields);
        if (!empty($matches)) {
            return $matches;
        }

        // Still no results found?  Resort to the Solr-based method just in case!
        return parent::getSeries();
    }

    /**
     * Support method for getSeries() -- given a field specification, look for
     * series information in the MARC record.
     *
     * @param array $fieldInfo Associative array of field => subfield information
     * (used to find series name)
     *
     * @return array
     */
    protected function getSeriesFromMARC($fieldInfo)
    {
        $matches = [];

        // Loop through the field specification....
        foreach ($fieldInfo as $field => $subfields) {
            // Did we find any matching fields?
            $series = $this->marcRecord->getFields($field);
            if (is_array($series)) {
                foreach ($series as $currentField) {
                    // Can we find a name using the specified subfield list?
                    $name = $this->getSubfieldArray($currentField, $subfields);
                    if (isset($name[0])) {
                        $currentArray = ['name' => $name[0]];

                        // Can we find a number in subfield v?  (Note that number is
                        // always in subfield v regardless of whether we are dealing
                        // with 440, 490, 800 or 830 -- hence the hard-coded array
                        // rather than another parameter in $fieldInfo).
                        $number
                            = $this->getSubfieldArray($currentField, ['v']);
                        if (isset($number[0])) {
                            $currentArray['number'] = $number[0];
                        }

                        // Save the current match:
                        $matches[] = $currentArray;
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Return an array of non-empty subfield values found in the provided MARC
     * field.  If $concat is true, the array will contain either zero or one
     * entries (empty array if no subfields found, subfield values concatenated
     * together in specified order if found).  If concat is false, the array
     * will contain a separate entry for each subfield value found.
     *
     * @param object $currentField Result from File_MARC::getFields.
     * @param array  $subfields    The MARC subfield codes to read
     * @param bool   $concat       Should we concatenate subfields?
     *
     * @return array
     */
    protected function getSubfieldArray($currentField, $subfields, $concat = true)
    {
        // Start building a line of text for the current field
        $matches = [];
        $currentLine = '';

        // Loop through all subfields, collecting results that match the whitelist;
        // note that it is important to retain the original MARC order here!
        $allSubfields = $currentField->getSubfields();
        if (count($allSubfields) > 0) {
            foreach ($allSubfields as $currentSubfield) {
                if (in_array($currentSubfield->getCode(), $subfields)) {
                    // Grab the current subfield value and act on it if it is
                    // non-empty:
                    $data = trim($currentSubfield->getData());
                    if (!empty($data)) {
                        // Are we concatenating fields or storing them separately?
                        if ($concat) {
                            $currentLine .= $data . ' ';
                        } else {
                            $matches[] = $data;
                        }
                    }
                }
            }
        }

        // If we're in concat mode and found data, it will be in $currentLine and
        // must be moved into the matches array.  If we're not in concat mode,
        // $currentLine will always be empty and this code will be ignored.
        if (!empty($currentLine)) {
            $matches[] = trim($currentLine);
        }

        // Send back our result array:
        return $matches;
    }

    /**
     * Get an array of summary strings for the record.
     *
     * @return array
     */
    public function getSummary()
    {
        return $this->getFieldArray('520');
    }

    /**
     * Get an array of technical details on the item represented by the record.
     *
     * @return array
     */
    public function getSystemDetails()
    {
        return $this->getFieldArray('538');
    }

    /**
     * Get an array of note about the record's target audience.
     *
     * @return array
     */
    public function getTargetAudienceNotes()
    {
        return $this->getFieldArray('521');
    }

    /**
     * Get the text of the part/section portion of the title.
     *
     * @return string
     */
    public function getTitleSection()
    {
        return $this->getFirstFieldValue('245', ['n', 'p']);
    }

    /**
     * Get the statement of responsibility that goes with the title (i.e. "by John
     * Smith").
     *
     * @return string
     */
    public function getTitleStatement()
    {
        return $this->getFirstFieldValue('245', ['c']);
    }

    /**
     * Get an array of lines from the table of contents.
     *
     * @return array
     */
    public function getTOC()
    {
        // Return empty array if we have no table of contents:
        $fields = $this->marcRecord->getFields('505');
        if (!$fields) {
            return [];
        }

        // If we got this far, we have a table -- collect it as a string:
        $toc = [];
        foreach ($fields as $field) {
            $subfields = $field->getSubfields();
            foreach ($subfields as $subfield) {
                // Break the string into appropriate chunks,  and merge them into
                // return array:
                $toc = array_merge($toc, explode('--', $subfield->getData()));
            }
        }
        return $toc;
    }

    /**
     * Get hierarchical place names (MARC field 752)
     *
     * Returns an array of formatted hierarchical place names, consisting of all
     * alpha-subfields, concatenated for display
     *
     * @return array
     */
    public function getHierarchicalPlaceNames()
    {
        $placeNames = [];
        if ($fields = $this->marcRecord->getFields('752')) {
            foreach ($fields as $field) {
                $subfields = $field->getSubfields();
                $current = [];
                foreach ($subfields as $subfield) {
                    if (!is_numeric($subfield->getCode())) {
                        $current[] = $subfield->getData();
                    }
                }
                $placeNames[] = implode(' -- ', $current);
            }
        }
        return $placeNames;
    }

    /**
     * Return an array of associative URL arrays with one or more of the following
     * keys:
     *
     * <li>
     *   <ul>desc: URL description text to display (optional)</ul>
     *   <ul>url: fully-formed URL (required if 'route' is absent)</ul>
     *   <ul>route: VuFind route to build URL with (required if 'url' is absent)</ul>
     *   <ul>routeParams: Parameters for route (optional)</ul>
     *   <ul>queryString: Query params to append after building route (optional)</ul>
     * </li>
     *
     * @return array
     */
    public function getURLs()
    {
        $retVal = [];

        // Which fields/subfields should we check for URLs?
        $fieldsToCheck = [
            '856' => ['3', 'y', 'z'],   // Standard URL
            '555' => ['a']              // Cumulative index/finding aids
        ];

        foreach ($fieldsToCheck as $field => $subfields) {
            $urls = $this->marcRecord->getFields($field);
            if ($urls) {
                foreach ($urls as $url) {
                    // Is there an address in the current field?
                    $address = $url->getSubfield('u');
                    if ($address) {
                        $address = $address->getData();

                        // Is there a description?  If not, just use the URL itself.
                        foreach ($subfields as $current) {
                            $desc = $url->getSubfield($current);
                            if ($current == 'y' && $desc && $desc->getData() == 'c') {
                                $desc = null;
                            }
                            if ($desc) {
                                break;
                            }
                        }
                        if ($desc) {
                            $desc = $desc->getData();
                        } else {
                            $desc = $address;
                        }

                        $uselinks = [ 'Inhaltstext', 'Kurzbeschreibung',
                            'Ausführliche Beschreibung', 'Inhaltsverzeichnis',
                            'Rezension', 'Beschreibung für den Leser',
                            'Autorenbiografie' ];

                        // Take the link, if it has a description defined in $uselinks
                        // or if its a non-numeric (i.e. a non-CBS) match.
                        if (in_array($desc, $uselinks) === true
                            || is_numeric($this->getUniqueId()) === false
                        ) {
                            $retVal[] = ['url' => $address, 'desc' => $desc];
                        }
                    }
                }
            }
        }

        return $retVal;
    }

    /**
     * Get all record links related to the current record. Each link is returned as
     * array.
     * Format:
     * array(
     *        array(
     *               'title' => label_for_title
     *               'value' => link_name
     *               'link'  => link_URI
     *        ),
     *        ...
     * )
     *
     * @return null|array
     */
    public function getAllRecordLinks()
    {
        // Load configurations:
        $fieldsNames = isset($this->mainConfig->Record->marc_links)
            ? explode(',', $this->mainConfig->Record->marc_links) : [];
        $useVisibilityIndicator
            = isset($this->mainConfig->Record->marc_links_use_visibility_indicator)
            ? $this->mainConfig->Record->marc_links_use_visibility_indicator : true;

        $retVal = [];
        foreach ($fieldsNames as $value) {
            $value = trim($value);
            $fields = $this->marcRecord->getFields($value);
            if (!empty($fields)) {
                foreach ($fields as $field) {
                    // Check to see if we should display at all
                    if ($useVisibilityIndicator) {
                        $visibilityIndicator = $field->getIndicator('1');
                        if ($visibilityIndicator == '1') {
                            continue;
                        }
                    }

                    // Get data for field
                    $tmp = $this->getFieldData($field);
                    if (is_array($tmp)) {
                        $retVal[] = $tmp;
                    }
                }
            }
        }
        return empty($retVal) ? null : $retVal;
    }

    /**
     * Support method for getFieldData() -- factor the relationship indicator
     * into the field number where relevant to generate a note to associate
     * with a record link.
     *
     * @param File_MARC_Data_Field $field Field to examine
     *
     * @return string
     */
    protected function getRecordLinkNote($field)
    {
        // Normalize blank relationship indicator to 0:
        $relationshipIndicator = $field->getIndicator('2');
        if ($relationshipIndicator == ' ') {
            $relationshipIndicator = '0';
        }

        // Assign notes based on the relationship type
        $value = $field->getTag();
        switch ($value) {
        case '780':
            if (in_array($relationshipIndicator, range('0', '7'))) {
                $value .= '_' . $relationshipIndicator;
            }
            break;
        case '785':
            if (in_array($relationshipIndicator, range('0', '8'))) {
                $value .= '_' . $relationshipIndicator;
            }
            break;
        }

        return 'note_' . $value;
    }

    /**
     * Returns the array element for the 'getAllRecordLinks' method
     *
     * @param File_MARC_Data_Field $field Field to examine
     *
     * @return array|bool                 Array on success, boolean false if no
     * valid link could be found in the data.
     */
    protected function getFieldData($field)
    {
        // Make sure that there is a t field to be displayed:
        if ($title = $field->getSubfield('t')) {
            $title = $title->getData();
        } else {
            return false;
        }

        $linkTypeSetting = isset($this->mainConfig->Record->marc_links_link_types)
            ? $this->mainConfig->Record->marc_links_link_types
            : 'id,oclc,dlc,isbn,issn,title';
        $linkTypes = explode(',', $linkTypeSetting);
        $linkFields = $field->getSubfields('w');

        // Run through the link types specified in the config.
        // For each type, check field for reference
        // If reference found, exit loop and go straight to end
        // If no reference found, check the next link type instead
        foreach ($linkTypes as $linkType) {
            switch (trim($linkType)){
            case 'oclc':
                foreach ($linkFields as $current) {
                    if ($oclc = $this->getIdFromLinkingField($current, 'OCoLC')) {
                        $link = ['type' => 'oclc', 'value' => $oclc];
                    }
                }
                break;
            case 'dlc':
                foreach ($linkFields as $current) {
                    if ($dlc = $this->getIdFromLinkingField($current, 'DLC', true)) {
                        $link = ['type' => 'dlc', 'value' => $dlc];
                    }
                }
                break;
            case 'id':
                foreach ($linkFields as $current) {
                    if ($bibLink = $this->getIdFromLinkingField($current)) {
                        $link = ['type' => 'bib', 'value' => $bibLink];
                    }
                }
                break;
            case 'isbn':
                if ($isbn = $field->getSubfield('z')) {
                    $link = [
                        'type' => 'isn', 'value' => trim($isbn->getData()),
                        'exclude' => $this->getUniqueId()
                    ];
                }
                break;
            case 'issn':
                if ($issn = $field->getSubfield('x')) {
                    $link = [
                        'type' => 'isn', 'value' => trim($issn->getData()),
                        'exclude' => $this->getUniqueId()
                    ];
                }
                break;
            case 'title':
                $link = ['type' => 'title', 'value' => $title];
                break;
            }
            // Exit loop if we have a link
            if (isset($link)) {
                break;
            }
        }
        // Make sure we have something to display:
        return !isset($link) ? false : [
            'title' => $this->getRecordLinkNote($field),
            'value' => $title,
            'link'  => $link
        ];
    }

    /**
     * Returns an id extracted from the identifier subfield passed in
     *
     * @param \File_MARC_Subfield $idField MARC field containing id information
     * @param string              $prefix  Prefix to search for in id field
     * @param bool                $raw     Return raw match, or normalize?
     *
     * @return string|bool                 ID on success, false on failure
     */
    protected function getIdFromLinkingField($idField, $prefix = null, $raw = false)
    {
        $text = $idField->getData();
        if (preg_match('/\(([^)]+)\)(.+)/', $text, $matches)) {
            // If prefix matches, return ID:
            if ($matches[1] == $prefix) {
                // Special case -- LCCN should not be stripped:
                return $raw
                    ? $matches[2]
                    : trim(str_replace(range('a', 'z'), '', ($matches[2])));
            }
        } else if ($prefix == null) {
            // If no prefix was given or found, we presume it is a raw bib record
            return $text;
        }
        return false;
    }

    /**
     * Get Status/Holdings Information from the internally stored MARC Record
     * (support method used by the NoILS driver).
     *
     * @param array $field The MARC Field to retrieve
     * @param array $data  A keyed array of data to retrieve from subfields
     *
     * @return array
     */
    public function getFormattedMarcDetails($field, $data)
    {
        // Initialize return array
        $matches = [];
        $i = 0;

        // Try to look up the specified field, return empty array if it doesn't
        // exist.
        $fields = $this->marcRecord->getFields($field);
        if (!is_array($fields)) {
            return $matches;
        }

        // Extract all the requested subfields, if applicable.
        foreach ($fields as $currentField) {
            foreach ($data as $key => $info) {
                $split = explode("|", $info);
                if ($split[0] == "msg") {
                    if ($split[1] == "true") {
                        $result = true;
                    } elseif ($split[1] == "false") {
                        $result = false;
                    } else {
                        $result = $split[1];
                    }
                    $matches[$i][$key] = $result;
                } else {
                    // Default to subfield a if nothing is specified.
                    if (count($split) < 2) {
                        $subfields = ['a'];
                    } else {
                        $subfields = str_split($split[1]);
                    }
                    $result = $this->getSubfieldArray(
                        $currentField, $subfields, true
                    );
                    $matches[$i][$key] = count($result) > 0
                        ? (string)$result[0] : '';
                }
            }
            $matches[$i]['id'] = $this->getUniqueID();
            $i++;
        }
        return $matches;
    }

    /**
     * Return an XML representation of the record using the specified format.
     * Return false if the format is unsupported.
     *
     * @param string     $format     Name of format to use (corresponds with OAI-PMH
     * metadataPrefix parameter).
     * @param string     $baseUrl    Base URL of host containing VuFind (optional;
     * may be used to inject record URLs into XML when appropriate).
     * @param RecordLink $recordLink Record link helper (optional; may be used to
     * inject record URLs into XML when appropriate).
     *
     * @return mixed         XML, or false if format unsupported.
     */
    public function getXML($format, $baseUrl = null, $recordLink = null)
    {
        // Special case for MARC:
        if ($format == 'marc21') {
            $xml = $this->marcRecord->toXML();
            $xml = str_replace(
                [chr(27), chr(28), chr(29), chr(30), chr(31)], ' ', $xml
            );
            $xml = simplexml_load_string($xml);
            if (!$xml || !isset($xml->record)) {
                return false;
            }

            // Set up proper namespacing and extract just the <record> tag:
            $xml->record->addAttribute('xmlns', "http://www.loc.gov/MARC21/slim");
            $xml->record->addAttribute(
                'xsi:schemaLocation',
                'http://www.loc.gov/MARC21/slim ' .
                'http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd',
                'http://www.w3.org/2001/XMLSchema-instance'
            );
            $xml->record->addAttribute('type', 'Bibliographic');
            return $xml->record->asXML();
        }

        // Try the parent method:
        return parent::getXML($format, $baseUrl, $recordLink);
    }

    /**
     * determines mylib setting
     *
     * @access  protected
     * @return  string
     */
    protected function getMyLibraryCode()
    {
        $iln = isset($this->recordConfig->Library->iln)
            ? $this->recordConfig->Library->iln : null;
        $mylib = isset($this->recordConfig->Library->mylibId)
            ? $this->recordConfig->Library->mylibId : null;

        if ($mylib === null && $iln !== null) {
            $mylib = "GBV_ILN_".$iln;
        }

        return $mylib;
    }

    /**
     * determines if this item is in the local stock
     *
     * @access protected
     * @return boolean
     */
    public function checkLocalStock()
    {
        // Return null if we have no table of contents:
        $fields = $this->marcRecord->getFields('912');
        if (!$fields) {
            return null;
        }

        $mylib = $this->getMyLibraryCode();

        // If we got this far, we have libraries owning this item -- check if we have it locally
        foreach ($fields as $field) {
            $subfields = $field->getSubfields();
            foreach ($subfields as $subfield) {
                if ($subfield->getCode() === 'a') {
                    if ($subfield->getData() === $mylib) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * determines if this item is in the local stock by checking the index
     *
     * @access protected
     * @return boolean
     */
    public function checkLocalStockInIndex()
    {
        return in_array($this->getMyLibraryCode(), $this->fields['collection_details']);
    }

    /**
     * checks if this item needs interlibrary loan
     *
     * @access protected
     * @return string
     */
    public function checkInterlibraryLoan()
    {
        // Is this item in local stock?
        if ($this->checkLocalStockInIndex() === true) {
            return '0';
        }
        // Is this item an e-ressource?
        if (in_array('eBook', $this->getFormats()) === true || in_array('eJournal', $this->getFormats()) === true || $this->isNLZ() === true) {
            return '0';
        }

        return '1';
    }

    /**
     * checks if this item is licensed
     *
     * @access protected
     * @return boolean
     */
    public function licenseAvailable()
    {
        // Is this item in local stock?
        if ((in_array('eBook', $this->getFormats()) === true || in_array('eJournal', $this->getFormats()) === true || $this->isNLZ() === true) && $this->checkLocalStockInIndex() === true) {
            return true;
        }

        return false;
    }

    /**
     * checks if this item needs to be licensed
     *
     * @access protected
     * @return boolean
     */
    public function needsLicense()
    {
        // Is this item in local stock?
        if ((in_array('eBook', $this->getFormats()) === true || in_array('eJournal', $this->getFormats()) === true) && $this->isNLZ() === false) {
            return true;
        }

        return false;
    }

    /**
     * Attach an ILS connection and related logic to the driver
     *
     * @param \VuFind\ILS\Connection       $ils            ILS connection
     * @param \VuFind\ILS\Logic\Holds      $holdLogic      Hold logic handler
     * @param \VuFind\ILS\Logic\TitleHolds $titleHoldLogic Title hold logic handler
     *
     * @return void
     */
    public function attachILS(\VuFind\ILS\Connection $ils,
        \VuFind\ILS\Logic\Holds $holdLogic,
        \VuFind\ILS\Logic\TitleHolds $titleHoldLogic
    ) {
        $this->ils = $ils;
        $this->holdLogic = $holdLogic;
        $this->titleHoldLogic = $titleHoldLogic;
    }

    /**
     * Do we have an attached ILS connection?
     *
     * @return bool
     */
    protected function hasILS()
    {
        return null !== $this->ils;
    }

    /**
     * Get an array of information about record holdings, obtained in real-time
     * from the ILS.
     *
     * @return array
     */
    public function getRealTimeHoldings()
    {
        return $this->hasILS() ? $this->holdLogic->getHoldings(
            $this->getUniqueID(), $this->getConsortialIDs()
        ) : [];
    }

    /**
     * Get an array of information about record history, obtained in real-time
     * from the ILS.
     *
     * @return array
     */
    public function getRealTimeHistory()
    {
        // Get Acquisitions Data
        if (!$this->hasILS()) {
            return [];
        }
        try {
            return $this->ils->getPurchaseHistory($this->getUniqueID());
        } catch (ILSException $e) {
            return [];
        }
    }

    /**
     * Get a link for placing a title level hold.
     *
     * @return mixed A url if a hold is possible, boolean false if not
     */
    public function getRealTimeTitleHold()
    {
        if ($this->hasILS()) {
            $biblioLevel = strtolower($this->getBibliographicLevel());
            if ("monograph" == $biblioLevel || strstr("part", $biblioLevel)) {
                if ($this->ils->getTitleHoldsMode() != "disabled") {
                    return $this->titleHoldLogic->getHold($this->getUniqueID());
                }
            }
        }

        return false;
    }

    /**
     * Returns true if the record supports real-time AJAX status lookups.
     *
     * @return bool
     */
    public function supportsAjaxStatus()
    {
        return true;
    }

    /**
     * Get access to the raw File_MARC object.
     *
     * @return File_MARCBASE
     */
    public function getMarcRecord()
    {
        return $this->marcRecord;
    }

    /**
     * Get an XML RDF representation of the data in this record.
     *
     * @return mixed XML RDF data (empty if unsupported or error).
     */
    public function getRDFXML()
    {
        return XSLTProcessor::process(
            'record-rdf-mods.xsl', trim($this->marcRecord->toXML())
        );
    }

    /**
     * Return the list of "source records" for this consortial record.
     *
     * @return array
     */
    public function getConsortialIDs()
    {
        return $this->getFieldArray('035', 'a', true);
    }

    /**
     * Get multipart children.
     *
     * @return array
     * @access protected
     */
    public function getMultipartChildren()
    {
        $cnt=0;
        $retval = array();
        $sort = array();
        $result = $this->searchMultipart();

        // Sort the results
        foreach($result as $doc) {
            $retval[$cnt] = array();
            $part = $doc->getVolumeInformation();
            //$retval[$cnt]['sort']=$doc['sort'];
            $retval[$cnt]['title'] = $doc->getTitle()[0];
            //$retval[$cnt]['id']=$doc['id'];
            $retval[$cnt]['date'] = preg_replace("/[^0-9]/","", $doc->getPublicationDates()[0]);
            $retval[$cnt]['part'] = $part;
            $retval[$cnt]['partNum'] = preg_replace("/[^0-9]/","", $part);
            $retval[$cnt]['object'] = $doc;
            $cnt++;
        }

        $part0 = array();
        $part1 = array();
        $part2 = array();

        foreach ($retval as $key => $row) {
            $part0[$key] = (isset($row['title'])) ? $row['title'] : 0;
            $part1[$key] = (isset($row['partNum'])) ? $row['partNum'] : 0;
            $part2[$key] = (isset($row['date'])) ? $row['date'] : 0;
        }
        array_multisort($part1, SORT_ASC, $part2, SORT_DESC, $part0, SORT_ASC, $retval );

        // $retval has now the correct order, now set the objects into the same order
        $returnObjects = array();
        foreach ($retval as $object) {
            $returnObjects[] = $object['object'];
        }

        return $returnObjects;
    }

    /**
     * Search for multipart records of this record
     *
     * @return bool
     * @access protected
     */
    protected function searchMultipart()
    {
        $limit = 2;
        $rid=$this->fields['id'];
        if(strlen($rid)<2) {
            return false;
        }
        $rid=str_replace(":","\:",$rid);

        // Assemble the query parts and filter out current record:
        $searchQ = '(ppnlink:'.$this->stripNLZ($rid).' AND NOT (format:Article OR format:"electronic Article"))';

        $hiddenFilters = null;
        // Get filters from config file
        if (isset($this->recordConfig->Filter->hiddenFilters)) {
            $hiddenFilters = $this->recordConfig->Filter->hiddenFilters->toArray();
        }

        $query = new \VuFindSearch\Query\Query($searchQ);
        $params = new ParamBag();
        $params->set('fq', $hiddenFilters);

        $all = $this->searchService->search('Solr', $query, 0, 0, $params)->getTotal();
        $results = $this->searchService->search('Solr', $query, 0, $all, $params);

        $frbrItems = $this->searchFRBRitems();
        $frbrItemIds = [ ];
        if (count($frbrItems) > 0) {
            foreach ($frbrItems as $frbrItem) {
                $frbrItemIds[] = $frbrItem->getUniqueId();
            }
        }
        $return = [ ];
        if (count($results) > 0) {
            foreach ($results as $result) {
                if (in_array($result->getUniqueId(), $frbrItemIds) === false) {
                    $return[] = $result;
                }
            }
        }
        else {
            $return = $results;
        }

        return $return;
    }

    /**
     * Check if at least one volume for this item exists.
     * Used to detect wheter or not the volume tab needs to be displayed
     *
     * @return bool
     * @access public
     */
    public function isMultipartChildren()
    {
        $limit = 2;
        $rid=$this->fields['id'];
        if(strlen($rid)<2) {
            return false;
        }
        $rid=str_replace(":","\:",$rid);

        // Assemble the query parts and filter out current record:
        $searchQ = '(ppnlink:'.$this->stripNLZ($rid).' AND NOT (format:Article OR format:"electronic Article"))';

        $hiddenFilters = null;
        // Get filters from config file
        if (isset($this->recordConfig->Filter->hiddenFilters)) {
            $hiddenFilters = $this->recordConfig->Filter->hiddenFilters->toArray();
        }

        $query = new \VuFindSearch\Query\Query($searchQ);
        $params = new ParamBag();
        $params->set('fq', $hiddenFilters);

        $all = $this->searchService->search('Solr', $query, 0, 0, $params)->getTotal();

        // Assemble the query parts and filter out current record:
        $searchQFRBR = '(ppnlink:'.$this->stripNLZ($rid).' AND NOT (format:Article OR format:"electronic Article")';
        if ($this->fields['remote_bool'] == 'true') {
            $searchQFRBR .= ' AND remote_bool:false';
        }
        else {
            $searchQFRBR .= ' AND remote_bool:true';
        }
        $searchQFRBR .= ')';

        $queryFRBR = new \VuFindSearch\Query\Query($searchQFRBR);
        $paramsFRBR = new ParamBag();
        $paramsFRBR->set('fq', $hiddenFilters);
        $allFRBR = $this->searchService->search('Solr', $queryFRBR, 0, 0, $paramsFRBR)->getTotal();

        $count = ($all-$allFRBR);

        if ($count > 0) {
            return true;
        }

        return false;
    }

    /**
     * Search for FRBR items of this item.
     *
     * @return array
     * @access public
     */
    public function searchFRBRitems()
    {
        $rid=$this->fields['id'];
        if(strlen($rid)<2) {
            return array();
        }
        $rid=str_replace(":","\:",$rid);

        // Assemble the query parts and filter out current record:
        $searchQ = '(ppnlink:'.$this->stripNLZ($rid).' AND NOT (format:Article OR format:"electronic Article")';
        if ($this->fields['remote_bool'] == 'true') {
            $searchQ .= ' AND remote_bool:false';
        }
        else {
            $searchQ .= ' AND remote_bool:true';
        }
        $searchQ .= ')';
//echo $searchQ;
        $hiddenFilters = null;
        // Get filters from config file
        if (isset($this->recordConfig->Filter->hiddenFilters)) {
            $hiddenFilters = $this->recordConfig->Filter->hiddenFilters->toArray();
        }

        $query = new \VuFindSearch\Query\Query($searchQ);
        $params = new ParamBag();
        $params->set('fq', $hiddenFilters);

        $all = $this->searchService->search('Solr', $query, 0, 0, $params)->getTotal();
        $results = $this->searchService->search('Solr', $query, 0, $all, $params);

        return $results;
/*
        foreach ($result['response']['docs'] as $resp) {
            if (($this->_isNLZ($resp['id']) && $this->_isNLZ($rid)) || (!$this->_isNLZ($resp['id']) && !$this->_isNLZ($rid))) {
                $resultArray['response']['docs'][] = $resp;
            }
        }

        return (count($resultArray['response']['docs']) > 0) ? $resultArray['response'] : false;
*/
    }

    /**
     * Returns one of three things: a full URL to a thumbnail preview of the record
     * if an image is available in an external system; an array of parameters to
     * send to VuFind's internal cover generator if no fixed URL exists; or false
     * if no thumbnail can be generated.
     *
     * @param string $size Size of thumbnail (small, medium or large -- small is
     * default).
     *
     * @return string|array|bool
     */
    public function getThumbnail($size = 'small')
    {
        if (isset($this->fields['thumbnail']) && $this->fields['thumbnail']) {
            return $this->fields['thumbnail'];
        }
        $arr = [
            'author'     => mb_substr($this->getPrimaryAuthor(), 0, 300, 'utf-8'),
            'callnumber' => $this->getCallNumber(),
            'size'       => $size,
            'title'      => mb_substr($this->getTitle(), 0, 300, 'utf-8')
        ];
        if ($isbn = $this->getCleanISBN()) {
            $arr['isbn'] = $isbn;
        }
        if ($issn = $this->getCleanISSN()) {
            $arr['issn'] = $issn;
        }
        if ($oclc = $this->getCleanOCLCNum()) {
            $arr['oclc'] = $oclc;
        }
        if ($upc = $this->getCleanUPC()) {
            $arr['upc'] = $upc;
        }
        if ($ppn = $this->getUniqueId()) {
            $arr['ppn'] = $ppn;
        }
        // If an ILS driver has injected extra details, check for IDs in there
        // to fill gaps:
        if ($ilsDetails = $this->getExtraDetail('ils_details')) {
            foreach (['isbn', 'issn', 'oclc', 'upc'] as $key) {
                if (!isset($arr[$key]) && isset($ilsDetails[$key])) {
                    $arr[$key] = $ilsDetails[$key];
                }
            }
        }
        return $arr;
    }

/* deprecated
    public function searchMultipartChildren()
    {
        $result = $this->searchMultipart();
        return $result;
        //return ($result['docs'] > 0) ? $result['docs'] : false;
    }
*/
    public function searchArticleChildren()
    {
        $result = $this->searchArticles();

        return ($result['docs'] > 0) ? $result['docs'] : false;
    }

    /**
     * Get the content of MARC field 246
     *
     * @return array
     * @access protected
     */
    public function getSubseries() {
        return array('label' => $this->getFieldArray('246', ['i']), 'value' => $this->getFieldArray('246', ['a']));
    }

    public function getHss() {
        return $this->getFirstFieldValue('502');
    }

    public function getEditionsFromMarc() {
        return $this->getFieldArray('250');
    }

    public function getMoreContributors() {
        return array('names' => $this->getFieldArray('700', ['a']), 'functions' => $this->getFieldArray('700', ['e']));
    }

    public function getVolumeInformation() {
        if ($this->getFirstFieldValue('800', ['v'])) {
            return $this->getFirstFieldValue('800', ['v']);
        }
        if ($this->getFirstFieldValue('830', ['v'])) {
            return $this->getFirstFieldValue('830', ['v']);
        }
        if ($this->getFirstFieldValue('245', ['n'])) {
            return $this->getFirstFieldValue('245', ['n']);
        }
        return null;
    }

    /**
     * Get an array of information about record holdings, obtained in real-time
     * from the ILS.
     *
     * @return array
     * @access protected
     */
/* deprecated
    protected function getTomes()
    {
        $result = $this->searchMultipartChildren();

        return $result;

        //$picaConfigArray = parse_ini_file('conf/PICA.ini', true);
        //$record_url = $picaConfigArray['Catalog']['ppnUrl'];

        $onlyTopLevel = 0;
        $checkMore = false;
        $showAssociated = false;
        $leader = $this->marcRecord->getLeader();
        $indicator = substr($leader, 19, 1);
        switch ($indicator) {
            case 'a':
                $checkMore = 0;
                $showAssociated = 1;
                break;
            case 'c':
                $onlyTopLevel = 1;
                $showAssociated = 2;
                break;
            case 'b':
            case ' ':
            default:
                //$checkMore = 0;
                $showAssociated = 0;
                break;
        }
        if ($checkMore !== 0) {
            $journalIndicator = substr($leader, 7, 1);
            switch ($journalIndicator) {
                case 's':
                    $showAssociated = 1;
                    break;
                case 'b':
                case 'm':
                    //$onlyTopLevel = 1;
                    $showAssociated = 3;
                    break;
            }
        }
        if ($onlyTopLevel === 1) {
            // only look for the parent of this record, all other associated publications can be ignored
            $vs = $this->marcRecord->getFields('773');
            if ($vs) {
                foreach($vs as $v) {
                    $a_names = $v->getSubfields('w');
                    if (count($a_names) > 0) {
                        $idArr = explode(')', $a_names[0]->getData());
                        $parentId = $idArr[1];
                    }
                    $v_names = $v->getSubfields('v');
                    if (count($v_names) > 0) {
                        $volNumber = $v_names[0]->getData();
                    }
                }
            }
            if (!$parentId) {
                $vs = $this->marcRecord->getFields('830');
                if ($vs) {
                    foreach($vs as $v) {
                        $a_names = $v->getSubfields('w');
                        if (count($a_names) > 0) {
                            $idArr = explode(')', $a_names[0]->getData());
                            if ($idArr[0] === '(DE-601') {
                                $parentId = $idArr[1];
                            }
                        }
                        $v_names = $v->getSubfields('v');
                        if (count($v_names) > 0) {
                            $volNumber = $v_names[0]->getData();
                        }
                    }
                }
                else {
                    $vs = $this->marcRecord->getFields('800');
                    if ($vs) {
                        foreach($vs as $v) {
                            $a_names = $v->getSubfields('w');
                            if (count($a_names) > 0) {
                                $idArr = explode(')', $a_names[0]->getData());
                                if ($idArr[0] === '(DE-601') {
                                    $parentId = $idArr[1];
                                }
                            }
                            $v_names = $v->getSubfields('v');
                            if (count($v_names) > 0) {
                                $volNumber = $v_names[0]->getData();
                            }
                        }
                    }
                }
            }

            $subrecord = array('id' => $parentId);
            $subrecord['number'] = $volNumber;
            $subrecord['title_full'] = array();
            $subrecord['record_url'] = $record_url.$parentId;
*/
/*
            $m = trim($subr['fullrecord']);
            // check if we are dealing with MARCXML
            $xmlHead = '<?xml version';
            if (strcasecmp(substr($m, 0, strlen($xmlHead)), $xmlHead) === 0) {
                $m = new File_MARCXML($m, File_MARCXML::SOURCE_STRING);
            } else {
                $m = preg_replace('/#31;/', "\x1F", $m);
                $m = preg_replace('/#30;/', "\x1E", $m);
                $m = new File_MARC($m, File_MARC::SOURCE_STRING);
            }
            $marcRecord = $m->next();
            if (is_a($marcRecord, 'File_MARC_Record') === true || is_a($marcRecord, 'File_MARCXML_Record') === true) {
                $vs = $marcRecord->getFields('245');
                if ($vs) {
                    foreach($vs as $v) {
                        $a_names = $v->getSubfields('a');
                        if (count($a_names) > 0) {
                            $subrecord['title_full'][] = " ".$a_names[0]->getData();
                        }
                    }
                }
            }
*/
/*
            if (!$parentId) {
                $showAssociated = 0;
            }
            $subrecords[] = $subrecord;
            //print_r($subrecord);
            $parentRecord = $subrecord;
            return $subrecords;
        }

        // Get Holdings Data
//        $id = $this->getUniqueID();

    }
*/
    /**
     * Get an array of information about record holdings, obtained in real-time
     * from the ILS.
     *
     * @return array
     * @access protected
     */
    protected function getArticles()
    {
        global $configArray, $interface;
        // only get associted volumes if this is a top level journal
        $class = $configArray['Index']['engine'];
        $url = $configArray['Index']['url'];
        $this->db = new $class($url);
        $picaConfigArray = parse_ini_file('conf/PICA.ini', true);
        $record_url = $picaConfigArray['Catalog']['ppnUrl'];
        
        $onlyTopLevel = 0;
        $leader = $this->marcRecord->getLeader();
        $indicator = substr($leader, 19, 1);
        switch ($indicator) {
            case 'a':
                $checkMore = 0;
                $interface->assign('showAssociated', '1');
                break;
            case 'c':
                $onlyTopLevel = 1;
                $interface->assign('showAssociated', '2');
                break;
            case 'b':
            case ' ':
            default:
                //$checkMore = 0;
                $interface->assign('showAssociated', '0');
                break;
        }
        if ($checkMore !== 0) {
        $journalIndicator = substr($leader, 7, 1);
        switch ($journalIndicator) {
            case 's':
                $interface->assign('showAssociated', '1');
                break;
            case 'b':
            case 'm':
                #$onlyTopLevel = 1;
                $interface->assign('showAssociated', '3');
                break;
        }
        }
        if ($onlyTopLevel === 1) {
            // only look for the parent of this record, all other associated publications can be ignored
            $vs = $this->marcRecord->getFields('773');
            if ($vs) {
                foreach($vs as $v) {
                    $a_names = $v->getSubfields('w');
                    if (count($a_names) > 0) {
                        $idArr = explode(')', $a_names[0]->getData());
                        $parentId = $idArr[1];
                    }
                    $v_names = $v->getSubfields('v');
                    if (count($v_names) > 0) {
                        $volNumber = $v_names[0]->getData();
                    }
                }
            }
            if (!$parentId) {
                $vs = $this->marcRecord->getFields('830');
                if ($vs) {
                    foreach($vs as $v) {
                        $a_names = $v->getSubfields('w');
                        if (count($a_names) > 0) {
                            $idArr = explode(')', $a_names[0]->getData());
                            if ($idArr[0] === '(DE-601') {
                                $parentId = $idArr[1];
                            }
                        }
                        $v_names = $v->getSubfields('v');
                        if (count($v_names) > 0 && $parentId === $id) {
                            $volNumber = $v_names[0]->getData();
                        }
                    }
                }
                else {
                    $vs = $this->marcRecord->getFields('800');
                    if ($vs) {
                        foreach($vs as $v) {
                            $a_names = $v->getSubfields('w');
                            if (count($a_names) > 0) {
                                $idArr = explode(')', $a_names[0]->getData());
                                if ($idArr[0] === '(DE-601') {
                                    $parentId = $idArr[1];
                                }
                            }
                            $v_names = $v->getSubfields('v');
                            if (count($v_names) > 0 && $parentId === $id) {
                                $volNumber = $v_names[0]->getData();
                            }
                        }
                    }
                }
            }
            $subr = $this->db->getRecord($parentId);
            $subrecord = array('id' => $parentId);
            $subrecord['number'] = $volNumber;
            $subrecord['title_full'] = array();
            if (!$subr) {
                $subrecord['record_url'] = $record_url.$parentId;
            }
            $m = trim($subr['fullrecord']);
            // check if we are dealing with MARCXML
            $xmlHead = '<?xml version';
            if (strcasecmp(substr($m, 0, strlen($xmlHead)), $xmlHead) === 0) {
                $m = new File_MARCXML($m, File_MARCXML::SOURCE_STRING);
            } else {
                $m = preg_replace('/#31;/', "\x1F", $m);
                $m = preg_replace('/#30;/', "\x1E", $m);
                $m = new File_MARC($m, File_MARC::SOURCE_STRING);
            }
            $marcRecord = $m->next();
            if (is_a($marcRecord, 'File_MARC_Record') === true || is_a($marcRecord, 'File_MARCXML_Record') === true) {
                $vs = $marcRecord->getFields('245');
                if ($vs) {
                    foreach($vs as $v) {
                        $a_names = $v->getSubfields('a');
                        if (count($a_names) > 0) {
                            $subrecord['title_full'][] = " ".$a_names[0]->getData();
                        }
                    }
                }
            }
            if (!$parentId) {
                $interface->assign('showAssociated', '0');
            }
            $subrecords[] = $subrecord;
            $interface->assign('parentRecord', $subrecord);
            return $subrecords;
        }
        // Get Holdings Data
        $id = $this->getUniqueID();
        #$catalog = ConnectionManager::connectToCatalog();
        #if ($catalog && $catalog->status) {
            #$result = $this->db->getRecordsByPPNLink($id);
            $result = $this->searchArticleChildren();
            #$result = $catalog->getJournalHoldings($id);
            if (PEAR::isError($result)) {
                PEAR::raiseError($result);
            }

            foreach ($result as $subId) {
                /*if (!($subrecord = $this->db->getRecord($subId))) {
                    $subrecord = array('id' => $subId, 'title_full' => array("Title not found"), 'record_url' => $record_url.$subId);
                }*/

                $subr = $subId;
                $subrecord = array('id' => $subId['id']);
                $subrecord['title_full'] = array();
                $subrecord['publishDate'] = array();
                if (!$subr) {
                    $subrecord['record_url'] = $record_url.$subId;
                }
                $m = trim($subr['fullrecord']);
                // check if we are dealing with MARCXML
                $xmlHead = '<?xml version';
                if (strcasecmp(substr($m, 0, strlen($xmlHead)), $xmlHead) === 0) {
                    $m = new File_MARCXML($m, File_MARCXML::SOURCE_STRING);
                } else {
                    $m = preg_replace('/#31;/', "\x1F", $m);
                    $m = preg_replace('/#30;/', "\x1E", $m);
                    $m = new File_MARC($m, File_MARC::SOURCE_STRING);
                }
                $marcRecord = $m->next();
                if (is_a($marcRecord, 'File_MARC_Record') === true || is_a($marcRecord, 'File_MARCXML_Record') === true) {
                // 800$t$v -> 773$q -> 830$v -> 245$a$b -> "Title not found"
                    $leader = $marcRecord->getLeader();
                    $indicator = substr($leader, 19, 1);
                    $journalIndicator = substr($leader, 7, 1);
                    switch ($indicator) {
                        case 'a':
                            $vs = $marcRecord->getFields('245');
                            if ($vs) {
                                foreach($vs as $v) {
                                    $a_names = $v->getSubfields('a');
                                    if (count($a_names) > 0) {
                                        $subrecord['title_full'][] = " ".$a_names[0]->getData();
                                    }
                                }
                            }
                            unset($vs);
                            $vs = $marcRecord->getFields('260');
                            if ($vs) {
                                foreach($vs as $v) {
                                    $a_names = $v->getSubfields('c');
                                    if (count($a_names) > 0) {
                                        $subrecord['publishDate'][0] = " ".$a_names[0]->getData();
                                    }
                                }
                            }
                            unset($vs);
                            $vs = $marcRecord->getFields('800');
                            $thisHasBeenSet = 0;
                            if ($vs) {
                                foreach($vs as $v) {
                                    $a_names = $v->getSubfields('w');
                                    if (count($a_names) > 0) {
                                        $idArr = explode(')', $a_names[0]->getData());
                                        if ($idArr[0] === '(DE-601') {
                                            $parentId = $idArr[1];
                                        }
                                    }
                                    $v_names = $v->getSubfields('v');
                                    if (count($v_names) > 0 && $parentId === $id) {
                                        $subrecord['volume'] = $v_names[0]->getData();
                                        $thisHasBeenSet = 1;
                                    }
                                }
                            }
                            if ($thisHasBeenSet === 0) {
                                $vs = $marcRecord->getFields('830');
                                if ($vs) {
                                    foreach($vs as $v) {
                                        $a_names = $v->getSubfields('w');
                                        if (count($a_names) > 0) {
                                            $idArr = explode(')', $a_names[0]->getData());
                                            if ($idArr[0] === '(DE-601') {
                                                $parentId = $idArr[1];
                                            }
                                        }
                                        $v_names = $v->getSubfields('v');
                                        if (count($v_names) > 0 && $parentId === $id) {
                                            $subrecord['volume'] = $v_names[0]->getData();
                                        }
                                        $e_names = $v->getSubfields('9');
                                        if (count($e_names) > 0 && $parentId === $id) {
                                            $subrecord['sort'] = $e_names[0]->getData();
                                        }
                                    }
                                }
                            }
                            break;
                        case 'b':
                            $vs = $marcRecord->getFields('800');
                            $thisHasBeenSet = 0;
                            if ($vs) {
                                foreach($vs as $v) {
                                    $a_names = $v->getSubfields('w');
                                    if (count($a_names) > 0) {
                                        $idArr = explode(')', $a_names[0]->getData());
                                        if ($idArr[0] === '(DE-601') {
                                            $parentId = $idArr[1];
                                        }
                                    }
                                    $v_names = $v->getSubfields('v');
                                    if (count($v_names) > 0 && $parentId === $id) {
                                        $subrecord['volume'] = $v_names[0]->getData();
                                        $thisHasBeenSet = 1;
                                    }
                                }
                            }
                            if ($thisHasBeenSet === 0) {
                                $vs = $marcRecord->getFields('830');
                                if ($vs) {
                                    foreach($vs as $v) {
                                        $a_names = $v->getSubfields('w');
                                        if (count($a_names) > 0) {
                                            $idArr = explode(')', $a_names[0]->getData());
                                            if ($idArr[0] === '(DE-601') {
                                                $parentId = $idArr[1];
                                            }
                                        }
                                        $v_names = $v->getSubfields('v');
                                        if (count($v_names) > 0 && $parentId === $id) {
                                            $subrecord['volume'] = $v_names[0]->getData();
                                        }
                                        $e_names = $v->getSubfields('9');
                                        if (count($e_names) > 0 && $parentId === $id) {
                                            $subrecord['sort'] = $e_names[0]->getData();
                                        }
                                    }
                                }
                            }
                            unset($vs);
                            $vs = $marcRecord->getFields('245');
                            if ($vs) {
                                foreach($vs as $v) {
                                    $a_names = $v->getSubfields('a');
                                    if (count($a_names) > 0) {
                                        $subrecord['title_full'][0] .= " ".$a_names[0]->getData();
                                    }
                                }
                            }
                            unset($vs);
                            $vs = $marcRecord->getFields('250');
                            if ($vs) {
                                foreach($vs as $v) {
                                    $a_names = $v->getSubfields('a');
                                    if (count($a_names) > 0) {
                                        $subrecord['title_full'][0] .= " ".$a_names[0]->getData();
                                    }
                                }
                            }
                            unset($vs);
                            $vs = $marcRecord->getFields('260');
                            if ($vs) {
                                foreach($vs as $v) {
                                    $a_names = $v->getSubfields('c');
                                    if (count($a_names) > 0) {
                                        $subrecord['publishDate'][0] = " ".$a_names[0]->getData();
                                    }
                                }
                            }
                            /*
                            $ves = $marcRecord->getFields('900');
                            if ($ves) {
                                foreach($ves as $ve) {
                                    $libArr = $ve->getSubfields('b');
                                    $lib = $libArr[0]->getData();
                                    if ($lib === 'TUB Hamburg <830>') {
                                        // Is there an address in the current field?
                                        $ve_names = $ve->getSubfields('c');
                                        if (count($ve_names) > 0) {
                                            foreach($ve_names as $ve_name) {
                                                $subrecord['title_full'][] = $ve_name->getData();
                                            }
                                        }
                                    }
                                }
                            }
                            */
                            break;
                        case 'c':
                            $vs = $marcRecord->getFields('773');
                            if ($vs) {
                                foreach($vs as $v) {
                                    $q_names = $v->getSubfields('q');
                                    if ($q_names[0]) {
                                        $subrecord['title_full'][] = $q_names[0]->getData();
                                    }
                                }
                            }
                            unset($vs);
                            $vs = $marcRecord->getFields('260');
                            if ($vs) {
                                foreach($vs as $v) {
                                    $a_names = $v->getSubfields('c');
                                    if (count($a_names) > 0) {
                                        $subrecord['publishDate'][0] = " ".$a_names[0]->getData();
                                    }
                                }
                            }
                            unset($vs);
                            $vs = $marcRecord->getFields('800');
                            $thisHasBeenSet = 0;
                            if ($vs) {
                                foreach($vs as $v) {
                                    $a_names = $v->getSubfields('w');
                                    if (count($a_names) > 0) {
                                        $idArr = explode(')', $a_names[0]->getData());
                                        if ($idArr[0] === '(DE-601') {
                                            $parentId = $idArr[1];
                                        }
                                    }
                                    $v_names = $v->getSubfields('v');
                                    if (count($v_names) > 0 && $parentId === $id) {
                                        $subrecord['volume'] = $v_names[0]->getData();
                                        $thisHasBeenSet = 1;
                                    }
                                }
                            }
                            if ($thisHasBeenSet === 0) {
                                $vs = $marcRecord->getFields('830');
                                if ($vs) {
                                    foreach($vs as $v) {
                                        $a_names = $v->getSubfields('w');
                                        if (count($a_names) > 0) {
                                            $idArr = explode(')', $a_names[0]->getData());
                                            if ($idArr[0] === '(DE-601') {
                                                $parentId = $idArr[1];
                                            }
                                        }
                                        $v_names = $v->getSubfields('v');
                                        if (count($v_names) > 0 && $parentId === $id) {
                                            $subrecord['volume'] = $v_names[0]->getData();
                                        }
                                        $e_names = $v->getSubfields('9');
                                        if (count($e_names) > 0 && $parentId === $id) {
                                            $subrecord['sort'] = $e_names[0]->getData();
                                        }
                                    }
                                }
                            }
                            break;
                        case ' ':
                        default:
                            $vs = $marcRecord->getFields('830');
                            if ($vs) {
                                foreach($vs as $v) {
                                    $a_names = $v->getSubfields('w');
                                    if (count($a_names) > 0) {
                                        $idArr = explode(')', $a_names[0]->getData());
                                        if ($idArr[0] === '(DE-601') {
                                            $parentId = $idArr[1];
                                        }
                                    }
                                    $v_names = $v->getSubfields('v');
                                    if (count($v_names) > 0 && $parentId === $id) {
                                        $subrecord['volume'] = $v_names[0]->getData();
                                    }
                                    $e_names = $v->getSubfields('9');
                                    if (count($e_names) > 0 && $parentId === $id) {
                                        $subrecord['sort'] = $e_names[0]->getData();
                                    }
                                }
                            }
                            if (count($subrecord['title_full']) === 0 || $journalIndicator === 'm' || $journalIndicator === 's') {
                                unset($vs);
                                $vs = $marcRecord->getFields('245');
                                if ($vs) {
                                    foreach($vs as $v) {
                                        $a_names = $v->getSubfields('a');
                                        if (count($a_names) > 0) {
                                            $subrecord['title_full'][0] .= " ".$a_names[0]->getData();
                                        }
                                    }
                                }
                                unset($vs);
                                $vs = $marcRecord->getFields('250');
                                if ($vs) {
                                    foreach($vs as $v) {
                                        $a_names = $v->getSubfields('a');
                                        if (count($a_names) > 0) {
                                            $subrecord['title_full'][0] .= " ".$a_names[0]->getData();
                                        }
                                    }
                                }
                                /*
                                unset($vs);
                                if ($journalIndicator === 's') {
                                    $vs = $marcRecord->getFields('362');
                                    if ($vs) {
                                        foreach($vs as $v) {
                                            $a_names = $v->getSubfields('a');
                                            if (count($a_names) > 0) {
                                                $subrecord['title_full'][0] .= " ".$a_names[0]->getData();
                                            }
                                        }
                                    }
                                }
                                else {
                                    $vs = $marcRecord->getFields('260');
                                    if ($vs) {
                                        foreach($vs as $v) {
                                            $a_names = $v->getSubfields('c');
                                            if (count($a_names) > 0) {
                                                $subrecord['title_full'][0] .= " ".$a_names[0]->getData();
                                            }
                                        }
                                    }
                                }
                                */
                            }
                            unset($vs);
                            $vs = $marcRecord->getFields('260');
                            if ($vs) {
                                foreach($vs as $v) {
                                    $a_names = $v->getSubfields('c');
                                    if (count($a_names) > 0) {
                                        $subrecord['publishDate'][0] = " ".$a_names[0]->getData();
                                    }
                                }
                            }
                            break;
                    }
                }
                $afr = $marcRecord->getFields('952');
                if ($afr) {
                    foreach($afr as $articlefieldedref) {
                        $a_names = $articlefieldedref->getSubfields('d');
                        if (count($a_names) > 0) {
                            $subrecord['volume'] = $a_names[0]->getData();
                        }
                        $e_names = $articlefieldedref->getSubfields('e');
                        if (count($e_names) > 0) {
                            $subrecord['issue'] = $e_names[0]->getData();
                        }
                        $h_names = $articlefieldedref->getSubfields('h');
                        if (count($h_names) > 0) {
                            $subrecord['pages'] = $h_names[0]->getData();
                        }
                        $j_names = $articlefieldedref->getSubfields('j');
                        if (count($j_names) > 0) {
                            $subrecord['publishDate'][] = $j_names[0]->getData();
                        }
                    }
                }
                if (count($subrecord['title_full']) === 0) {
                    $subrecord['title_full'][] = '';
                }

                $subrecords[] = $subrecord;
            }
            #print_r($subrecords);
            return $subrecords;
        #}
    }

    /**
     * Get the ID of a record without NLZ prefix
     *
     * @return string ID without NLZ-prefix (if this is an NLZ record)
     * @access protected
     */
    protected function stripNLZ($rid = false) {
        if ($rid === false) $rid = $this->fields['id'];
        // if this is a national licence record, strip NLZ prefix since this is not indexed as ppnlink
        if (substr($this->fields['id'], 0, 3) === 'NLZ' || substr($this->fields['id'], 0, 3) === 'NLM') {
            $rid = substr($rid, 3);
        }
        return $rid;
    }

    /**
     * Get the ID of a record with NLZ prefix, if this is appropriate
     *
     * @return string ID with NLZ-prefix (if this is an NLZ record)
     * @access protected
     */
    protected function addNLZ($rid = false) {
        if ($rid === false) $rid = $this->fields['id'];
        $prefix = '';
        if (substr($this->fields['id'], 0, 3) === 'NLZ') {
            $prefix = 'NLZ';
        }
        if (substr($this->fields['id'], 0, 3) === 'NLM') {
            $prefix = 'NLM';
        }
        return $prefix.$rid;
    }

    /**
     * Determine if we have a national license hit
     *
     * @return boolean is this a national license hit?
     * @access protected
     */
    protected function isNLZ() {
        return ($this->_isNLZ($this->fields['id']));
    }

    /**
     * Determine if we have a national license hit
     *
     * @return boolean is this a national license hit?
     * @access protected
     */
    private function _isNLZ($id) {
        if (substr($id, 0, 3) === 'NLZ' || substr($id, 0, 3) === 'NLM') {
            return true;
        }
        return false;
    }

    /**
     * Get an array of all series names containing the record.  Array entries may
     * be either the name string, or an associative array with 'name' and 'number'
     * keys.
     *
     * @return array
     * @access protected
     */
    public function getSeriesShort()
    {
        $matches = array();

        // First check the 440, 800 and 830 fields for series information:
        $primaryFields = array(
            '440' => array('a', 'p'),
            '800' => array('a', 'b', 'c', 'd', 'f', 'p', 'q', 't'),
            '830' => array('a', 'p'));
        $matches = $this->getSeriesFromMARC($primaryFields);

        return $matches;
    }

    public function getVolumeName($record = null) {
        if ($this->getFirstFieldValue('245', array('p'))) return array($this->getFirstFieldValue('245', array('p')));
        return null;
    }

    public function getDateSpan() {
        $spanArray = parent::getDateSpan();
        $span = implode(' ', $spanArray);
        return($span);
    }

    public function getSeriesLink()
    {
        $parentIds = array();
        $onlyTopLevel = 0;
        $leader = $this->marcRecord->getLeader();
        $indicator = substr($leader, 19, 1);
        switch ($indicator) {
            case 'a':
                $checkMore = 0;
                $parentIds['showAssociated'] = '1';
                break;
            case 'c':
                $onlyTopLevel = 1;
                $parentIds['showAssociated'] = '2';
                break;
            case 'b':
            case ' ':
            default:
                //$checkMore = 0;
                $parentIds['showAssociated'] = '0';
                break;
        }
        if ($checkMore !== 0) {
        $journalIndicator = substr($leader, 7, 1);
        switch ($journalIndicator) {
            case 's':
                $parentIds['showAssociated'] = '1';
                break;
            case 'b':
            case 'm':
                #$onlyTopLevel = 1;
                $parentIds['showAssociated'] = '3';
                break;
        }
        }
        $onlyTopLevel = 1;
        $parentIds['ids'] = array();
        $volNumber = array();
        if ($onlyTopLevel === 1) {
            // only look for the parent of this record, all other associated publications can be ignored
            $vs = $this->marcRecord->getFields('773');
            if ($vs) {
                foreach($vs as $v) {
                    $a_names = $v->getSubfields('w');
                    if (count($a_names) > 0) {
                        $idArr = explode(')', $a_names[0]->getData());
                        $parentIds['ids'][] = $this->addNLZ($idArr[1]);
                    }
                    $v_names = $v->getSubfields('v');
                    if (count($v_names) > 0) {
                        $volNumber[$idArr[1]] = $v_names[0]->getData();
                    }
                }
            }
            if (count($parentIds['ids']) === 0) {
                $vs = $this->marcRecord->getFields('830');
                $eighthundred = $this->marcRecord->getFields('800');
                $eighthundredten = $this->marcRecord->getFields('810');
                if ($vs) {
                    foreach($vs as $v) {
                        $a_names = $v->getSubfields('w');
                        if (count($a_names) > 0) {
                            $idArr = explode(')', $a_names[0]->getData());
                            if ($idArr[0] === '(DE-601') {
                                $parentIds['ids'][] = $idArr[1];
                            }
                        }
                        $v_names = $v->getSubfields('v');
                        if (count($v_names) > 0) {
                            $volNumber[$idArr[1]] = $v_names[0]->getData();
                        }
                    }
                }
                else if ($eighthundred) {
                    foreach($eighthundred as $v) {
                        $a_names = $v->getSubfields('w');
                        if (count($a_names) > 0) {
                            $idArr = explode(')', $a_names[0]->getData());
                            if ($idArr[0] === '(DE-601') {
                                $parentIds['ids'][] = $idArr[1];
                            }
                        }
                        $v_names = $v->getSubfields('v');
                        if (count($v_names) > 0) {
                            $volNumber[$idArr[1]] = $v_names[0]->getData();
                        }
                    }
                }
                else if ($eighthundredten) {
                    foreach($eighthundredten as $v) {
                        $a_names = $v->getSubfields('w');
                        if (count($a_names) > 0) {
                            $idArr = explode(')', $a_names[0]->getData());
                            if ($idArr[0] === '(DE-601') {
                                $parentIds['ids'][] = $idArr[1];
                            }
                        }
                        $v_names = $v->getSubfields('v');
                        if (count($v_names) > 0) {
                            $volNumber[$idArr[1]] = $v_names[0]->getData();
                        }
                    }
                }
            }
            return $parentIds;
        }
    }

    /**
     * Check if at least one article for this item exists.
     * Method to keep performance lean in core.tpl.
     *
     * @return bool
     * @access protected
     */
    public function searchArticles()
    {
        $rid=$this->fields['id'];
        if(strlen($rid)<2) {
            return array();
        }
        $rid=str_replace(":","\:",$rid);
        $index = $this->getIndexEngine();

        // Assemble the query parts and filter out current record:
        $query = '(ppnlink:'.$this->stripNLZ($rid).' AND (format:Article OR format:"electronic Article"))';

        // Perform the search and return either results or an error:
        $this->setHiddenFilters();

        $result = $index->search($query, null, $this->hiddenFilters, 0, 1000, null, '', null, null, '',  HTTP_REQUEST_METHOD_POST , false, false, false);

        // Check if the PPNs are from the same origin (either both should have an NLZ-prefix or both should not have it)
        $resultArray = array();
        $resultArray['response'] = array();
        $resultArray['response']['docs'] = array();
        foreach ($result['response']['docs'] as $resp) {
            if (($this->_isNLZ($resp['id']) && $this->_isNLZ($rid)) || (!$this->_isNLZ($resp['id']) && !$this->_isNLZ($rid))) {
                $resultArray['response']['docs'][] = $resp;
            }
        }

        //return ($result['response'] > 0) ? $result['response'] : false;
        return ($resultArray['response'] > 0) ? $resultArray['response'] : false;
    }

    /**
     * Check if at least one article for this item exists.
     * Method to keep performance lean in core.tpl.
     *
     * @return bool
     * @access protected
     */
    public function searchArticleVolume($rid, $fieldref)
    {
        $index = $this->getIndexEngine();

        $queryparts = array();
        $queryparts[] = 'ppnlink:'.$this->stripNLZ($rid);
        if ($fieldref['volume']) {
            $fieldsToSearch .= $fieldref['volume'].'.';
        }
        if ($fieldref['date']) {
            $fieldsToSearch .= $fieldref['date'];
        }
        if ($fieldsToSearch) {
            $queryparts[] = $fieldsToSearch;
        }
        $queryparts[] = '(format:Book OR format:"Serial Volume")';
        // Assemble the query parts and filter out current record:
        $query = implode(" AND ", $queryparts);
        $query = '('.$query.')';
        //$query = '(ppnlink:'.$rid.' AND '.$fieldref.')';

        // Perform the search and return either results or an error:
        $this->setHiddenFilters();

        $result = $index->search($query, null, $this->hiddenFilters, 0, 1000, null, '', null, null, '',  HTTP_REQUEST_METHOD_POST, false, false, false);

        return ($result['response'] > 0) ? $result['response'] : false;
    }

    /**
     * Check if at least one article for this item exists.
     * Method to keep performance lean in core.tpl.
     *
     * @return bool
     * @access protected
     */
    public function hasArticles()
    {
        $rid=$this->fields['id'];
        if(strlen($rid)<2) {
            return array();
        }
        $rid=str_replace(":","\:",$rid);
        $index = $this->getIndexEngine();

        // Assemble the query parts and filter out current record:
        $query = '(ppnlink:'.$this->stripNLZ($rid).' AND (format:Article OR format:"electronic Article")';
        //if ($this->isNLZ() === false) $query .= ' AND (NOT id:"NLZ*")';
        $query .= ')';

        // Perform the search and return either results or an error:
        $this->setHiddenFilters();

        $result = $index->search($query, null, $this->hiddenFilters, 0, 1000, null, '', null, null, 'id',  HTTP_REQUEST_METHOD_POST , false, false, false);

        $showRegister = false;
        foreach ($result['response']['docs'] as $resp) {
            // Walk through the results until there is a match, which is added to the result array
            if (($this->_isNLZ($resp['id']) && $this->_isNLZ($rid)) || (!$this->_isNLZ($resp['id']) && !$this->_isNLZ($rid))) {
                $showRegister = true;
                // After one hit is found, its clear that the register card needs to be shown, so leave the loop
                break;
            }
        }

        return $showRegister;
    }

    /**
     * Get multipart parent.
     *
     * @return array
     * @access protected
     */
    protected function getMultipartParent()
    {
        if (!(isset($this->fields['ppnlink'])) || $this->fields['ppnlink'] == null) {
            return array();
        }
        $mpid = $this->fields['ppnlink'];
        $query="";
        foreach($mpid as $mp) {
            if(strlen($mp)<2) continue;
            $mp=str_replace(":","\:",$mp);
            if(strlen($query)>0) $query.=" OR ";
            $query.= "id:".$this->addNLZ($mp);
        }

        // echo "<pre>".$query."</pre>";

        $index = $this->getIndexEngine();

        // Perform the search and return either results or an error:
        $this->setHiddenFilters();
        $result = $index->search($query, null, $this->hiddenFilters, 0, null, null, '', null, null, 'title, id',  HTTP_REQUEST_METHOD_POST , false, false, false);

        if (PEAR::isError($result)) {
            return $result;
        }

        if (isset($result['response']['docs'])
            && !empty($result['response']['docs'])
            ) {
            $cnt=0;
            foreach($result['response']['docs'] as $doc) {
                $retval[$cnt]['title']=$doc['title'];
                $retval[$cnt]['id']=$doc['id'];
                $cnt++;
            }
            // sort array for key 'part'
            return $retval = $this->_sortArray($retval,'title','asort');
        }
        return array();
    }

    /**
     * Get article children.
     *
     * @return array
     * @access protected
     */
    protected function getArticleChildren()
    {
        $cnt=0;
        $retval = array();
        $sort = array();
        $result = $this->getArticles();
        foreach($result as $doc) {
            $retval[$cnt]['title']=$doc['title_full'][0];
            $retval[$cnt]['id']=$doc['id'];
            $retval[$cnt]['date']=$doc['publishDate'][0];
            $retval[$cnt]['volume'] = $doc['volume'];
            $retval[$cnt]['issue'] = $doc['issue'];
            $retval[$cnt]['pages'] = $doc['pages'];
            $retval[$cnt]['sort'] = $doc['sort'];
            $cnt++;
        }
        foreach ($retval as $key => $row) {
            $part0[$key] = (isset($row['sort'])) ? $row['sort'] : 0;
            $part1[$key] = (isset($row['date'])) ? $row['date'] : 0;
            $part2[$key] = (isset($row['volume'])) ? $row['volume'] : 0;
            $part3[$key] = (isset($row['issue'])) ? $row['issue'] : 0;
            $part4[$key] = (isset($row['pages'])) ? $row['pages'] : 0;
            $part5[$key] = (isset($row['title'])) ? $row['title'] : 0;
        }
        array_multisort($part0, SORT_DESC, $part1, SORT_DESC, $part2, SORT_DESC, $part3, SORT_DESC, $part4, SORT_DESC, $part5, SORT_ASC, $retval );
        return $retval;
    }
}
