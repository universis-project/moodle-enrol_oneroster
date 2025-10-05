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
 * One Roster Enrolment Client.
 *
 * @package    enrol_oneroster
 * @copyright  Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_oneroster\local\v1p1;

use core\output\progress_trace\text_progress_trace;
use DateTime;
use Exception;
use context_user;
use core_course_category;
use core_php_time_limit;
use core_user;
use enrol_oneroster\client as client_base;
use enrol_oneroster\local\converter;

// Client and associated features.
use enrol_oneroster\local\interfaces\container as container_interface;
use enrol_oneroster\local\interfaces\rostering_endpoint as rostering_endpoint_interface;

// Entities which represent Moodle objects.
use enrol_oneroster\local\interfaces\course_representation;
use enrol_oneroster\local\interfaces\coursecat_representation;
use enrol_oneroster\local\interfaces\user_representation;
use enrol_oneroster\local\interfaces\enrollment_representation;

use enrol_oneroster\local\collections\orgs as orgs_collection;
use enrol_oneroster\local\collections\schools as schools_collection;
use enrol_oneroster\local\collections\terms as terms_collection;
use enrol_oneroster\local\entities\class_entity;
use enrol_oneroster\local\v1p1\endpoints\rostering as rostering_endpoint;
use enrol_oneroster\local\entities\org as org_entity;
use enrol_oneroster\local\entities\school as school_entity;
use enrol_oneroster\local\entities\user as user_entity;
use enrol_oneroster\local\entities\term as term_entity;
use enrol_oneroster\local\entities\academic_session as academic_session_entity;
use enrol_oneroster\local\collections\academic_sessions as academic_sessions_collection;
use mod_bigbluebuttonbn\local\helpers\reset;
use moodle_url;
use progress_trace;
use stdClass;

function uuid_make($string){
    return substr($string, 0, 8 ) .'-'.
    substr($string, 8, 4) .'-'.
    substr($string, 12, 4) .'-'.
    substr($string, 16, 4) .'-'.
    substr($string, 20);
  }


