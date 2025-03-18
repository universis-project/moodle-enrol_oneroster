<?php

namespace enrol_oneroster\external;
use \core_external\external_api as external_api;
use \core_external\external_function_parameters as external_function_parameters;
use \core_external\external_single_structure as external_single_structure;
use \core_external\external_multiple_structure as external_multiple_structure;
use \core_external\external_value as external_value;
use \core_external\external_warnings as external_warnings;

use enrol_oneroster\client_helper;

class class_webhook extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'sourcedId' => new external_value(
                        PARAM_TEXT,
                        'OneRoster class code',
                        VALUE_REQUIRED
                ),
                'school' => new external_value(
                        PARAM_TEXT,
                        'OneRoster school code',
                        VALUE_OPTIONAL
                    )
        ]
        );
    }

    public static function execute($sourcedId, $school = null): array {
        global $DB;

        $result = true;
        $warnings = [];

        // get oneroster config
        $config = get_config('enrol_oneroster');
        // get client
        $client = client_helper::get_client(
            $config->oauth_version,
            $config->oneroster_version,
            $config->token_url,
            $config->root_url,
            $config->clientid,
            $config->secret
        );
        // create extra filter for the specific class
        $filter = [
            'sourcedId' => $sourcedId
        ];
        if ($school) {
            $filter['school'] = $school;
        }
        // authenticate client
        try {
            $client->authenticate();
            $client->synchronise(null, $filter);
        } catch (\Exception $e) {
            $result = false;
            $warnings[] = [
                'warningcode' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
        
        return [
            'result' => $result,
            'warnings' => $warnings
        ];
    }
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'result' => new external_value(PARAM_BOOL, 'The processing result'),
            'warnings' => new external_warnings()
        ]);
    }
}
