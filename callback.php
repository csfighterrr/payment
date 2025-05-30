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
 * Listens to any callbacks from ipaymu.
 *
 * @package   enrol_ipaymu
 * @copyright 2024 Syaifudin <syaifudin@ipaymu.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use enrol_ipaymu\ipaymu_mathematical_constants;
use enrol_ipaymu\ipaymu_status_codes;
use enrol_ipaymu\ipaymu_helper;

// This script does not require login.
require("../../config.php"); // phpcs:ignore
require_once("lib.php");
require_once("{$CFG->libdir}/enrollib.php");
require_once("{$CFG->libdir}/filelib.php");

// Make sure we are enabled in the first place.
if (!enrol_is_enabled('ipaymu')) {
    http_response_code(503);
    throw new moodle_exception('errdisabled', 'enrol_ipaymu');
}

// Attempt to get data from request body
$post = file_get_contents('php://input');
$request = json_decode($post);

// Check if merchantOrderId is in POST/GET params or in JSON body
if (optional_param('merchantOrderId', '', PARAM_TEXT) !== '') {
    $merchantorderid = required_param('merchantOrderId', PARAM_TEXT);
} else if (isset($request->merchantOrderId)) {
    $merchantorderid = $request->merchantOrderId;
} else {
    throw new moodle_exception('invalidrequest', 'core_error', '', null, 'Missing merchantOrderId parameter');
}

// Making sure that merchant order id is in the correct format.
$custom = explode('-', $merchantorderid);
$userid = $custom[1];
$courseid = $custom[2];
$instanceid = $custom[3];

// Initialize sid and trx_id variables safely
$sid = isset($request->sid) ? $request->sid : optional_param('sid', '', PARAM_TEXT);
$trx_id = isset($request->trx_id) ? $request->trx_id : optional_param('trx_id', '', PARAM_TEXT);

if (empty($trx_id)) {
    throw new moodle_exception('invalidrequest', 'core_error', '', null, 'Missing transaction ID');
}

$ipaymuhelper = new ipaymu_helper();
$check = $ipaymuhelper->check_transaction($trx_id);

if (!isset($check['res']['Status']) || !isset($check['res']['Data']['PaidStatus'])) {
    throw new moodle_exception('invalidrequest', 'core_error', '', null, 'Invalid transaction data');
}

if ($check['res']['Data']['PaidStatus'] != 'paid') {
    throw new moodle_exception('invalidrequest', 'core_error', '', null, 'Payment Failed');
}

$data = new stdClass();
$data->userid = (int)$userid;
$data->courseid = (int)$courseid;
$user = $DB->get_record("user", ["id" => $userid], "*", MUST_EXIST);
$course = $DB->get_record("course", ["id" => $courseid], "*", MUST_EXIST);
$context = context_course::instance($courseid, MUST_EXIST);
$PAGE->set_context($context);

// Set enrolment duration (default from Moodle).
// Only accessible if all required parameters are available.
$data->instanceid = (int)$instanceid;
$plugininstance = $DB->get_record("enrol", ["id" => $data->instanceid, "enrol" => "ipaymu", "status" => 0], "*", MUST_EXIST);
$plugin = enrol_get_plugin('ipaymu');
if ($plugininstance->enrolperiod) {
    $timestart = time();
    $timeend = $timestart + $plugininstance->enrolperiod;
} else {
    $timestart = 0;
    $timeend = 0;
}

// Enrol user and update database.
$plugin->enrol_user($plugininstance, $user->id, $plugininstance->roleid, $timestart, $timeend);

// Add to log that callback has been received and student enrolled.
$eventarray = [
    'context' => $context,
    'relateduserid' => (int)$userid,
    'other' => [
        'Log Details' => get_string('log_callback', 'enrol_ipaymu'),
        'merchantOrderId' => $merchantorderid,
        'reference' => $sid
    ]
];
$ipaymuhelper->log_request($eventarray);

$admin = get_admin(); // Only 1 MAIN admin can exist at a time.

$params = [
    'userid' => (int)$userid,
    'courseid' => (int)$courseid,
    'instanceid' => (int)$instanceid,
    'reference' => $sid
];
$sql = 'SELECT * FROM {enrol_ipaymu}
WHERE userid = :userid AND courseid = :courseid AND instanceid = :instanceid AND reference = :reference
ORDER BY {enrol_ipaymu}.timestamp DESC';

$existingdata = $DB->get_record_sql($sql, $params, 1);

$data->id = $existingdata->id;
$data->payment_status = 'Success';
$data->pending_reason = get_string('log_callback', 'enrol_ipaymu');
$data->timeupdated = round(microtime(true) * ipaymu_mathematical_constants::SECOND_IN_MILLISECONDS);

$DB->update_record('enrol_ipaymu', $data);

// Standard mail sending by Moodle to notify users if there are enrolments.
// Pass $view=true to filter hidden caps if the user cannot see them.
if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC', '', '', '', '', false, true)) {
    $users = sort_by_roleassignment_authority($users, $context);
    $teacher = array_shift($users);
} else {
    $teacher = false;
}