/**
 * One Roster v1p1 client.
 *
 * @package    enrol_oneroster
 * @copyright  Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait oneroster_client {

    /** @var array List of the entries which have been recently modified to reduce churn */
    protected $modifiedentities = [];

    /** @var stdClass[] List of existing enrolment instances for the plugin */
    protected $instances = [];

    /** @var int[] List of user idnumber => userid */
    protected $usermappings = null;

    /** @var stdClass List of mappings from One Roster role name to Moodle Role ID */
    protected $rolemappings = null;

    /** @var array Plugin configuration */
    protected $pluginconfig = null;

    /** @var array List of applicable context levels for each role */
    protected $rolecontextlevels = null;

    /** @var array List of existing role assignmenets */
    protected $existingroleassignments = [];

    /** @var array List of tracking metrics */
    protected $metrics = [];

    /**
     * @var stdClass[] Cache of all academic sessions
     */
    protected $all_academic_sessions = null;

    /**
     * Get the Base URL for this One Roster API version.
     *
     * @param string $server The hostname
     * @return moodle_url
     */
    protected function get_base_url(string $server): moodle_url {
        // As per https://www.imsglobal.org/oneroster-v11-final-specification#_Toc480451989
        // The API Root URL MUST be /ims/oneroster.
        //
        // To allow further versions of the specification to exist in a controlled manner, the new version number MUST be '/v1p1'.
        return new moodle_url("{$server}/ims/oneroster/v1p1");
    }

    /**
     * Get all of the scopes required for this OAuth2 Implementation.
     *
     * @return string[]
     */
    protected function get_all_scopes(): array {
        return array_merge(
            rostering_endpoint::get_required_scopes()
        );
    }

    /**
     * Get the rostering endpoint for this version of the API.
     *
     * @return rostering
     */
    public function get_rostering_endpoint(): rostering_endpoint_interface {
        return new rostering_endpoint($this->get_container());
    }

    /**
     * Get the entity factory for this One Roster implementation.
     *
     * @return  container_interface
     */
    public function get_container(): container_interface {
        if ($this->container === null) {
            $this->container = new container($this);
        }

        return $this->container;
    }

    /**
     * Sync the roster.
     *
     * @param   int $onlysincetime
     */
    public function sync_roster(?int $onlysincetime = null, ?array $filter = null): void {
        global $DB;
        global $CFG;

        // Most systems do not have many organisations in them.
        // Fetch all organisations to add them to the cache.
        $this->fetch_organisation_list();

        $schoolidstosync = explode(',', get_config('enrol_oneroster', 'datasync_schools'));
        // if filter contains school, only sync that school
        if (is_array($filter) && array_key_exists('school', $filter)) {
            $schoolidstosync = [$filter['school']];
        }
        $countofschools = count($schoolidstosync);

        $this->get_trace()->output("Processing {$countofschools} schools");

        $onlysince = null;
        if ($onlysincetime) {
            // Only fetch users last modified in the onlysince period.
            $onlysince = new DateTime();
            $onlysince->setTimestamp($onlysincetime);
        }

        // Synchronise all users.
        // One Roster does not provide a way of fetching users relating to a specific school.
        // All users for all supported schools will be created first.
        $this->get_trace()->output("Updating the user roster", 1);

        // Only fetch users last modified in the past day.
        // All timezones in One Roster are Zulu.
        //$this->sync_users_in_schools($schoolidstosync, $onlysince);

        // Fetch the details of all enrolment instances before running the sync.
        $this->cache_enrolment_instances();

        // Synchronise all courses, classes, and enrolments.
        foreach ($schoolidstosync as $schoolidtosync) {
            $this->get_trace()->output("Fetching school with sourcedId '{$schoolidtosync}'", 2);
            $school = $this->get_container()->get_entity_factory()->fetch_org_by_id($schoolidtosync);
            if ($school instanceof school_entity) {
                $school_name = $school->get('name');
                $this->get_trace()->output("Synchronising school '{$schoolidtosync} {$school_name}'", 2);
                // use try catch to continue with next school if any error
                try {
                    $this->sync_school($school, $onlysince, $filter);
                } 
                catch (Exception $e) {
                    $this->get_trace()->output("Error synchronising school '{$schoolidtosync} {$school_name}'", 2);
                    $this->get_trace()->output("Error '{$e->getMessage()}'", 2);
                    // if ($CFG->debugdeveloper) { // DEBUG_DEVELOPER
                    //     $this->get_trace()->output($e->getTraceAsString(), 3);
                    // }   
                }
            } else {
                $this->get_trace()->output("Organisation with sourcedId '{$schoolidtosync}' is not a school. Skipping.", 3);
            }
        }
        
        $this->get_trace()->output("Completed synchronisation of Rostering information");
        if ($this->get_trace() instanceof text_progress_trace) {
            $this->get_trace()->output(sprintf("Entity\t\tCreate\tUpdate\tExclude\tDelete"), 1);
            foreach ($this->get_metrics() as $thing => $actions) {
                $this->get_trace()->output(
                    sprintf(
                        "Entity '%s'\t%d\t%d\t%d\t%d",
                        $thing,
                        $actions['create'],
                        $actions['update'],
                        $actions['exclude'],
                        $actions['delete']
                    ),
                    1
                );
            }
        }
    }

    /**
     * Fetch current enrolment data into memory for later operations.
     */
    protected function fetch_current_enrolment_data($courseid): void {
        global $DB;

        $sql = <<<EOF
      SELECT
            e.id AS enrolid,
            ue.id AS ueid,
            ue.userid AS userid,
            ra.roleid
        FROM {user_enrolments} ue
        JOIN {enrol} e ON ue.enrolid = e.id
   LEFT JOIN {role_assignments} ra ON ra.component = :component AND ra.itemid = e.id AND ra.userid=ue.userid
       WHERE e.enrol = :enrol AND e.courseid = :courseid
EOF;

        $rs = $DB->get_recordset_sql($sql, [
            'component' => 'enrol_oneroster',
            'enrol' => 'oneroster',
            'courseid' => $courseid
        ]);

        // clear array
        $this->existingroleassignments = [];
        foreach ($rs as $row) {
            if (!array_key_exists($row->enrolid, $this->existingroleassignments)) {
                $this->existingroleassignments[$row->enrolid] = [];
            }
            if (!array_key_exists($row->userid, $this->existingroleassignments[$row->enrolid])) {
                $this->existingroleassignments[$row->enrolid][$row->userid] = [];
            }
            $this->existingroleassignments[$row->enrolid][$row->userid][$row->roleid] = true;
        }
        $rs->close();
    }

    /**
     * Synchronise all users in the Schools.
     *
     * @param   int[] $schoolids
     * @param   DateTime|null $onlysince Only sync users which have been remotely modified since the specified date
     */
    public function sync_users_in_schools(array $schoolids, ?DateTime $onlysince = null): void {
        $filter = null;
        if ($onlysince) {
            // Only fetch users last modified in the onlysince period.
            $filter = new filter('dateLastModified',  $onlysince->format('o-m-d'), '>');
        }

        // Note: Some Endpoints do not sort properly on Array properties.
        $users = $this->get_container()->get_collection_factory()->get_users(
            [],
            $filter,
            function($data) use ($schoolids) {
                $foundids = array_map(function($orgref) {
                    return $orgref->sourcedId;
                }, $data->get('orgs'));

                return !!count(array_intersect($schoolids, $foundids));
            }
        );

        $usercount = 0;
        foreach ($users as $user) {
            $this->update_or_create_user($user);
            $usercount++;
        }
        $this->get_trace()->output("Finished processing users. Processed {$usercount} users", 3);
    }

    private function get_course_metadata($courseid) {
        $handler = \core_customfield\handler::get_handler('core_course', 'course');
        // This is equivalent to the line above.
        //$handler = \core_course\customfield\course_handler::create();
        $datas = $handler->get_instance_data($courseid, true);
        $metadata = [];
        foreach ($datas as $data) {
            if (empty($data->get_value())) {
                continue;
            }
            $field = $data->get_field();
            //$cat = $field->get_category()->get('name');
            // get field type
            $type = $field->get('type');
            if ($type === 'select') {
                $value = intval($data->get_value()) - 1;
                // get options
                $options = $field->get('configdata')['options'];
                // options is a \n separated list of values
                $options = array_map('trim', explode("\n", $options));
                if ($options && array_key_exists($value, $options)) {
                    $metadata[$field->get('shortname')] = $options[$value];
                } else {
                    $metadata[$field->get('shortname')] = '-';
                }
            } else {
                $metadata[$field->get('shortname')] = $data->get_value();
            }
        }
        return $metadata;
    }

    private function get_all_academic_sessions(): array {
        if ($this->all_academic_sessions !== null) {
            return $this->all_academic_sessions;
        }
        // get all academic sessions
        /**
         * @var academic_sessions_collection $academic_sessions
         */
        $academic_session_collection = $this->get_container()->get_collection_factory()->get_academic_sessions([]);
        /**
         * @var stdClass[]
         * 
         */
        $this->all_academic_sessions = [];
        foreach ($academic_session_collection as $session) {
            $this->all_academic_sessions[] = $session->get_data();
        }
        return $this->all_academic_sessions;
    }

    /**
     * Synchronise the entire School.
     *
     * @param   school_entity $school
     * @param   null|DateTime $onlysince
     */
    public function sync_school(school_entity $school, ?DateTime $onlysince = null, ?array $filter = null): void {
        global $CFG, $DB;
        require_once("{$CFG->dirroot}/group/lib.php");
        require_once("{$CFG->dirroot}/course/lib.php");
        // Updating the category for this school.
        $this->update_or_create_category($school);

        $this->get_trace()->output("Fetching term data", 3);
        foreach ($school->get_terms() as $term) {
            // Nullop to cache terms.
            continue;
        }

        $classfilter = new filter();
        if ($onlysince) {
            // Only fetch users last modified in the onlysince period.
            $classfilter->add_filter('dateLastModified',  $onlysince->format('o-m-d'), '>');
        }

        if ($filter) {
            foreach ($filter as $key => $value) {
                if ($key == 'school') {
                    continue;
                }
                $classfilter->add_filter($key, $value);
            }
        }

        // select active academic session
        $academic_session = get_config('enrol_oneroster', 'datasync_academic_session');
        if ($academic_session) {
            // get academic session
            $academic_session_filter = new filter();
            $academic_session_filter->add_filter('sourcedId', $academic_session, '=');
            $academic_sessions = $this->get_container()->get_collection_factory()->get_academic_sessions([], $academic_session_filter);
            foreach ($academic_sessions as $session) {
                // exit the loop to get session
                break;
            }
            if ($session instanceof academic_session_entity) {
               // get academic session type
                $sessiontype = $session->get('type');
                if ($sessiontype == 'schoolYear') {
                    // if school year, include semesters (filtering by parent)
                    $classfilter->add_filter('terms.parent', $session->get('sourcedId'), '=');
                } else {
                    // otherwise, filter by the exact term
                    $classfilter->add_filter('terms.sourcedId', $academic_session, '=');
                }
            } else {
                // if no session found, filter by the exact term
                $classfilter->add_filter('terms.sourcedId', $academic_session, '=');
            }
        }

        $this->get_trace()->output("Fetching class data", 3);
        $classes = $school->get_classes([], $classfilter);
        // get class snapshot cache
        $snapshots = $this->container->get_cache_factory()->get_class_snapshot_cache();
        // use class groups
        $use_class_groups = get_config('enrol_oneroster', 'oneroster_sync_groups');

        $keep_existing_class = get_config('enrol_oneroster', 'keep_existing_class');
        /**
         * get all academic sessions
         * @var stdClass[]
         */
        $academic_sessions = $this->get_all_academic_sessions();

        foreach ($classes as $class) {
            // status to track if class has been linked to an existing course
            $class_linked = false;
            // get class snapshot
            $sourcedid = $class->get('sourcedId');
            $snapshot = $snapshots->get($sourcedid);
            if ($snapshot) {
                // get class last modified date
                $lastmodified = converter::from_datetime_to_unix($class->get('dateLastModified'));
                // and snapshot last modified date
                $datelastmodified = converter::from_datetime_to_unix($snapshot->dateLastModified);
                // if class has not been modified after last snapshot
                if ($lastmodified != null && $datelastmodified >= $lastmodified) {
                    // do nothing and continue
                    $this->get_trace()->output(
                        sprintf(
                            "Skipping class '%s' with id %s as it has not been modified",
                            $class->get('title'),
                            $class->get('sourcedId')
                        ),
                        4
                    );
                    continue;
                }
            }

            $this->get_trace()->output(
                sprintf(
                    "Synchronising course '%s' with id %s",
                    $class->get('title'),
                    $class->get('sourcedId')
                ),
                4
            );

            // before updating or creating course, check if course exists for a different term
            $link_courses = [];
            if ($keep_existing_class) {
                // search for existing course with the same idnumber
                $existingcourse = $DB->get_record('course', ['idnumber' => $class->get('sourcedId')]);
                // if course does not exist, search for an existing course associated with a different term 
                if (!$existingcourse) {
                    // get one roster classes filtering by course
                    $course = $class->get('course')->sourcedId;
                    $otherclasses_collection = $this->get_container()->get_collection_factory()->get_classes(
                        [],
                        (new filter())->add_filter('course', $course, '=')
                    );
                    $this->get_trace()->output(
                        sprintf(
                            "Searching for existing course to link to class '%s' with id %s",
                            $class->get('title'),
                            $class->get('sourcedId')
                        ),
                        4   
                    );
                    $otherclasses = [];
                    foreach ($otherclasses_collection as $otherclass) {
                        $otherclasses[] = $otherclass;
                        $otherclass->terms = array_map(function($term) use ($academic_sessions) {
                            return current(array_filter($academic_sessions, function ($value) use ($term) {
                                return $value->sourcedId == $term->sourcedId;
                            }));
                        }, $otherclass->get('terms'));
                    }
                    $this->get_trace()->output(
                        sprintf(
                            "Found %d other classes for course '%s'",
                            count($otherclasses),
                            $course
                        ),
                        4   
                    );
                    // get first term of the class being processed
                    $class_term = current($class->get('terms'));
                    if ($class_term) {
                        $class_term = current(array_filter($academic_sessions, function ($value) use ($class_term) {
                            return $value->sourcedId == $class_term->sourcedId;
                        }));
                    }
                    // filter other classes to only those that have a term in common with the class being processed
                    $otherclasses = array_filter($otherclasses, function($otherclass) use ($class_term, $class) {
                        if ($otherclass->get('sourcedId') == $class->get('sourcedId')) {
                            return false;
                        }
                        // filter out classes that do not have any term in common with the class being processed
                        $otherclass_term = reset($otherclass->terms);
                        if ($otherclass_term && $otherclass_term->metadata && $otherclass_term->metadata->href) {
                            return $class_term->metadata->href == $otherclass_term->metadata->href;
                        }
                        return false;
                    });

                    $this->get_trace()->output(
                        sprintf(
                            "After filtering by term, found %d other classes for course '%s'",
                            count($otherclasses),
                            $course
                        ),
                        4   
                    );

                    if (count($otherclasses) > 1) {
                        $this->get_trace()->output(
                            sprintf(
                                "Warning: Found %d other classes for course '%s' while processing class '%s' with id %s. Skipping link process.",
                                count($otherclasses),
                                $course,
                                $class->get('title'),
                                $class->get('sourcedId')
                            ),
                            4   
                        );
                        $otherclasses = [];
                    }
                    foreach ($otherclasses as $otherclass) {
                        $existingcourse = $DB->get_record('course', ['idnumber' => $otherclass->get('sourcedId')]);
                        if ($existingcourse) {
                            // get custom field
                            $metadata = $this->get_course_metadata($existingcourse->id);
                            $course_keep_existing_class = $metadata['keep_existing_class'] ?? 'Yes';
                            if (strtolower($course_keep_existing_class) == 'no') {
                                // if keep existing is not set, continue
                                $this->get_trace()->output(
                                    sprintf(
                                        "Skipping class '%s' with id %s as the corresponding course has 'keep_existing_class' set to 'No'",
                                        $otherclass->get('title'),
                                        $otherclass->get('sourcedId')
                                    ),
                                    4
                                );
                                continue;
                            }
                            // we are expecting that the existing course should be associated with
                            // the corresponding academic session of another school/academic year
                            // get first term of the current class
                            $otherclass_term = current($otherclass->get('terms'));
                            if ($otherclass_term) {
                                $otherclass_term = current(array_filter($academic_sessions, function ($value) use ($otherclass_term) {
                                    return $value->sourcedId == $otherclass_term->sourcedId;
                                }));
                            }
                            // if no term for other class, continue
                            if (!$otherclass_term) {
                                $this->get_trace()->output(
                                    sprintf(
                                        "Skipping class '%s' with id %s as it has no term associated",
                                        $otherclass->get('title'),
                                        $otherclass->get('sourcedId')
                                    ),
                                    4
                                );
                                continue;
                            }
                            // "keep existing class" process is trying to find a course associated with a corresponding term
                            // this operation is not really supported by one roster spec (an academic session cannot be identified across different school years)
                            // for supporting this feature, we are assuming that that academic session metadata holds such information
                            // a proposal for having a standard way of identifying academic sessions across school years might be metadata.href attribute 
                            // where the producer of the one roster might include an academic session identifier across school years
                            $otherclass_term->metadata = $otherclass_term->metadata ?? new stdClass();
                            $otherclass_term->metadata->href = $otherclass_term->metadata->href ?? '00000000-0000-0000-0000-000000000001';

                            // get first term of processing class
                            $class_term = current($class->get('terms'));
                            if ($class_term) {
                                $class_term = current(array_filter($academic_sessions, function ($value) use ($class_term) {
                                    return $value->sourcedId == $class_term->sourcedId;
                                }));
                            }
                            // if no term for class, continue
                            if (!$class_term) {
                                $this->get_trace()->output(
                                    sprintf(
                                        "Skipping class '%s' with id %s as it has no term associated",
                                        $class->get('title'),
                                        $class->get('sourcedId')
                                    ),
                                    4
                                );
                                continue;
                            }
                            $class_term->metadata = $class_term->metadata ?? new stdClass();
                            $class_term->metadata->href = $class_term->metadata->href ?? '00000000-0000-0000-0000-000000000002';

                            if ($otherclass_term->metadata->href != $class_term->metadata->href) {
                                // if terms do not match, continue
                                $this->get_trace()->output(
                                    sprintf(
                                        "Skipping class '%s' with id %s as it is associated with a different academic session",
                                        $otherclass->get('title'),
                                        $otherclass->get('sourcedId')
                                    ),
                                    4
                                );
                                continue;
                            }

                            $this->get_trace()->output(
                                sprintf(
                                    "Preparing to link existing course '%s' with id %s to class '%s' with id %s",
                                    $existingcourse->fullname,
                                    $existingcourse->idnumber,
                                    $class->get('title'),
                                    $class->get('sourcedId')
                                ),
                                4
                            );
                            $link_courses[] = (object) array(
                                'id' => $existingcourse->id,
                                'fullname' => $existingcourse->fullname,
                                'idnumber' => $class->get('sourcedId')
                            );
                        }
                        // the link operation did not find any course
                        if (count($link_courses) == 0) {
                                $this->get_trace()->output(
                                    sprintf(
                                        "Link operation did not find existing course for class '%s' with id %s.",
                                        $class->get('title'),
                                        $class->get('sourcedId')
                                    ),
                                    4
                                );
                        } else if (count($link_courses) > 1) { 
                            // if more than one course found, skip linking
                            $this->get_trace()->output(
                                sprintf(
                                    "Link operation found multiple existing courses for class '%s' with id %s. Skipping linking.",
                                    $class->get('title'),
                                    $class->get('sourcedId')
                                ),
                                4
                            );
                            $link_courses = [];
                        } else {
                            // try to link the course
                            $link_course = reset($link_courses);
                            // update course idnumber to the current class
                            $DB->update_record('course', (object) [
                                'id' => $link_course->id,
                                'idnumber' => $link_course->idnumber
                            ]);
                            $this->get_trace()->output(
                                    sprintf(
                                        "Linked existing course '%s' with id %s to class '%s' with id %s",
                                        $existingcourse->fullname,
                                        $existingcourse->idnumber,
                                        $class->get('title'),
                                        $class->get('sourcedId')
                                    ),
                                    4
                                );
                            $this->get_trace()->output(
                                    sprintf(
                                        "Resetting all user data for course '%s' with id %s as it has been linked to an existing course",
                                        $class->get('title'),
                                        $class->get('sourcedId')
                                    ),
                                    4
                                );
                            require_once("{$CFG->dirroot}/course/lib.php");
                            // reset course
                            $student_role = get_archetype_roles('student');
                            
                            $data = (object) array(
                                'id' => $link_course->id,
                                'reset_events' => 1,
                                'reset_notes' => 1,
                                'reset_chat' => 1,
                                'reset_gradebook_items' => 0,
                                'unenrol_users' => array_keys($student_role),
                                'reset_gradebook_grades' => 1,
                                'reset_completion' => 1,
                                'reset_groups' => 0,
                                'reset_groups_members' => 1,
                                'reset_groups_remove' => 0,
                                'reset_groupings' => 0,
                                'reset_groupings_remove' => 0,
                                'reset_groupings_members' => 0,
                                'reset_outcomes' => 0,
                                'reset_forum_subscriptions' => 1,
                                'reset_forum_all' => 1,
                                'reset_forum_types' => 'general,social,blog,eachuser,single,qanda',
                                'reset_drafts' => 1,
                                'reset_user_preferences' => 0,
                                'reset_assign_submissions' => 1,
                                'reset_assign_user_overrides' => 1,
                                'reset_assign_group_overrides' => 1,
                                'reset_quiz_attempts' => 1,
                                'reset_quiz_user_overrides' => 1,
                                'reset_quiz_group_overrides' => 1,
                                'reset_quiz_grades' => 1,
                                'reset_attendance_log' => 1,
                                'reset_attendance_statuses' => 1,
                                'reset_attendance_sessions' => 1,
                                'reset_checklist_progress' => 1,
                                'reset_wiki_comments' => 1,
                                'reset_wiki_tags' => 1,
                                'reset_survey_answers' => 1,
                                'reset_survey_analysis' => 1,
                                'reset_data' => 1,
                                'reset_lesson' => 1,
                                'reset_workshop_submissions' => 1,
                                'reset_workshop_assessments' => 1,
                                'reset_workshop_grades' => 1,
                                'reset_choice' => 1,
                                'reset_choicegroup' => 1,
                                'reset_scorm' => 1,
                                'reset_bookmarks' => 1,
                                'reset_workshop_phase' => 1,
                                'reset_glossary_all' => 0,
                                'reset_glossary_ratings' => 0,
                                'reset_glossary_comments' => 0

                            );
                            $status = reset_course_userdata($data);
                        }
                    }
                }
            }
            
            // update or create class
            $localcourse = $this->update_or_create_course($class);
            if (!$localcourse) {
                continue;
            }
            $this->get_trace()->output(
                sprintf(
                    "Getting existing enrollments for course '%s' with id %s",
                    $class->get('title'),
                    $class->get('sourcedId')
                ),
                5
            );
            $this->fetch_current_enrolment_data($localcourse->id);

            $this->get_trace()->output(
                sprintf(
                    "Synchronizing teacher accounts for '%s'",
                    $class->get('title')
                ),
                5
            );
            // get class students and sync them
            $index = 0;
            $error_count = 0;
            $teachers = $class->get_teachers();
                foreach ($teachers as $teacher) {
                    $index++;
                    try {
                        $this->update_or_create_user($teacher);
                    } catch (Exception $e) {
                        $error_count++;
                        $this->get_trace()->output("Error synchronising teacher '{$teacher->get('username')}'", 4);
                        $this->get_trace()->output("Error '{$e->getMessage()}'", 4);
                        // if ($CFG->debugdeveloper) { // DEBUG_DEVELOPER
                        //     $this->get_trace()->output($e->getTraceAsString(), 4);
                        // }
                    }
                }

            $this->get_trace()->output(
                sprintf(
                    "Finished synchronizing %s teacher account(s) with %s error(s)",
                    $index,
                    $error_count
                ),
                5
            );
            
            $teacher_count = $index;

            $this->get_trace()->output(
                sprintf(
                    "Synchronizing student accounts for '%s'",
                    $class->get('title')
                ),
                5
            );
            $index = 0;
            $error_count = 0;
            // get class students and sync them
            $students = $class->get_students();
                foreach ($students as $student) {
                    $index++;
                    // use try catch to continue with next student if any error
                    try {
                        $this->update_or_create_user($student);
                    } catch (Exception $e) {
                        $error_count++;
                        $this->get_trace()->output("Error synchronising student '{$student->get('username')}'", 4);
                        $this->get_trace()->output("Error '{$e->getMessage()}'", 4);
                    }
                }

            $student_count = $index;

            $this->get_trace()->output(
                sprintf(
                    "Finished synchronizing %s student account(s) with %s error(s)",
                    $index,
                    $error_count
                ),
                5
            );
            if ($teacher_count > 0 || $student_count > 0) {
                // fetching enrollments for class
                $this->get_trace()->output(sprintf("Fetching enrolments data for '%s'", $class->get('title')), 5);
                foreach ($class->get_enrollments() as $enrollment) {
                    $this->update_or_create_enrolment($enrollment);
                    // feature: course group management
                    // description: add user to group
                    if ($use_class_groups) {
                        // add user to group using the enrollment term, if available
                        $enrolment_term = $enrollment->get_enrolment_term();
                        if ($enrolment_term) {
                            // try to find if the current course has a group associated with the term
                            $course_group = $this->create_course_group_from_term($localcourse, $enrolment_term);
                            // get remote user
                            $userentity = $enrollment->get_user_entity();
                            // find local user
                            $localuserid = $this->get_user_mapping_for_user($userentity);
                            if ($localuserid) {
                                // add group membership
                                groups_add_member($course_group, $localuserid, 'enrol_oneroster');
                            }
                        }
                        // get extra enrolment terms
                        $enrolment_terms = $enrollment->get_enrolment_terms();
                        foreach ($enrolment_terms as $term) {
                            // try to find if the current course has a group associated with the term
                            $course_group = $this->create_course_group_from_term($localcourse, $term);
                            // get remote user
                            $userentity = $enrollment->get_user_entity();
                            // find local user
                            $localuserid = $this->get_user_mapping_for_user($userentity);
                            if ($localuserid) {
                                // add group membership
                                groups_add_member($course_group, $localuserid, 'enrol_oneroster');
                            }
                        }
                    }
                }
            }
            foreach ($this->existingroleassignments as $instanceid => $ra) {
                $instance = $DB->get_record('enrol', ['id' => $instanceid]);
                if ($instance === null) {
                    $this->get_trace()->output("No enrolment instance found with id {$instanceid}");
                    continue;
                }
                $context = \context_course::instance($instance->courseid);
                if (count($ra) > 0) {
                    $this->get_trace()->output(
                        sprintf(
                            "Processing %s disenrollment(s) for course with id %s",
                            count($ra),
                            $instance->courseid
                        ),
                        5
                    );
                    // Unassign roles for this user.
                    foreach ($ra as $userid => $roleids) {
                        foreach (array_keys($roleids) as $roleid) {
                            if ($roleid) {
                                role_unassign($roleid, $userid, $context->id, 'enrol_oneroster', $instance->id);
                            }
                        }
                        // unenrol the user
                        $localuser = \core_user::get_user($userid);
                        if ($this->get_trace() instanceof text_progress_trace) {
                            $this->get_trace()->output(sprintf(
                                "Unenroling user %s from course with id %s",
                                $localuser->username,
                                $localcourse->id
                            ), 5);
                        }
                        // feature: course group management
                        // description: remove user from groups
                        if (is_array($localcourse->groups)) {
                            foreach ($localcourse->groups as $group) {
                                groups_remove_member($group, $userid);
                            }
                        }
                        $this->get_plugin_instance()->unenrol_user(
                            $instance,
                            $userid
                        );
                    }
                }
            }

            $this->get_trace()->output(
                sprintf(
                    "Finished synchronising course '%s' with id %s",
                    $class->get('title'),
                    $class->get('sourcedId')
                ),
                4
            );
            // get class data
            $data = $class->get_data();
            // and update snapshot cache
            $snapshots->set($data->sourcedId, $data);
        }
    }

    /**
     * Create course group using the given academic session
     * @param \stdClass $course
     * @param academic_session_entity $term
     * @return \stdClass
     */
    protected function create_course_group_from_term(stdClass $course, academic_session_entity $term): stdClass {
        global $DB;
        // if course groups are not enabled
        if (!is_array($course->groups)) {
            // get groups
            $course->groups = $DB->get_records('groups', ['courseid' => $course->id]);
        }
        // generate group idnumber from course->idnumber and term->sourcedId
        $termid = $term->get('sourcedId');
        $id = array('class' => $course->idnumber, 'term' => $termid);
        $idnumber = uuid_make(md5(json_encode($id)));
        // try to find a course group with the same idnumber
        $found = array_filter($course->groups, function($group) use ($idnumber) {
            return $group->idnumber == $idnumber;
        });
        // if group is found, return it
        if (count($found) > 0) {
            return reset($found);
        }
        // otherwise, create a new group
        $group = new stdClass();
        $group->courseid = $course->id;
        $group->name = $term->get('title');
        $group->idnumber = $idnumber; // set idnumber
        $group->timecreated = time();
        $group->timemodified = time();
        $group->id = $DB->insert_record('groups', $group);
        $course->groups[] = $group;
        return $group;
    }

    /**
     * Cache all existing enrolment instances.
     */
    protected function cache_enrolment_instances(): void {
        global $DB;

        // Fetch all of the enrolment instances for this plugin into the cache.
        $enrolinstancesql = <<<EOF
SELECT
    e.*,
    c.idnumber
  FROM {enrol} e
  JOIN {course} c ON c.id = e.courseid
 WHERE e.enrol = :type
EOF;
        $recordset = $DB->get_recordset_sql($enrolinstancesql, ['type' => 'oneroster']);
        foreach ($recordset as $record) {
            $idnumber = $record->idnumber;
            unset($record->idnumber);
            $this->instances[$idnumber] = $record;
        }
        $recordset->close();
    }

    /**
     * Get the Moodle enrolment instance for the specified course representation.
     *
     * @param   course_representation $entity
     * @return  stdClass
     */
    protected function get_course_enrolment_instance(course_representation $entity): ?stdClass {
        $coursedata = $entity->get_course_data();
        if (array_key_exists($coursedata->idnumber, $this->instances)) {
            // The entry already exists in the cache.
            return $this->instances[$coursedata->idnumber];
        }

        return null;
    }

    /**
     * Ensure that the enrolment instance exists for this course.
     *
     * @param   stdClass $course The moodle course to fetch or create the enrolment instance for
     */
    protected function ensure_course_enrolment_instance_exists(stdClass $course): void {
        global $DB;
        // try to get instance
        $instance = $DB->get_record('enrol', [
            'courseid' => $course->id,
            'enrol' => 'oneroster',
        ]);
        // get status based on the visibility of the course
        $status = $course->visible == false ? 1 : 0;
        if ($instance) {
            // A record exists, add it to the list.
            $this->instances[$course->idnumber] = $instance;
            // enable or disable enrolment instance
            $this->get_plugin_instance()->update_instance($instance, (object)[ 'status' => $status ]);
            // and exit
            return;
        }
        // add instance
        $enrolid = $this->get_plugin_instance()->add_instance($course, ['status' => $status ]);
        $this->instances[$course->idnumber] = $DB->get_record('enrol', ['id' => $enrolid]);
    }

    /**
     * Fetch the list of organisations that can be syncronised.
     *
     * @return  array
     */
    public function fetch_organisation_list(): Iterable {
        return $this->get_container()->get_collection_factory()->get_orgs(array(
            'sort' => 'name'
        ));
    }

    /**
     * Update or create a Moodle Course Category based on an entity representing a coursecat.
     *
     * @param   coursecat_representation $entity An entity representing a course category
     * @return  core_course_category
     */
    protected function update_or_create_category(coursecat_representation $entity): core_course_category {
        global $DB;

        // Fetch the course category representation for this entity.
        $remotecategory = $entity->get_course_category_data();

        // Find a matching local course category.
        $localcategoryid = $DB->get_field('course_categories', 'id', [
            'idnumber' => $remotecategory->idnumber,
        ]);

        // Ensure that the entity has not been recently modified.
        // These are only updated once per run.
        if (array_key_exists($entity->get('sourcedId'), $this->modifiedentities)) {
            return core_course_category::get($localcategoryid);
        }
        $this->modifiedentities[$entity->get('sourcedId')] = true;

        // Check for any parents and create/update those too.
        // The alternative is that we fetch all of the ones we need and create them once.
        if ($parent = $entity->get_parent()) {
            $parentcoursecategory = $this->update_or_create_category($parent);
            $remotecategory->parent = $parentcoursecategory->id;
        }

        if ($localcategoryid) {
            $localcategory = core_course_category::get($localcategoryid);

            $remotelastmodified = converter::from_datetime_to_unix($entity->get('dateLastModified'));
            if ($remotelastmodified > $localcategory->timemodified) {
                $localcategory->update($remotecategory);
                $this->add_metric('coursecat', 'update');
            }
        } else {
            $localcategory = core_course_category::create($remotecategory);
            $this->add_metric('coursecat', 'create');
        }

        return $localcategory;
    }

    /**
     * Update or create a Moodle Course based on an entity representing a course.
     *
     * @param   course_representation $entity An entity representing a course category
     * @return  stdClass
     */
    protected function update_or_create_course(course_representation $entity): ?stdClass {
        global $CFG, $DB;

        require_once("{$CFG->dirroot}/course/lib.php");

        // Fetch the course representation for this entity.
        $remotecourse = $entity->get_course_data();

        // Determine the remote parent category.
        $category = $this->update_or_create_category($entity->get_course_category());
        $remotecourse->category = $category->id;

        // Find a matching local course record.
        $localcourse = $DB->get_record('course', [
            'idnumber' => $remotecourse->idnumber,
        ]);

        if ($localcourse) {
            // do not change category and use the existing one
            if ($localcourse->category) {
                $remotecourse->category = $localcourse->category;
            }
            $update = false;
            foreach ((array) $remotecourse as $field => $value) {
                if ($localcourse->{$field} != $value) {
                    $update = true;
                    $localcourse->{$field} = $value;
                }
            }

            if ($update) {
                update_course($localcourse);
                $this->add_metric('course', 'update');
            }
        } else {
            // check if course is inactive
            $exclude_inactive = get_config('enrol_oneroster', 'oneroster_exclude_inactive');
            // inactive courses have already marked as invisible (mdl_course.visible = 0)
            if ($exclude_inactive && $remotecourse->visible == false) {
                $this->get_trace()->output(
                    sprintf(
                        "Excluding course '%s' with id '%s' because it has been marked as inactive.",
                        $remotecourse->fullname,
                        $remotecourse->idnumber
                    ),
                    5
                );
                // do not create course if it is inactive
                $this->add_metric('course', 'exclude');
                return null;
            }
            $localcourse = create_course($remotecourse);
            $this->add_metric('course', 'create');
        }

        $this->ensure_course_enrolment_instance_exists($localcourse);

        return $localcourse;
    }

    
    /**
     * Update or create a Moodle User based on an entity representing a user.
     *
     * @param   user_representation $entity An entity representing a user category
     * @return  stdClass
     */
    protected function update_or_create_user(user_representation $entity): stdClass {
        global $CFG, $DB;

        // Note: This is _usually_ the responsibility of an authentication plugin but One Roster can work with different
        // authentication sources which do not know anything about One Roster.
        require_once("{$CFG->dirroot}/user/lib.php");

        // Fetch the user representation for this entity.
        $remoteuser = $entity->get_user_data();
        $remoteuser->mnethostid = $CFG->mnet_localhost_id;
        $remoteuser->auth = $this->get_config_setting('newuser_auth');
        $remoteuser->confirmed = true;

        $metadata = $entity->get('metadata');
        if ($metadata) {
            $department_map = get_config('enrol_oneroster', 'user_department_map');
            if (empty($department_map) == FALSE) {
                if (property_exists($metadata, $department_map)) {
                    $remoteuser->department = $metadata->{$department_map};
                }
            }
            $institution_map = get_config('enrol_oneroster', 'user_institution_map');
            if (empty($institution_map) == FALSE) {
                if (property_exists($metadata, $institution_map)) {
                    $remoteuser->institution = $metadata->{$institution_map};
                }
            }
        }

        if ($this->get_user_mapping($remoteuser->idnumber)) {
            $localuser = $this->update_existing_user($entity, $remoteuser);
        } else {
            // Create a new uesr.
            $localuser = $this->create_new_user($entity, $remoteuser);
        }

        // See whether this user is an agent for any other user.
        // Note: This is only applied for students as per section 4.1.2 of the specification.
        $syncagents = get_config('enrol_oneroster', 'oneroster_sync_agents');
        if ($syncagents) {
            $this->sync_user_agents($entity, $localuser);
        }
        return $localuser;
    }

    /**
     * Create a new local user based upon a user representation.
     *
     * @param   user_representation $entity
     * @param   stdClass $remoteuser The user representation for the entity
     * @return  stdClass
     */
    protected function create_new_user(user_representation $entity, stdClass $remoteuser): stdClass {
        global $CFG;
        // Check whether there is an existing user with the same username.
        $user = core_user::get_user_by_username($remoteuser->username);
        if ($user) {
            // issue #3: validate syncing existing user without idnumber
            // if idnumber is empty
            if (empty($user->idnumber)) {
                // set idnumber with the one provided by remoteuser
                $user->idnumber = $remoteuser->idnumber;
                // and update local user
                user_update_user($user, false, false);
            }
            // finally create user mapping
            $this->create_user_mapping($user, $remoteuser->idnumber);
            // if idnumber is not empty and different from remoteuser idnumber
            if ($user->idnumber != $remoteuser->idnumber) {
                // create a new user mapping (an in-memory mapping)
                $this->usermappings[$remoteuser->idnumber] = $user->id;
            }
            // if ($CFG->debugdeveloper) {
            //     $this->get_trace()->output(sprintf("Skipping update/create of user %s merged into local user %s",
            //         $remoteuser->username,
            //         $user->idnumber
            //     ), 5);
            // }
            return $user;
        }

        // No user with the same idnumber, or a mapped idnumber.
        // Create a new user.
        // $this->get_trace()->output(sprintf("Creating new user %s (%s)",
        //     $remoteuser->username,
        //     $remoteuser->idnumber
        // ), 5);

        $localuserid = user_create_user($remoteuser);
        if ($localuserid == FALSE) {
            $this->get_trace()->output(sprintf("Failed to create new user %s (%s)",
                $remoteuser->username,
                $remoteuser->idnumber
            ), 5);
            throw new Exception("Failed to create new user");
        }
        $this->add_metric('user', 'create');
        $localuser = core_user::get_user($localuserid);
        if ($localuserid == FALSE) {
            $this->get_trace()->output(sprintf("Failed to get new user with id %s",
                $localuserid
            ), 5);
            throw new Exception("Failed to get new user");
        }
        $this->create_user_mapping($localuser, $remoteuser->idnumber);

        return $localuser;
    }

    /**
     * Update an existing user.
     *
     * @param   user_representation $entity
     * @param   stdClass $remoteuser The user representation for the entity
     * @return  stdClass
     */
    protected function update_existing_user(user_representation $entity, stdClass $remoteuser): stdClass {
        global $DB, $CFG;
        // try to get user mapping
        $mapping = $this->get_user_mapping($remoteuser->idnumber);
        if ($mapping) {
            $localuser = $DB->get_record('user', ['id' => $mapping]);
        } else {
            // validate the given idnumber (it should be unique across the system)
            // No mapping found, try to get user by idnumber
            $users = $DB->get_records('user', [
                'idnumber' => $remoteuser->idnumber
            ]);
            if (count($users) > 1) {
                throw new Exception("Multiple users found with idnumber {$remoteuser->idnumber}");
            }
            $localuser = array_shift($users);
        }
        // The user exists, user_update_user works on user 'id', so fill that in.
        $remoteuser->id = $localuser->id;

        // a user should be updated if the remote user has been modified after the local user
        // but sometimes we should update the user even if the remote user has not been modified
        // for example, if the remote user has a different department or institution than the local user
        // we should force update the local user
        $force = FALSE;
        if ((property_exists($remoteuser, 'department') && $remoteuser->department != $localuser->department) ||
            (property_exists($remoteuser, 'institution') && $remoteuser->institution != $localuser->institution)) {
            $force = TRUE;
        }

        if ($force == FALSE && $localuser->timemodified > converter::from_datetime_to_unix($entity->get('dateLastModified'))) {
            return $localuser;
        }

        // Update the existing user.
        $this->get_trace()->output(sprintf("Updating existing user %s with id %s (%s) %d < %d",
            $remoteuser->username,
            $remoteuser->id,
            $remoteuser->idnumber,
            $localuser->timemodified,
            converter::from_datetime_to_unix($entity->get('dateLastModified'))
        ), 5);

        user_update_user($remoteuser);
        $this->add_metric('user', 'update');

        return core_user::get_user($localuser->id);
    }

    /**
     * Synchronise user agents for a user.
     *
     * @param   user_entity $entity The user to sync agents for
     * @param   stdClass $localuser The local record for the user
     */
    protected function sync_user_agents(user_entity $entity, stdClass $localuser): void {
        if ($entity->get('role') !== 'student') {
            // Only applied for students as per section 4.1.2 of the specification.
            return;
        }

        $localusercontext = context_user::instance($localuser->id);

        // Create a mapping of userid => [roleid] for current user agents.
        $localuseragents = [];
        foreach (get_users_roles($localusercontext, [], false) as $userid => $roleassignments) {
            foreach (array_values($roleassignments) as $ra) {
                if ($ra->component === 'enrol_oneroster') {
                    if (!array_key_exists($userid, $localuseragents)) {
                        $localuseragents[$userid] = [];
                    }
                    $localuseragents[$userid][$ra->roleid] = true;
                }
            }
        }

        // Update remote user agents.
        foreach ($entity->get_agent_entities() as $remoteagent) {
            if (!$remoteagent) {
                continue;
            }

            // Ensure that the local user exists.
            $localagent = $this->update_or_create_user($remoteagent);
            if (!$localagent) {
                // Unable to create the local agent.
                $this->get_trace()->output(sprintf(
                    "Unable to assign %s (%s) as a %s of %s (%s). Local user not found.",
                    $remoteagent->get('username'),
                    $remoteagent->get('idnumber'),
                    $remoteagent->get('role'),
                    $entity->get('username'),
                    $entity->get('idnumber')
                ), 4);
                continue;
            }

            // Fetch the local role for the remote agent.
            $roleid = $this->get_role_mapping($remoteagent->get('role'), CONTEXT_USER);
            if (!$roleid) {
                // No local mapping for this role.
                $this->get_trace()->output(sprintf(
                    "Unable to assign %s (%s) as a %s of %s (%s). Role mapping not found.",
                    $remoteagent->get('username'),
                    $remoteagent->get('idnumber'),
                    $remoteagent->get('role'),
                    $entity->get('username'),
                    $entity->get('idnumber')
                ), 4);
                continue;
            }

            $assignrole = !array_key_exists($localagent->id, $localuseragents);
            $assignrole = $assignrole || !array_key_exists($roleid, $localuseragents[$localagent->id]);

            if ($assignrole) {
                // Assign the role.
                role_assign($roleid, $localagent->id, $localusercontext, 'enrol_oneroster');
                $this->get_trace()->output(sprintf(
                    "Assigned %s (%s) as a %s of %s (%s).",
                    $remoteagent->get('username'),
                    $remoteagent->get('idnumber'),
                    $remoteagent->get('role'),
                    $entity->get('username'),
                    $entity->get('idnumber')
                ), 4);
                $this->add_metric('user_mapping', 'create');
            } else {
                // Unset the local agent mapping.
                unset($localuseragents[$localagent->id][$roleid]);
            }

        }

        // Unenrol stale mappings.
        foreach ($localuseragents as $localagentid => $localagentroles) {
            foreach ($localagentroles as $roleid) {
                $this->get_trace()->output(sprintf(
                    "Unasssigned user with id %s from being a %s of %s (%s).",
                    $localagentid,
                    $roleid,
                    $localuser->username,
                    $localuser->idnumber
                ), 4);
                role_unassign($roleid, $localagentid, $localusercontext, 'enrol_oneroster');
                $this->add_metric('user_mapping', 'delete');
            }
        }
    }

    /**
     * Update or create a Moodle User Enrolment based on an entity representing that enrolment.
     *
     * @param   enrollment_representation $entity An entity representing a enrollment
     * @return  stdClass
     */
    protected function update_or_create_enrolment(enrollment_representation $entity) {
        global $DB, $CFG;

        // Fetch the user details for this enrolment.
        $userentity = $entity->get_user_entity();
        if ($userentity === null) {
            $this->get_trace()->output("Unable to fetch user entity for enrollment: " . $entity->get('sourcedId'), 5);
            return;
        }
        $moodleuserid = $this->get_user_mapping_for_user($userentity);
        if ($moodleuserid === null) {
            $this->get_trace()->output("No user found for user " . $userentity->get('identifier'), 5);
            return;
        }

        // Fetch the role mapping for this enrolment.
        $roledata = $entity->get_role_data();
        $moodleroleid = $this->get_role_mapping($roledata->role, (int) CONTEXT_COURSE);
        if ($moodleroleid === null) {
            $this->get_trace()->output("No user found for role '{$roledata->role}'", 5);
            // This role has no mapping in Moodle.
            return;
        }

        // Get the Enrolment instance for this course.
        $course = $entity->get_course_representation();
        $instance = $this->get_course_enrolment_instance($course);
        if ($instance === null) {
            $this->get_trace()->output("No enrolment instance could be found or created for course '{$course->idnumber}'", 3);
            return;
        }

        $enroldata = $entity->get_enrolment_data();
        $existing = $DB->get_record('user_enrolments', ['userid' => $moodleuserid, 'enrolid' => $instance->id]);
        if ($existing) {
            $enrolmentkeys = [
                'status',
                'timestart',
                'timeend',
            ];

            // Unset the current mapping to prevent the user from being unenrolled.
            $user_role_assignments = $this->existingroleassignments[$instance->id][$moodleuserid];
            if ($user_role_assignments) {
                unset($user_role_assignments[$moodleroleid]);
                if (empty($user_role_assignments)) {
                    unset($this->existingroleassignments[$instance->id][$moodleuserid]);
                }
            }
            
            foreach ($enrolmentkeys as $key) {
                $update = false;
                if ($existing->{$key} != $enroldata->{$key}) {
                    $update = true;
                }
            }

            if ($update) {
                $this->get_trace()->output(
                    "Updating existing enrolment for " .
                    $userentity->get('identifier') .
                    " in {$instance->courseid} from {$enroldata->timestart} to {$enroldata->timeend}",
                    5);
                $this->get_plugin_instance()->update_user_enrol(
                    $instance,
                    $moodleuserid,
                    $enroldata->status,
                    $enroldata->timestart,
                    $enroldata->timeend
                );
                $this->add_metric('enrollment', 'update');
            }
        } else {
            $this->get_plugin_instance()->enrol_user(
                $instance,
                $moodleuserid,
                $moodleroleid,
                $enroldata->timestart,
                $enroldata->timeend,
                $enroldata->status,
                true
            );
            $this->add_metric('enrollment', 'create');
        }

        if ($moodleroleid) {
            // get context
            $context = \context_course::instance($instance->courseid, TRUE);
            $component = 'enrol_'.$instance->enrol;
            $itemid = $instance->id;
            // check if role assignment already exists
            $ras = $DB->get_records('role_assignments', array('roleid'=>$moodleroleid, 'contextid'=>$context->id, 'userid'=>$moodleuserid, 'component'=>$component, 'itemid'=>$itemid), 'id');
            if ($ras) {
                return;
            }
            role_assign($moodleroleid, $moodleuserid, $context->id, $component, $itemid);
        }

    }

    /**
     * Get the context levels available for the specified role.
     *
     * @param   int $roleid
     * @return  int[] List of context levels suitable for this role
     */
    protected function get_role_contextlevels(int $roleid): array {
        if ($this->rolecontextlevels === null) {
            $this->rolecontextlevels = [];
        }

        if (!array_key_exists($roleid, $this->rolecontextlevels)) {
            $this->rolecontextlevels[$roleid] = get_role_contextlevels($roleid);
        }

        return $this->rolecontextlevels[$roleid];
    }

    /**
     * Whether the specified role is available at the specified context level.
     *
     * @param   int $roleid
     * @param   int $contextlevel
     * @return  bool
     */
    protected function is_role_available_for_contextlevel(int $roleid, int $contextlevel): bool {
        $mappings = $this->get_role_contextlevels($roleid);

        return array_search($contextlevel, $mappings) !== false;
    }

    /**
     * Get the plugin configuration.
     *
     * @param   string $name The plugin name to fetch
     * @return  string|null
     */
    protected function get_config_setting(string $name): ?string {
        if ($this->pluginconfig === null) {
            $this->pluginconfig = get_config('enrol_oneroster');
        }

        return property_exists($this->pluginconfig, $name) ? $this->pluginconfig->{$name} : null;
    }

    /**
     * Get the role mapping for the specified role.
     *
     * @param   string $rolename The One Roster role name
     * @param   int $intendedcontextlevel The context level that this mapping relates to
     * @return  int|null The Moodle Role ID for the mapped role
     */
    protected function get_role_mapping(string $rolename, int $intendedcontextlevel): ?int {
        $roleid = $this->get_config_setting("role_mapping_{$rolename}");

        if (empty($roleid)) {
            // This is user is not configured.
            return null;
        }

        if ($roleid < 0) {
            // This is user is not mapped.
            return null;
        }

        if (!$this->is_role_available_for_contextlevel($roleid, $intendedcontextlevel)) {
            // This role cannot be used in this context level.
            return null;
        }

        return $roleid;
    }

    /**
     * Get the list of user mappings from remote user idnumber to Moodle user ID.
     *
     * One Roster allows multiple people to be represented by multiple Human records in One Roster.
     * Moodle needs to merge those.
     *
     * This is done by mapping a 'Primary' remote sourcedId against the sourcedId of all other users in One Roster with
     * a matching username.
     *
     * @return  array
     */
    protected function get_user_mappings(): array {
        global $DB;

        if ($this->usermappings === null) {
            $sql = <<<EOF
                SELECT eom.mappedid, u.id
                FROM {enrol_oneroster_user_map} eom
                JOIN {user} u ON u.idnumber = eom.parentid 
                WHERE NOT (eom.parentid IS NULL OR eom.parentid = '')
EOF;
            $this->usermappings = $DB->get_records_sql_menu($sql);
        }

        return $this->usermappings;
    }

    /**
     * Get the user mapping for the specified User sourcedId.
     *
     * @param   string $idnumber
     * @return  int|null The user ID of the parent role
     */
    protected function get_user_mapping(string $idnumber): ?int {
        $mappings = $this->get_user_mappings();
        if (array_key_exists($idnumber, $mappings)) {
            return (int) $mappings[$idnumber];
        }
        return null;
    }

    /**
     * Create a user mapping entry.
     *
     * @param   stdClass $user
     * @param   string $mappedid
     */
    protected function create_user_mapping(stdClass $user, string $mappedid): void {
        global $DB;

        if (empty($user->idnumber)) {
            // throw exception for invalid idnumber
            throw new Exception('User must have an idnumber to be mapped');
        }
        if (empty($mappedid)) {
            // throw exception for invalid mappedid
            throw new Exception('Mapped ID must be provided to create a mapping');
        }

        if (array_key_exists($user->idnumber, $this->usermappings)) {
            // already mapped
            if ($this->usermappings[$user->idnumber] === $user->id) {
                // already mapped
                return;
            }
        }

        // validate if user already mapped
        $exists = $DB->record_exists('enrol_oneroster_user_map', [
            'parentid' => $user->idnumber,
            'mappedid' => $mappedid,
        ]);
        if ($exists) {
            // already mapped
            $this->usermappings[$user->idnumber] = $user->id;
            return;
        }
        // otherwise, create new mapping
        $DB->insert_record('enrol_oneroster_user_map', (object) [
            'parentid' => $user->idnumber,
            'mappedid' => $mappedid,
        ]);
        // update usermappings
        $this->usermappings[$user->idnumber] = $user->id;
    }

    /**
     * Get the user mapping for the specified user representation.
     *
     * @param   user_representation $entity
     * @return  int|null The user ID of the parent role
     */
    protected function get_user_mapping_for_user(user_representation $entity): ?int {
        $userdata = $entity->get_user_data();
        return $this->get_user_mapping($userdata->idnumber);
    }

    /**
     * Add a tracking metric value.
     *
     * @param   string $what The item being tracked
     * @param   string $action The action (create, update, delete)
     * @param   int $count The number of times that the action was performed
     */
    protected function add_metric(string $what, string $action, int $count = 1): void {
        if (!array_key_exists($what, $this->metrics)) {
            $this->metrics[$what] = [
                'create' => 0,
                'update' => 0,
                'delete' => 0,
                'exclude' => 0,
            ];
        }

        if (!array_key_exists($action, $this->metrics[$what])) {
            return;
        }

        $this->metrics[$what][$action] += $count;
    }

    /**
     * Fetch the recorded tracking metrics.
     *
     * @return  stdClass
     */
    protected function get_metrics(): stdClass {
        return (object) $this->metrics;
    }

    /**
     * Fetch the list of organisations that can be syncronised.
     *
     * @return  array
     */
    public function fetch_academic_session_list(): Iterable {
        return $this->get_container()->get_collection_factory()->get_academic_sessions(array(
            'sort' => 'schoolYear,type',
            'orderBy' => 'asc'
        ));
    }
}
