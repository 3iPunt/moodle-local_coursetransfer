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
 * Restore Wizard External.
 *
 * Frontend web services for the redesigned restore wizard. These methods only
 * WRAP the existing business logic (coursetransfer, api\request, models); they
 * do not modify it. All operations run in the admin (system) context.
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer\external\frontend;

use coding_exception;
use context_coursecat;
use context_system;
use core_course_category;
use dml_exception;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use invalid_parameter_exception;
use local_coursetransfer\api\request;
use local_coursetransfer\coursetransfer;
use local_coursetransfer\coursetransfer_request;
use local_coursetransfer\coursetransfer_sites;
use local_coursetransfer\factory\category;
use local_coursetransfer\factory\course;
use local_coursetransfer\models\configuration_category;
use local_coursetransfer\models\configuration_course;
use moodle_exception;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

/**
 * restore_wizard_external
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_wizard_external extends external_api {

    /**
     * Get sites parameters.
     *
     * @return external_function_parameters
     */
    public static function get_sites_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Get origin sites.
     *
     * Wraps coursetransfer::get_origin_sites() (which returns [id => host]) and
     * coursetransfer_sites::list('origin') (which carries name and last test
     * information). The returned "id" is the DB record id of each origin site,
     * which is exactly the "position" expected by
     * coursetransfer::get_site_by_position().
     *
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function get_sites(): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursetransfer:origin_restore', $context);

        $sites = [];
        // coursetransfer_sites::list('origin') gives us name/lasttest/lastteststatus
        // keyed and indexed by the site record id, which IS the position.
        $records = coursetransfer_sites::list('origin');
        foreach ($records as $record) {
            $connected = isset($record->lastteststatus) && (int)$record->lastteststatus === 1;
            if (!empty($record->name)) {
                $name = $record->name;
            } else {
                $name = preg_replace('#^https?://#i', '', $record->host);
            }
            if (!isset($record->lastteststatus) || $record->lastteststatus === null) {
                $status = get_string('platforms_test_idle', 'local_coursetransfer');
            } else {
                $status = $connected
                        ? get_string('platform_conn_ok', 'local_coursetransfer')
                        : get_string('platform_conn_error', 'local_coursetransfer');
            }
            $sites[] = [
                'id' => (int)$record->id,
                'name' => $name,
                'host' => $record->host,
                'connected' => $connected,
                'status' => $status,
            ];
        }

        return [
            'sites' => $sites,
        ];
    }

    /**
     * Get sites returns.
     *
     * @return external_single_structure
     */
    public static function get_sites_returns(): external_single_structure {
        return new external_single_structure(
            [
                'sites' => new external_multiple_structure(new external_single_structure(
                    [
                        'id' => new external_value(PARAM_INT, 'Site position (origin record id)'),
                        'name' => new external_value(PARAM_TEXT, 'Site name'),
                        'host' => new external_value(PARAM_RAW, 'Site host'),
                        'connected' => new external_value(PARAM_BOOL, 'Whether last test was OK'),
                        'status' => new external_value(PARAM_TEXT, 'Connection status text'),
                    ]
                )),
            ]
        );
    }

    /**
     * List origin parameters.
     *
     * @return external_function_parameters
     */
    public static function list_origin_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'siteid' => new external_value(PARAM_INT, 'Site position (origin record id)'),
                'type' => new external_value(PARAM_ALPHA, 'Type: course|category'),
                'page' => new external_value(PARAM_INT, 'Page (0-based)', VALUE_DEFAULT, 0),
                'perpage' => new external_value(PARAM_INT, 'Items per page', VALUE_DEFAULT, 10),
                'query' => new external_value(PARAM_TEXT, 'Search query', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * List origin courses or categories.
     *
     * Wraps api\request::origin_get_courses()/origin_get_categories() and
     * normalises their response into a common item shape.
     *
     * @param int $siteid
     * @param string $type
     * @param int $page
     * @param int $perpage
     * @param string $query
     * @return array
     * @throws coding_exception
     * @throws invalid_parameter_exception
     */
    public static function list_origin(int $siteid, string $type, int $page, int $perpage, string $query): array {
        global $USER;
        $params = self::validate_parameters(
            self::list_origin_parameters(), [
                'siteid' => $siteid,
                'type' => $type,
                'page' => $page,
                'perpage' => $perpage,
                'query' => $query,
            ]
        );
        $siteid = $params['siteid'];
        $type = $params['type'];
        $page = $params['page'];
        $perpage = $params['perpage'];
        $query = $params['query'];

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursetransfer:origin_restore', $context);

        $items = [];
        $total = 0;
        $pages = 1;
        $success = false;
        $errors = [];

        try {
            $site = coursetransfer::get_site_by_position($siteid);
            $request = new request($site);
            if ($type === 'category') {
                $res = $request->origin_get_categories($USER, $page, $perpage);
            } else {
                $res = $request->origin_get_courses($USER, $page, $perpage, $query);
            }

            $success = (bool)$res->success;
            if ($success) {
                $data = is_array($res->data) ? $res->data : [];
                foreach ($data as $item) {
                    if ($type === 'category') {
                        $items[] = [
                            'id' => (int)$item->id,
                            'name' => isset($item->name) ? $item->name : '',
                            'sub' => isset($item->idnumber) ? (string)$item->idnumber : '',
                            'meta' => isset($item->totalcourses) ? (string)$item->totalcourses : '0',
                        ];
                    } else {
                        $items[] = [
                            'id' => (int)$item->id,
                            'name' => isset($item->fullname) ? $item->fullname : '',
                            'sub' => isset($item->shortname) ? (string)$item->shortname : '',
                            'meta' => isset($item->backupsizeestimated) ? (string)$item->backupsizeestimated : '',
                        ];
                    }
                }
                // Paging: the remote may return totalcount/perpage in $res->paging.
                // LIMITATION: if the remote does not report paging (older origin
                // sites), we cannot know the real total, so we fall back to the
                // size of the current page and a single page.
                if (!empty($res->paging) && isset($res->paging->totalcount)) {
                    $total = (int)$res->paging->totalcount;
                    $pp = !empty($res->paging->perpage) ? (int)$res->paging->perpage : $perpage;
                    $pages = ($pp > 0) ? (int)ceil($total / $pp) : 1;
                    if ($pages < 1) {
                        $pages = 1;
                    }
                } else {
                    $total = count($items);
                    $pages = 1;
                }
            } else {
                $errors = self::normalize_errors($res->errors);
            }
        } catch (moodle_exception $e) {
            $success = false;
            $errors[] = [
                'code' => '30001',
                'msg' => $e->getMessage(),
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'success' => $success,
            'errors' => $errors,
        ];
    }

    /**
     * List origin returns.
     *
     * @return external_single_structure
     */
    public static function list_origin_returns(): external_single_structure {
        return new external_single_structure(
            [
                'items' => new external_multiple_structure(new external_single_structure(
                    [
                        'id' => new external_value(PARAM_INT, 'Course/Category ID'),
                        'name' => new external_value(PARAM_TEXT, 'Name'),
                        'sub' => new external_value(PARAM_TEXT, 'Shortname/idnumber'),
                        'meta' => new external_value(PARAM_TEXT, 'Size or course count'),
                    ]
                )),
                'total' => new external_value(PARAM_INT, 'Total items'),
                'page' => new external_value(PARAM_INT, 'Current page (0-based)'),
                'pages' => new external_value(PARAM_INT, 'Total pages'),
                'success' => new external_value(PARAM_BOOL, 'Was it a success?'),
                'errors' => new external_multiple_structure(new external_single_structure(
                    [
                        'code' => new external_value(PARAM_TEXT, 'Code'),
                        'msg' => new external_value(PARAM_RAW, 'Message'),
                    ]
                )),
            ]
        );
    }

    /**
     * Submit parameters.
     *
     * @return external_function_parameters
     */
    public static function submit_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'siteid' => new external_value(PARAM_INT, 'Site position (origin record id)'),
                'type' => new external_value(PARAM_ALPHA, 'Type: course|category'),
                'ids' => new external_multiple_structure(
                        new external_value(PARAM_INT, 'Origin course/category id')),
                'targetcatid' => new external_value(PARAM_INT, 'Target local category (0 = default)'),
                'mode' => new external_value(PARAM_ALPHA, 'Mode: new|merge|replace'),
                'removeenrols' => new external_value(PARAM_BOOL, 'Remove enrols'),
                'removegroups' => new external_value(PARAM_BOOL, 'Remove groups'),
                'includeusers' => new external_value(PARAM_BOOL, 'Include users'),
                'schedule' => new external_value(PARAM_INT, 'Schedule timestamp in ms (0 = now)'),
            ]
        );
    }

    /**
     * Submit restore.
     *
     * Replicates the logic of restore_external::origin_restore_step4 /
     * origin_restore_cat_step4 (create the target course/category with the
     * factory, build the configuration models and call
     * coursetransfer::restore_course/restore_category), fixing bug LCT-040:
     * errors are accumulated with array_merge and success is an AND of every
     * unit, so a later success no longer masks an earlier failure.
     *
     * @param int $siteid
     * @param string $type
     * @param array $ids
     * @param int $targetcatid
     * @param string $mode
     * @param bool $removeenrols
     * @param bool $removegroups
     * @param bool $includeusers
     * @param int $schedule
     * @return array
     * @throws coding_exception
     * @throws invalid_parameter_exception
     */
    public static function submit(int $siteid, string $type, array $ids, int $targetcatid,
            string $mode, bool $removeenrols, bool $removegroups, bool $includeusers, int $schedule): array {
        global $USER;
        $params = self::validate_parameters(
            self::submit_parameters(), [
                'siteid' => $siteid,
                'type' => $type,
                'ids' => $ids,
                'targetcatid' => $targetcatid,
                'mode' => $mode,
                'removeenrols' => $removeenrols,
                'removegroups' => $removegroups,
                'includeusers' => $includeusers,
                'schedule' => $schedule,
            ]
        );
        $siteid = $params['siteid'];
        $type = $params['type'];
        $ids = $params['ids'];
        $targetcatid = $params['targetcatid'];
        $mode = $params['mode'];
        $removeenrols = $params['removeenrols'];
        $removegroups = $params['removegroups'];
        $includeusers = $params['includeusers'];
        $schedule = $params['schedule'];

        // System context + origin_restore capability.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursetransfer:origin_restore', $context);

        // LCT-012: also require restore capability on the destination category context.
        $targetcategory = ($targetcatid === 0)
                ? core_course_category::get_default()
                : core_course_category::get($targetcatid);
        $catcontext = context_coursecat::instance($targetcategory->id);
        require_capability('moodle/restore:restorecourse', $catcontext);

        // Map mode -> target_target.
        $targettarget = self::mode_to_target($mode);

        // Schedule: incoming timestamp is in ms; 0 = run now (null nextruntime).
        $nextruntime = ($schedule > 0) ? (int)floor($schedule / 1000) : null;

        $success = true;
        $errors = [];
        $requestids = [];

        if (empty($ids)) {
            $success = false;
            $errors[] = [
                'code' => '30502',
                'msg' => get_string('courses_not_selected', 'local_coursetransfer'),
            ];
        } else {
            try {
                $site = coursetransfer::get_site_by_position($siteid);

                if ($type === 'category') {
                    // One restore_category per selected origin category.
                    foreach ($ids as $catid) {
                        try {
                            $config = new configuration_category(
                                    $targettarget,
                                    $removeenrols,
                                    $removegroups,
                                    $includeusers,
                                    false,
                                    $nextruntime
                            );
                            $res = coursetransfer::restore_category($USER, $site, $targetcatid, (int)$catid, $config);
                            // LCT-040: AND success, merge errors.
                            $success = $success && (bool)$res['success'];
                            if (!empty($res['errors'])) {
                                $errors = array_merge($errors, $res['errors']);
                            }
                            if (isset($res['data']['requestid'])) {
                                $requestids[] = (int)$res['data']['requestid'];
                            }
                        } catch (moodle_exception $e) {
                            $success = false;
                            $errors[] = [
                                'code' => '30501',
                                'msg' => 'Category ID: ' . $catid . ' - ' . $e->getMessage(),
                            ];
                        }
                    }
                } else {
                    // One target course (created with the factory) per selected origin course.
                    $num = 1;
                    foreach ($ids as $origincourseid) {
                        try {
                            $targetcourseid = course::create(
                                    $targetcategory,
                                    'Remote Restoring in process...',
                                    'IN-PROGRESS-' . time() . '-' . $num);
                            $config = new configuration_course(
                                    $targettarget,
                                    $removeenrols,
                                    $removegroups,
                                    $includeusers,
                                    false,
                                    $nextruntime
                            );
                            $res = coursetransfer::restore_course(
                                    $USER, $site, $targetcourseid, (int)$origincourseid, $config, []);
                            // LCT-040: AND success, merge errors.
                            $success = $success && (bool)$res['success'];
                            if (!empty($res['errors'])) {
                                $errors = array_merge($errors, $res['errors']);
                            }
                            if (isset($res['data']['requestid'])) {
                                $requestids[] = (int)$res['data']['requestid'];
                            }
                            $num++;
                        } catch (moodle_exception $e) {
                            $success = false;
                            $errors[] = [
                                'code' => '30501',
                                'msg' => 'Course ID: ' . $origincourseid . ' - ' . $e->getMessage(),
                            ];
                        }
                    }
                }
            } catch (moodle_exception $e) {
                $success = false;
                $errors[] = [
                    'code' => '30500',
                    'msg' => $e->getMessage(),
                ];
            }
        }

        if ($type === 'category') {
            $nexturl = new moodle_url('/local/coursetransfer/logs.php',
                    ['type' => coursetransfer_request::TYPE_CATEGORY]);
        } else {
            $nexturl = new moodle_url('/local/coursetransfer/logs.php');
        }

        return [
            'success' => $success,
            'errors' => $errors,
            'data' => [
                'requestids' => $requestids,
                'nexturl' => $nexturl->out(false),
            ],
        ];
    }

    /**
     * Submit returns.
     *
     * @return external_single_structure
     */
    public static function submit_returns(): external_single_structure {
        return new external_single_structure(
            [
                'success' => new external_value(PARAM_BOOL, 'Was it a success?'),
                'errors' => new external_multiple_structure(new external_single_structure(
                    [
                        'code' => new external_value(PARAM_TEXT, 'Code'),
                        'msg' => new external_value(PARAM_RAW, 'Message'),
                    ]
                )),
                'data' => new external_single_structure(
                    [
                        'requestids' => new external_multiple_structure(
                                new external_value(PARAM_INT, 'Request ID')),
                        'nexturl' => new external_value(PARAM_RAW, 'Next URL', VALUE_OPTIONAL, '#'),
                    ]
                ),
            ]
        );
    }

    /**
     * Map wizard mode to a backup target_target constant.
     *
     * new     => \backup::TARGET_NEW_COURSE (2)
     * replace => \backup::TARGET_EXISTING_DELETING (3)
     * merge   => \backup::TARGET_EXISTING_ADDING (4)
     *
     * @param string $mode
     * @return int
     */
    protected static function mode_to_target(string $mode): int {
        switch ($mode) {
            case 'replace':
                return \backup::TARGET_EXISTING_DELETING;
            case 'merge':
                return \backup::TARGET_EXISTING_ADDING;
            case 'new':
            default:
                return \backup::TARGET_NEW_COURSE;
        }
    }

    /**
     * Normalise remote errors (stdClass with code/msg) into the array shape
     * expected by the external returns.
     *
     * @param array|null $errors
     * @return array
     */
    protected static function normalize_errors($errors): array {
        $out = [];
        if (is_array($errors)) {
            foreach ($errors as $error) {
                $out[] = [
                    'code' => isset($error->code) ? (string)$error->code : '0',
                    'msg' => isset($error->msg) ? $error->msg : '',
                ];
            }
        }
        return $out;
    }

}
