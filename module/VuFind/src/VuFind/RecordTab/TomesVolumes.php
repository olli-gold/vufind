<?php
/**
 * User comments tab
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
 * @package  RecordTabs
 * @author   Oliver Goldschmidt <o.goldschmidt@tuhh.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:record_tabs Wiki
 */
namespace VuFind\RecordTab;

/**
 * User comments tab
 *
 * @category VuFind2
 * @package  RecordTabs
 * @author   Oliver Goldschmidt <o.goldschmidt@tuhh.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:record_tabs Wiki
 */
class TomesVolumes extends AbstractBase
{
    /**
     * Constructor
     *
     * @param bool $enabled is this tab enabled?
     */
    public function __construct($enabled = true)
    {
    }

    /**
     * Is this tab active?
     *
     * @return bool
     */
    public function isActive()
    {
        $multipart = $this->getRecordDriver()->tryMethod('isMultipartChildren');
        if (empty($multipart)) {
            return false;
        }
        $mp = $this->getRecordDriver()->isMultipartChildren();
        return $mp;
    }

    /**
     * Get the on-screen description for this tab.
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Tomes/Volumes';
    }

    /**
     * Get the content of this tab.
     *
     * @return array
     */
    public function getContent()
    {
        $multipart = $this->getRecordDriver()->tryMethod('getMultipartChildren');
        if (empty($multipart)) {
            return null;
        }
        $vols = $this->getRecordDriver()->getMultipartChildren();
        return $vols;
    }
}
