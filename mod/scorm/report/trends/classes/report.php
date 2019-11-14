<?php
// This file is part of SCORM trends report for Moodle
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
 * Core Report class of graphs reporting plugin
 *
 * @package    scormreport_trends
 * @copyright  2013 onwards Ankit Kumar Agarwal <ankit.agrr@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace scormreport_trends;

defined('MOODLE_INTERNAL') || die();

/**
 * Main class for the trends report
 *
 * @package    scormreport_trends
 * @copyright  2013 onwards Ankit Agarwal
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report extends \mod_scorm\report {
    /**
     * Displays the trends report
     *
     * @param \stdClass $scorm full SCORM object
     * @param \stdClass $cm - full course_module object
     * @param \stdClass $course - full course object
     * @param string $download - type of download being requested
     *
     * @return bool true on success
     */
    public function display($scorm, $cm, $course, $download) {
        global $DB, $OUTPUT, $PAGE;

        // Groups are being used, Display a form to select current group.
        if (groups_get_activity_groupmode($cm)) {
                groups_print_activity_menu($cm, new \moodle_url($PAGE->url));
        }

        $allowedlist = self::get_students_allowedlist($cm);

        if (empty($allowedlist)) {
            // No students to report.
            echo $OUTPUT->notification(get_string('noactivity', 'scorm'));
            return true;
        }

        // Do this only if we have students to report.
        list($usql, $params) = $DB->get_in_or_equal($allowedlist);

        // Construct the SQL.
        $select = 'SELECT DISTINCT '.$DB->sql_concat('st.userid', '\'#\'', 'COALESCE(st.attempt, 0)').' AS uniqueid, ';
        $select .= 'st.userid AS userid, st.scormid AS scormid, st.attempt AS attempt, st.scoid AS scoid ';
        $from = 'FROM {scorm_scoes_track} st ';
        $where = ' WHERE st.userid ' .$usql. ' and st.scoid = ?';

        $scoeswhere = "scorm = :scorm AND launch != ''";
        $scoes = $DB->get_records_select('scorm_scoes', $scoeswhere, array("scorm" => $scorm->id), 'id');
        foreach ($scoes as $sco) {
            echo $OUTPUT->heading($sco->title);
            $sqlargs = array_merge($params, array($sco->id));
            $attempts = $DB->get_records_sql($select.$from.$where, $sqlargs);
            self::display_sco_table($sco, $attempts);
        }
        return true;
    }

    /**
     * Returns The maximum numbers of Questions associated with a Sco object
     *
     * @param int $scoid Sco ID
     *
     * @return int an integer representing the question count
     */
    protected static function get_sco_question_count($scoid) {
        global $DB;
        $count = 0;
        $params = array();
        $select = "scoid = ? AND ";
        $select .= $DB->sql_like("element", "?", false);
        $params[] = $scoid;
        $params[] = "cmi.interactions_%.id";
        $rs = $DB->get_recordset_select("scorm_scoes_track", $select, $params, 'element');
        $keywords = array("cmi.interactions_", ".id");
        if ($rs->valid()) {
            foreach ($rs as $record) {
                $num = trim(str_ireplace($keywords, '', $record->element));
                if (is_numeric($num) && $num > $count) {
                    $count = $num;
                }
            }
            // Done as interactions start at 0 (do only if we have something to report).
            $count++;
        }
        $rs->close(); // Closing recordset.
        return $count;
    }

    /**
     * Get list of students allowed.
     *
     * @param Object $cm course module
     *
     * @return array
     */
    protected static function get_students_allowedlist($cm) {

        $contextmodule = \context_module::instance($cm->id);
        // Find out current group.
        $currentgroup = groups_get_activity_group($cm, true);

        // Group Check.
        if (empty($currentgroup)) {
            // All users who can attempt scoes.
            $students = get_users_by_capability($contextmodule, 'mod/scorm:savetrack', 'u.id' , '', '', '', '', '', false);
            $allowedlist = empty($students) ? array() : array_keys($students);
        } else {
            // All users who can attempt scoes and who are in the currently selected group.
            $groupstudents = get_users_by_capability($contextmodule, 'mod/scorm:savetrack',
                                'u.id', '', '', '', $currentgroup, '', false);
            $allowedlist = empty($groupstudents) ? array() : array_keys($groupstudents);
        }

        return $allowedlist;
    }

    /**
     * Display table for a given sco.
     *
     * @param Object $sco Sco object.
     * @param array $attempts Array of attempts.
     *
     * @return bool
     */
    protected static function display_sco_table($sco, $attempts) {

        $tabledata = self::get_tabledata($sco, $attempts);
        if (empty($tabledata)) {
            return true;
        }

        $table = new table('mod-scorm-trends-report-'.$sco->id);

        // Format data for tables and generate output.
        foreach ($tabledata as $interaction => $rowinst) {
            foreach ($rowinst as $element => $data) {
                foreach ($data as $value => $freq) {
                    $formateddata = array(get_string('questionfreq', 'scormreport_trends', $interaction),
                        " - <b>$element</b>", $value, $freq);
                    $table->add_data($formateddata);
                }
            }
        }
        $table->finish_output();
        // End of generating output.
        return true;
    }

    /**
     * Get data for table.
     *
     * @param Object $sco Sco object.
     * @param array $attempts Array of attempts.
     *
     * @return array table data.
     */
    protected static function get_tabledata($sco, $attempts) {
        // Determine maximum number to loop through.
        $loop = self::get_sco_question_count($sco->id);

        $tabledata = [];
        for ($i = 0; $i < $loop; $i++) {
            $rowdata = array(
                'type' => array(),
                'student_response' => array(),
                'result' => array());
            foreach ($attempts as $attempt) {
                if ($trackdata = scorm_get_tracks($sco->id, $attempt->userid, $attempt->attempt)) {
                    foreach ($trackdata as $element => $value) {
                        if (stristr($element, "cmi.interactions_$i.type") !== false) {
                            if (isset($rowdata['type'][$value])) {
                                $rowdata['type'][$value]++;
                            } else {
                                $rowdata['type'][$value] = 1;
                            }
                        } else if (stristr($element, "cmi.interactions_$i.student_response") !== false) {
                            if (isset($rowdata['student_response'][$value])) {
                                $rowdata['student_response'][$value]++;
                            } else {
                                $rowdata['student_response'][$value] = 1;
                            }
                        } else if (stristr($element, "cmi.interactions_$i.result") !== false) {
                            if (isset($rowdata['result'][$value])) {
                                $rowdata['result'][$value]++;
                            } else {
                                $rowdata['result'][$value] = 1;
                            }
                        }
                    }
                }
            } // End of foreach loop of attempts.
            $tabledata[] = $rowdata;
        }// End of foreach loop of interactions loop
        return $tabledata;
    }
}
