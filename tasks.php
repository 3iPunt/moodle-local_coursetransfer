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
 * Tracking page: adhoc tasks related to a course transfer request (this site).
 *
 * @package    local_coursetransfer
 * @copyright  2025 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coursetransfer\coursetransfer_request;

require_once('../../config.php');

global $OUTPUT, $PAGE, $DB;

$requestid = required_param('requestid', PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/coursetransfer:view_logs', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/coursetransfer/tasks.php', ['requestid' => $requestid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('tracking', 'local_coursetransfer'));
$PAGE->set_heading(get_string('tracking', 'local_coursetransfer'));

$request = coursetransfer_request::get($requestid);

echo $OUTPUT->header();

$logsurl = new moodle_url('/local/coursetransfer/logs.php',
        $request ? ['type' => $request->type, 'direction' => $request->direction] : []);
echo html_writer::link($logsurl, get_string('back'), ['class' => 'btn btn-outline-primary mb-4']);

if (!$request) {
    echo $OUTPUT->notification(get_string('retry_error', 'local_coursetransfer', 'ID ' . $requestid),
            \core\output\notification::NOTIFY_ERROR);
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('h4', get_string('tracking', 'local_coursetransfer') . ' — '
        . get_string('request_id', 'local_coursetransfer') . ' ' . $requestid);

// Find the adhoc tasks of this plugin related to this request (this site only).
$tasks = coursetransfer_request::get_related_adhoc_tasks($requestid);

if (empty($tasks)) {
    echo $OUTPUT->notification(get_string('tracking_none', 'local_coursetransfer'),
            \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [
            get_string('tracking_class', 'local_coursetransfer'),
            get_string('tracking_faildelay', 'local_coursetransfer'),
            get_string('tracking_nextrun', 'local_coursetransfer'),
            get_string('timecreated', 'local_coursetransfer'),
    ];
    foreach ($tasks as $task) {
        $shortclass = ltrim(strrchr($task->classname, '\\'), '\\') ?: $task->classname;
        $nextrun = $task->nextruntime ? userdate($task->nextruntime) : '-';
        $table->data[] = [
                $shortclass,
                (int)$task->faildelay,
                $nextrun,
                userdate($task->timecreated),
        ];
    }
    echo html_writer::table($table);
}

// Link to the core task logs (history; filter by task class there).
$tasklogsurl = new moodle_url('/admin/tasklogs.php');
echo html_writer::div(
        html_writer::link($tasklogsurl, get_string('tracking_corelogs', 'local_coursetransfer'),
                ['target' => '_blank', 'class' => 'btn btn-link']));

echo $OUTPUT->footer();