$mailstudents = $plugin->get_config('mailstudents');
$mailteachers = $plugin->get_config('mailteachers');
$mailadmins = $plugin->get_config('mailadmins');
$shortname = format_string($course->shortname, true, ['context' => $context]);

// Setup the array that will be replace the variables in the custom email html.
$maildata = [
    '$courseFullName' => format_string($course->fullname, true, array('context' => $context)),
    '$amount' => $amount,
    '$courseShortName' => $shortname,
    '$studentUsername' => fullname($user),
    '$courseFullName' => format_string($course->fullname, true, array('context' => $context)),
    '$teacherName' => empty($teacher) ? core_user::get_support_user() : $teacher->username,
    '$adminUsername' => $admin->username
];

// Setup the array that will be replace the variables in the email template.
$a = new stdClass();
$a->shortname = $shortname;
$a->adminUsername = $admin->username;
$a->studentUsername = fullname($user);
$a->amount = $amount;
$a->courseFullName = format_string($course->fullname, true, array('context' => $context));
$a->teachername = empty($teacher) ? core_user::get_support_user() : $teacher->username;
$templatedata = new stdClass();

if (!empty($mailstudents)) {
    $userfrom = empty($teacher) ? core_user::get_support_user() : $teacher;
    $subject = get_string("enrolmentnew", 'enrol', $shortname);
    $templatedata->student_email_template_header = format_text(
        get_string('student_email_template_header', 'enrol_ipaymu'),
        FORMAT_MOODLE
    );
    $templatedata->student_email_template_greeting = format_text(
        get_string('student_email_template_greeting', 'enrol_ipaymu', $a),
        FORMAT_MOODLE
    );
    $templatedata->student_email_template_body = format_text(
        get_string('student_email_template_body', 'enrol_ipaymu', $a),
        FORMAT_MOODLE
    );
    $studentemail = $plugin->get_config('student_email');
    $studentemail = html_entity_decode($studentemail);
    if (empty($studentemail) === true) {
        $fullmessage = $OUTPUT->render_from_template('enrol_ipaymu/ipaymu_mail_for_students', $templatedata);
    } else {
        $fullmessage = strtr($studentemail, $maildata);
    }

    // Send test email.
    ob_start();
    $success = email_to_user($user, $userfrom, $subject, $fullmessage);
    $smtplog = ob_get_contents();
    ob_end_clean();
}

if (!empty($mailteachers) && !empty($teacher)) {
    $subject = get_string("enrolmentnew", 'enrol', $shortname);
    $teacheremail = $plugin->get_config('teacher_email');
    $templatedata->teacher_email_template_header = format_text(
        get_string('teacher_email_template_header', 'enrol_ipaymu', $a),
        FORMAT_MOODLE
    );
    $templatedata->teacher_email_template_greeting = format_text(
        get_string('teacher_email_template_greeting', 'enrol_ipaymu', $a),
        FORMAT_MOODLE
    );
    $templatedata->teacher_email_template_body = format_text(
        get_string('teacher_email_template_body', 'enrol_ipaymu', $a),
        FORMAT_MOODLE
    );

    if (empty($teacheremail) === true) {
        $fullmessage = $OUTPUT->render_from_template('enrol_ipaymu/ipaymu_mail_for_teachers', $templatedata);
    } else {
        $fullmessage = strtr($teacheremail, $maildata);
    }

    // Send test email.
    ob_start();
    $success = email_to_user($teacher, $user, $subject, $fullmessage, $fullmessagehtml);
    $smtplog = ob_get_contents();
    ob_end_clean();
}

if (!empty($mailadmins)) {
    $adminemail = $plugin->get_config('admin_email');
    $admins = get_admins();
    foreach ($admins as $admin) {
        $subject = get_string("enrolmentnew", 'enrol', $shortname);
        $maildata['$adminUsername'] = $admin->username;
        $templatedata->admin_email_template_header = format_text(
            get_string('admin_email_template_header', 'enrol_ipaymu', $a),
            FORMAT_MOODLE
        );
        $templatedata->admin_email_template_greeting = format_text(
            get_string('admin_email_template_greeting', 'enrol_ipaymu', $a),
            FORMAT_MOODLE
        );
        $templatedata->admin_email_template_body = format_text(
            get_string('admin_email_template_body', 'enrol_ipaymu', $a),
            FORMAT_MOODLE
        );
        $templatedata->adminUsername = $admin->username;

        if (empty($adminemail) === true) {
            $fullmessage = $OUTPUT->render_from_template('enrol_ipaymu/ipaymu_mail_for_admins', $templatedata);
        } else {
            $fullmessage = strtr($adminemail, $maildata);
        }

        // Send test email.
        ob_start();
        echo ($fullmessagehtml . '<br />');
        $success = email_to_user($admin, $user, $subject, $fullmessage, $fullmessagehtml);
        $smtplog = ob_get_contents();
        ob_end_clean();
    }
}
echo 'Success';
return;
