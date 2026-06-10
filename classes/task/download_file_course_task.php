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
 * logs_course_response_table
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfer\task;

use context_course;
use curl;
use dml_exception;
use local_coursetransfer\coursetransfer;
use local_coursetransfer\coursetransfer_request;
use local_coursetransfer\coursetransfer_restore;
use moodle_exception;
use stdClass;

/**
 * logs_course_response_table
 *
 * @package    local_coursetransfer
 * @copyright  2023 Proyecto UNIMOODLE
 * @author     UNIMOODLE Group (Coordinator) <direccion.area.estrategia.digital@uva.es>
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class download_file_course_task extends \core\task\adhoc_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * Execute.
     *
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function execute() {

        $this->log_start("Download File Backup Course Remote and Restore Starting...");
        $fileurle = $this->get_custom_data()->fileurl;
        $requestid = $this->get_custom_data()->requestid;
        $request = coursetransfer_request::get($requestid);

        try {
            // Mark as "downloading" so the request shows progress while the file is fetched.
            $request->status = coursetransfer_request::STATUS_DOWNLOAD;
            coursetransfer_request::insert_or_update($request, $request->id);

            // Download with Moodle's cURL client so the HTTP response can be inspected.
            $curl = new curl();
            $filecontent = $curl->get($fileurle);
            $info = $curl->get_info();
            $httpcode = (int)($info['http_code'] ?? 0);

            // 1. Transport / HTTP error.
            if ($curl->get_errno() || $httpcode !== 200) {
                $this->set_request_error($request, '13001',
                        'HTTP ' . $httpcode . ': ' . ($curl->error !== '' ? $curl->error : 'request failed in file download'));
                $this->log_finish("Download File Backup Course Remote and Restore Finishing...");
                return;
            }

            // 2. The body is a Moodle web service error (JSON) instead of the MBZ.
            // A valid .mbz is gzip-compressed: it starts with the magic bytes 0x1f 0x8b.
            if (substr((string)$filecontent, 0, 2) !== "\x1f\x8b") {
                $error = json_decode($filecontent);
                if ($error && !empty($error->errorcode)) {
                    // e.g. "sitepolicynotagreed: No ha aceptado la política del sitio [debuginfo]".
                    $msg = $error->errorcode . ': ' . (isset($error->error) ? $error->error : '');
                    if (!empty($error->debuginfo)) {
                        $msg .= ' [' . $error->debuginfo . ']';
                    }
                    $this->set_request_error($request, '13002', $msg);
                } else {
                    $this->set_request_error($request, '13003',
                            'Downloaded file is not a valid MBZ backup (' . strlen((string)$filecontent) . ' bytes)');
                }
                $this->log_finish("Download File Backup Course Remote and Restore Finishing...");
                return;
            }

            // 3. Valid backup: store it and queue the restore.
            $fs = get_file_storage();
            $this->log('Backup File Dowload Success!');
            $context = context_course::instance($request->target_course_id);
            $filename = 'local_coursetransfer_' . $request->origin_course_id . '_' . time() . '.mbz';
            $fileinfo = [
                    'contextid' => $context->id,
                    'component' => 'backup',
                    'filearea' => 'course',
                    'itemid' => 0,
                    'filepath' => '/',
                    'filename' => $filename,
            ];
            $file = $fs->create_file_from_string($fileinfo, $filecontent);
            $this->log('Backup File Dowload in Moodle Success!');
            $request->status = coursetransfer_request::STATUS_DOWNLOADED;
            coursetransfer_request::insert_or_update($request, $request->id);
            coursetransfer_restore::create_task_restore_course($request, $file);
        } catch (\Exception $e) {
            $this->set_request_error($request, '13000', $e->getMessage());
        }
        $this->log_finish("Download File Backup Course Remote and Restore Finishing...");
    }

    /**
     * Mark the request as errored, log the message and persist it.
     *
     * @param stdClass $request Request record.
     * @param string $code Error code.
     * @param string $message Error message.
     * @throws dml_exception
     * @throws moodle_exception
     */
    private function set_request_error(stdClass $request, string $code, string $message): void {
        $this->log($message);
        $request->status = coursetransfer_request::STATUS_ERROR;
        $request->error_code = $code;
        $request->error_message = $message;
        coursetransfer_request::insert_or_update($request, $request->id);
    }

}
