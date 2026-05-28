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
 * Entry point for the SAB statistics report.
 *
 * @package     wbreport_iqshsab
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use wbreport_iqshsab\output\sab;

require_once(__DIR__ . '/../../../../config.php');

// No guest autologin.
require_login(0, false);

global $PAGE;

if (!$context = context_system::instance()) {
    throw new moodle_exception('badcontext');
}

// This report is restricted to wb_reports admins.
require_capability('local/wb_reports:admin', $context);

$PAGE->set_context($context);
$title = get_string('pluginname', 'wbreport_iqshsab');
$url   = new moodle_url('/local/wb_reports/wbreport/iqshsab/report.php');

$PAGE->set_url($url);
$PAGE->set_title($title);
$PAGE->set_heading($title);

$output = $PAGE->get_renderer('local_wb_reports');

echo $output->header();

$data = new sab();
echo $output->render_report($data);

echo $output->footer();
