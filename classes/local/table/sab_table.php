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
 * Wunderbyte table for the SAB statistics report.
 *
 * @package     wbreport_iqshsab
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace wbreport_iqshsab\local\table;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

use local_wunderbyte_table\wunderbyte_table;
use local_wunderbyte_table\local\customfield\wbt_field_controller_info;

/**
 * Wunderbyte table subclass for the SAB statistics report.
 *
 * @package     wbreport_iqshsab
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sab_table extends wunderbyte_table {
    /**
     * Unix timestamp set by the report output class at table-construction time.
     * Used to write a "Generated at" footer line in PDF exports.
     *
     * @var int
     */
    public int $generatedat = 0;

    /**
     * Override is_downloading to set a report-specific filename with timestamp.
     *
     * The download.php entry point always passes a generic 'download' filename.
     * This override replaces it with iqshsab_<YYYYMMDD_HHMM> for the SAB
     * (all-users) report. When the table is filtered to a single user (via the
     * 'userfullname' standardfilter), that user's name is included in the filename so
     * the export matches the on-screen filter selection.
     *
     * @param string|null $download dataformat type, or null to query current state.
     * @param string $filename ignored – generated from the report identifier.
     * @param string $sheettitle sheet title passed through unchanged.
     * @return string current download dataformat type.
     */
    public function is_downloading($download = null, $filename = '', $sheettitle = '') {
        if ($download !== null) {
            $timestamp  = date('Ymd_Hi');
            $usersuffix = $this->get_single_user_filename_suffix();
            $filename   = $usersuffix !== ''
                ? "iqshsab_{$usersuffix}_{$timestamp}"
                : "iqshsab_{$timestamp}";
        }
        return parent::is_downloading($download, $filename, $sheettitle);
    }

    /**
     * If the 'userfullname' filter is set to exactly one value, return a sanitized
     * filename fragment derived from that user. Otherwise return an empty string.
     *
     * Reads the wbtfilter URL parameter directly because download.php applies
     * filters internally and the active filter values are not stored on the
     * table instance in a stable way.
     *
     * @return string sanitized filename fragment, or '' if no single-user filter.
     */
    private function get_single_user_filename_suffix(): string {
        $wbtfilter = optional_param('wbtfilter', '', PARAM_RAW);
        if ($wbtfilter === '') {
            return '';
        }
        $decoded = json_decode($wbtfilter, true);
        if (!is_array($decoded) || empty($decoded['userfullname']) || !is_array($decoded['userfullname'])) {
            return '';
        }
        if (count($decoded['userfullname']) !== 1) {
            return '';
        }
        $username = reset($decoded['userfullname']);
        if (!is_string($username) || $username === '') {
            return '';
        }
        return clean_filename(str_replace(' ', '_', $username));
    }

    /**
     * Override export_class_instance to inject our custom export class for PDF exports.
     *
     * This is called from {@see flexible_table::is_downloading()} when a download
     * format is set. By intercepting the no-argument lazy-creation call we can
     * substitute {@see \wbreport_iqshsab\local\export\sab_export_format} for the
     * default {@see \core_table\dataformat_export_format}, giving us
     * {@see \wbreport_iqshsab\local\export\sab_export_format::write_footer_text()}
     * without touching any file outside this plugin.
     *
     * @param \core_table\dataformat_export_format|null $exportclass Optional export class to set.
     * @return \core_table\dataformat_export_format
     */
    public function export_class_instance($exportclass = null) {
        if (is_null($exportclass) && is_null($this->exportclass) && $this->download === 'pdf') {
            // Instantiate our subclass and set it up exactly as the parent would.
            $this->exportclass = new \wbreport_iqshsab\local\export\sab_export_format($this, $this->download);
            $this->exportclass->table = $this;
            if (!$this->exportclass->document_started()) {
                $this->exportclass->start_document($this->filename, $this->sheettitle);
            }
            return $this->exportclass;
        }
        return parent::export_class_instance($exportclass);
    }

    /**
     * Finish output: write a "Generated at" footer line for PDF exports.
     *
     * Intercepts the normal finish_output() flow so that the footer text can be
     * injected between finish_table() (closes table rows) and finish_document()
     * (streams the PDF to the browser).
     *
     * @param bool   $closeexportclassdoc Whether to close and stream the document.
     * @param string $encodedtable        Encoded table hash (HTML path only).
     * @return \local_wunderbyte_table\output\table|void
     */
    public function finish_output($closeexportclassdoc = true, $encodedtable = '') {
        if ($this->exportclass !== null) {
            $this->exportclass->finish_table();
            if ($this->generatedat > 0 && method_exists($this->exportclass, 'write_footer_text')) {
                global $USER;
                $label   = get_string('generated_at', 'wbreport_iqshsab');
                $datestr = userdate($this->generatedat, get_string('strftimedatetime', 'langconfig'));
                $fullname = $USER->firstname . ' ' . $USER->lastname;
                $this->exportclass->write_footer_text("{$label}: {$datestr} – {$fullname} ({$USER->email})");
            }
            if ($closeexportclassdoc) {
                $this->exportclass->finish_document();
            }
            return;
        }
        return parent::finish_output($closeexportclassdoc, $encodedtable);
    }

    /**
     * Format the userfullname column as "Firstname Lastname (email)".
     *
     * @param object $values
     * @return string
     */
    public function col_userfullname($values): string {
        $name  = trim(($values->firstname ?? '') . ' ' . ($values->lastname ?? ''));
        $email = $values->email ?? '';
        $label = $email !== '' ? "{$name} ({$email})" : $name;
        if ($this->is_downloading()) {
            return $label;
        }
        if (!empty($values->userid)) {
            $url = new \moodle_url('/user/profile.php', ['id' => (int) $values->userid]);
            return '<a href="' . $url->out(false) . '">' . s($label) . '</a>';
        }
        return s($label);
    }

    /**
     * Format the optionname column as a link to optionview.php (HTML only, not on download).
     *
     * @param object $values
     * @return string
     */
    public function col_optionname($values): string {
        $name = s($values->optionname);
        if ($this->is_downloading() || empty($values->cmid) || empty($values->optionid)) {
            return $name;
        }
        $url = new \moodle_url('/mod/booking/optionview.php', [
            'optionid' => (int) $values->optionid,
            'cmid'     => (int) $values->cmid,
        ]);
        return '<a href="' . $url->out(false) . '">' . $name . '</a>';
    }

    /**
     * Format the completed column as a readable yes/no value.
     *
     * @param object $values
     * @return string
     */
    public function col_completed($values): string {
        $label = $values->completed ? get_string('yes') : get_string('no');
        if ($this->is_downloading()) {
            return $label;
        }
        $class = $values->completed ? 'badge badge-success' : 'badge badge-danger';
        return \html_writer::tag('span', $label, ['class' => $class]);
    }

    /**
     * Format the completeddate column as a human-readable date.
     *
     * @param object $values
     * @return string
     */
    public function col_completeddate($values): string {
        if (empty($values->completeddate)) {
            return '';
        }
        if ($this->is_downloading()) {
            return date('Y-m-d H:i', $values->completeddate);
        }
        return userdate($values->completeddate, get_string('strftimedatetime', 'langconfig'));
    }

    /**
     * Format the coursestarttime column as a human-readable date.
     *
     * @param object $values
     * @return string
     */
    public function col_coursestarttime($values): string {
        if (empty($values->coursestarttime)) {
            return '';
        }
        if ($this->is_downloading()) {
            return date('Y-m-d H:i', $values->coursestarttime);
        }
        return userdate($values->coursestarttime, get_string('strftimedatetime', 'langconfig'));
    }

    /**
     * Format the courseendtime column as a human-readable date.
     *
     * @param object $values
     * @return string
     */
    public function col_courseendtime($values): string {
        if (empty($values->courseendtime)) {
            return '';
        }
        if ($this->is_downloading()) {
            return date('Y-m-d H:i', $values->courseendtime);
        }
        return userdate($values->courseendtime, get_string('strftimedatetime', 'langconfig'));
    }

    /**
     * Format the stunden column with one decimal place.
     *
     * @param object $values
     * @return string
     */
    public function col_stunden($values): string {
        if ($values->stunden === 0) {
            return '0.0';
        } else if (empty($values->stunden)) {
            return '';
        }
        return number_format((float) $values->stunden, 1);
    }

    /**
     * Format the fach column.
     *
     * @param object $values
     * @return string
     */
    public function col_fach($values): string {
        if (empty($values->fach)) {
            return '';
        }
        $fieldcontroller = wbt_field_controller_info::get_instance_by_shortname('fach');

        if (str_contains($values->fach, ',')) {
            // Multiple values are stored as pipe-separated keys. Format each and join with comma+space.
            $faecher = [];
            foreach (explode(',', $values->fach) as $fachkey) {
                $faecher[] = $fieldcontroller->get_option_value_by_key($fachkey);
            }
            return implode(', ', $faecher);
        }

        $renderedfach = $fieldcontroller->get_option_value_by_key($values->fach);
        return $renderedfach;
    }

    /**
     * Format the stunden_progress column as "total/48".
     *
     * @param object $values
     * @return string
     */
    public function col_stunden_progress($values): string {
        if (empty($values->completed)) {
            return '';
        }
        return (int) $values->stunden_progress . '/48';
    }
}
