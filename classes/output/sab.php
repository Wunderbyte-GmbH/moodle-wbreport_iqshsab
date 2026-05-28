<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Output class for the SAB statistics report.
 *
 * @package     wbreport_iqshsab
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace wbreport_iqshsab\output;

use cache_helper;
use local_wb_reports\plugininfo\wbreport;
use local_wb_reports\plugininfo\wbreport_interface;
use local_wunderbyte_table\filters\types\standardfilter;
use stdClass;
use renderer_base;
use renderable;
use templatable;
use wbreport_iqshsab\local\table\sab_table;

/**
 * Prepares data for the SAB statistics report.
 *
 * One row per booking_answer of any user with at least one booking. Columns:
 *   user, optionname, coursestarttime, courseendtime, completed, completeddate,
 *   fach, stunden (per option), stunden_progress (total/48 per user).
 *
 * The report-generation timestamp is shown in the page header (HTML)
 * and as a footer line in PDF exports via {@see sab_table::finish_output()}.
 *
 * @package     wbreport_iqshsab
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sab implements renderable, templatable, wbreport_interface {
    /** @var string $tabledata Rendered HTML of the table. */
    private string $tabledata = '';

    /**
     * Unix timestamp captured when this report instance was constructed.
     * Shown in the page header and used as the PDF footer timestamp.
     *
     * @var int
     */
    private int $generatedat;

    /**
     * In the constructor, we gather all the data we need.
     */
    public function __construct() {
        global $DB;

        cache_helper::purge_by_event('setbackwbreportscache');

        $table = new sab_table('sab_table');

        $table->define_headers([
            get_string('user', 'wbreport_iqshsab'),
            get_string('optionname', 'wbreport_iqshsab'),
            get_string('coursestarttime', 'wbreport_iqshsab'),
            get_string('courseendtime', 'wbreport_iqshsab'),
            get_string('completed', 'wbreport_iqshsab'),
            get_string('completeddate', 'wbreport_iqshsab'),
            get_string('schulart', 'wbreport_iqshsab'),
            get_string('fach', 'wbreport_iqshsab'),
            get_string('kategorie', 'wbreport_iqshsab'),
            get_string('stunden', 'wbreport_iqshsab'),
            get_string('stunden_progress', 'wbreport_iqshsab'),
        ]);

        $table->define_columns([
            'userfullname',
            'optionname',
            'coursestarttime',
            'courseendtime',
            'completed',
            'completeddate',
            'schulart',
            'fach',
            'kategorie',
            'stunden',
            'stunden_progress',
        ]);

        // Snapshot of the current time: shown in the page header and written as PDF footer.
        $now = time();
        $this->generatedat = $now;

        // Fetch the booking module id once via PHP so the main SQL stays free of scalar subqueries.
        $bookingmoduleid = (int) $DB->get_field('modules', 'id', ['name' => 'booking']);

        $fullnameuser = $DB->sql_concat("u.firstname", "' '", "u.lastname");

        $fields = "m.*";

        $from = "(
            SELECT
                ba.id,
                ba.userid                              AS userid,
                u.firstname                            AS firstname,
                u.lastname                             AS lastname,
                u.email                                AS email,
                {$fullnameuser}                        AS userfullname,
                bo.id                                  AS optionid,
                bo.text                                AS optionname,
                cm.id                                  AS cmid,
                bo.coursestarttime,
                bo.courseendtime,
                ba.completed,
                CASE WHEN ba.completed = 1 THEN ba.completeddate ELSE NULL END AS completeddate,
                COALESCE(cfd_schulart.value, '')       AS schulart,
                COALESCE(cfd_fach.value, '')           AS fach,
                COALESCE(cfd_kategorie.value, '')      AS kategorie,
                COALESCE(cfd.decvalue, 0)              AS stunden,
                COALESCE(tot.stunden_progress, 0)      AS stunden_progress,
                {$now}                                 AS generated_at
            FROM {booking_answers} ba
            JOIN {user} u
                ON u.id = ba.userid
            JOIN {booking_options} bo
                ON bo.id = ba.optionid
            LEFT JOIN {course_modules} cm
                ON  cm.instance = bo.bookingid
                AND cm.module   = {$bookingmoduleid}
            LEFT JOIN {customfield_field} cff
                ON  cff.shortname  = 'stunden'
            LEFT JOIN {customfield_category} cfc
                ON  cfc.id         = cff.categoryid
                AND cfc.component  = 'mod_booking'
                AND cfc.area       = 'booking'
            LEFT JOIN {customfield_data} cfd
                ON  cfd.instanceid = ba.optionid
                AND cfd.fieldid    = cff.id
            LEFT JOIN {customfield_field} cff_schulart
                ON  cff_schulart.shortname = 'schulart'
            LEFT JOIN {customfield_category} cfc_schulart
                ON  cfc_schulart.id        = cff_schulart.categoryid
                AND cfc_schulart.component = 'mod_booking'
                AND cfc_schulart.area      = 'booking'
            LEFT JOIN {customfield_data} cfd_schulart
                ON  cfd_schulart.instanceid = ba.optionid
                AND cfd_schulart.fieldid    = cff_schulart.id
            LEFT JOIN {customfield_field} cff_fach
                ON  cff_fach.shortname = 'fach'
            LEFT JOIN {customfield_category} cfc_fach
                ON  cfc_fach.id        = cff_fach.categoryid
                AND cfc_fach.component = 'mod_booking'
                AND cfc_fach.area      = 'booking'
            LEFT JOIN {customfield_data} cfd_fach
                ON  cfd_fach.instanceid = ba.optionid
                AND cfd_fach.fieldid    = cff_fach.id
            LEFT JOIN {customfield_field} cff_kategorie
                ON  cff_kategorie.shortname = 'kategorie'
            LEFT JOIN {customfield_category} cfc_kategorie
                ON  cfc_kategorie.id        = cff_kategorie.categoryid
                AND cfc_kategorie.component = 'mod_booking'
                AND cfc_kategorie.area      = 'booking'
            LEFT JOIN {customfield_data} cfd_kategorie
                ON  cfd_kategorie.instanceid = ba.optionid
                AND cfd_kategorie.fieldid    = cff_kategorie.id
            LEFT JOIN (
                SELECT
                    ba2.userid,
                    COALESCE(SUM(cfd2.decvalue), 0)    AS stunden_progress
                FROM {booking_answers} ba2
                LEFT JOIN {customfield_field} cff2
                    ON  cff2.shortname  = 'stunden'
                LEFT JOIN {customfield_category} cfc2
                    ON  cfc2.id         = cff2.categoryid
                    AND cfc2.component  = 'mod_booking'
                    AND cfc2.area       = 'booking'
                LEFT JOIN {customfield_data} cfd2
                    ON  cfd2.instanceid = ba2.optionid
                    AND cfd2.fieldid    = cff2.id
                WHERE ba2.completed = 1
                GROUP BY ba2.userid
            ) tot
                ON tot.userid = ba.userid
            WHERE ba.waitinglist = 0
        ) m";

        $table->set_filter_sql($fields, $from, '1=1', '', []);

        $table->sortable(true, 'completeddate', SORT_DESC);

        $table->define_fulltextsearchcolumns([
            'userfullname', 'firstname', 'lastname', 'email', 'optionname', 'schulart', 'fach', 'kategorie',
        ]);

        $table->define_sortablecolumns([
            'userfullname' => get_string('user', 'wbreport_iqshsab'),
            'optionname' => get_string('optionname', 'wbreport_iqshsab'),
            'coursestarttime' => get_string('coursestarttime', 'wbreport_iqshsab'),
            'courseendtime' => get_string('courseendtime', 'wbreport_iqshsab'),
            'completed' => get_string('completed', 'wbreport_iqshsab'),
            'completeddate' => get_string('completeddate', 'wbreport_iqshsab'),
            'schulart' => get_string('schulart', 'wbreport_iqshsab'),
            'fach' => get_string('fach', 'wbreport_iqshsab'),
            'kategorie' => get_string('kategorie', 'wbreport_iqshsab'),
            'stunden' => get_string('stunden', 'wbreport_iqshsab'),
        ]);

        // Filter: by school type.
        $schulartfilter = new standardfilter('schulart', get_string('schulart', 'wbreport_iqshsab'));
        $table->add_filter($schulartfilter);

        // Filter: by subject.
        $fachfilter = new standardfilter('fach', get_string('fach', 'wbreport_iqshsab'));
        $table->add_filter($fachfilter);

        // Filter: by category.
        $kategoriefilter = new standardfilter('kategorie', get_string('kategorie', 'wbreport_iqshsab'));
        $table->add_filter($kategoriefilter);

        // Filter: completed yes/no.
        $completedfilter = new standardfilter('completed', get_string('completed', 'wbreport_iqshsab'));
        $completedfilter->add_options([
            0 => get_string('no'),
            1 => get_string('yes'),
        ]);
        $table->add_filter($completedfilter);

        // Filter: by user (uses the concatenated full name as filter value).
        $userfilter = new standardfilter('userfullname', get_string('user', 'wbreport_iqshsab'));
        $table->add_filter($userfilter);

        $table->define_cache('local_wb_reports', 'wbreportscache');

        $table->generatedat = $now;

        $table->pageable(true);
        $table->cardsort = true;
        $table->showcountlabel = true;
        $table->showfilterontop = 1;
        $table->showdownloadbutton = true;
        $table->showreloadbutton = true;
        $table->applyfilterondownload = true;
        $table->alloweddownloadformats = ['pdf', 'csv', 'excel'];

        [, , $html] = $table->lazyouthtml(50, true);
        $this->tabledata = $html;
    }

    /**
     * Use this function to render any HTML in the report header.
     *
     * @return string the html for the table header
     */
    public function get_table_header_html(): string {
        $heading = get_string('pluginname', 'wbreport_iqshsab');
        $datestr = userdate($this->generatedat, get_string('strftimedatetime', 'langconfig'));
        $generatedlabel = get_string('generated_at', 'wbreport_iqshsab');
        return '<div class="alert alert-secondary h3">' .
            s($heading) .
            '<div class="small fw-normal mt-1">' .
            s("{$generatedlabel}: {$datestr}") .
            '</div>' .
            '</div>';
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param renderer_base $output
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $data = new stdClass();
        $wbreport = new wbreport();
        $data->dashboardlink = $wbreport->get_dashboard_link();
        $data->tableheader   = $this->get_table_header_html();
        $data->table         = $this->tabledata;
        return $data;
    }
}
