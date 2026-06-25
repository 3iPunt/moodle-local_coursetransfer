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

// Project implemented by the "Recovery, Transformation and Resilience Plan.
// Funded by the European Union - Next GenerationEU".
//
// Produced by the UNIMOODLE University Group: Universities of
// Valladolid, Complutense de Madrid, UPV/EHU, León, Salamanca,
// Illes Balears, Valencia, Rey Juan Carlos, La Laguna, Zaragoza, Málaga,
// Córdoba, Extremadura, Vigo, Las Palmas de Gran Canaria y Burgos.

/**
 * Delete a course transfer log record. Restricted to site administrators.
 *
 * @package    local_coursetransfer
 * @copyright  2025 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_coursetransfer\coursetransfer_request;

require_once('../../config.php');

global $OUTPUT, $PAGE, $DB;

$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/coursetransfer:view_logs', $context);
// Deleting log records is an administrative action.
if (!is_siteadmin()) {
    throw new required_capability_exception($context, 'moodle/site:config', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/coursetransfer/delete.php', ['id' => $id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('deletelog', 'local_coursetransfer'));
$PAGE->set_heading(get_string('deletelog', 'local_coursetransfer'));

$logsurl = new moodle_url('/local/coursetransfer/logs.php');

$request = coursetransfer_request::get($id);
if (!$request) {
    redirect($logsurl, get_string('deletelog_error', 'local_coursetransfer', 'ID ' . $id),
            null, notification::NOTIFY_ERROR);
}

$returnurl = new moodle_url('/local/coursetransfer/logs.php',
        ['type' => $request->type, 'direction' => $request->direction]);

// Confirmation step.
if (!$confirm) {
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
            get_string('deletelog_confirm', 'local_coursetransfer', $id),
            new moodle_url('/local/coursetransfer/delete.php', ['id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
            $returnurl
    );
    echo $OUTPUT->footer();
    exit;
}

require_sesskey();

$DB->delete_records('local_coursetransfer_request', ['id' => $id]);

redirect($returnurl, get_string('deletelog_ok', 'local_coursetransfer'), null, notification::NOTIFY_SUCCESS);