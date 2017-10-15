<?php

namespace analizzatore\common;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'common', 'exceptions.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'common', 'robot-configuration-store.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'robots-txt-parser.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'extractors.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'ex-url.php']);

use analizzatore\utils\ExUrl;
use analizzatore\exceptions\DenyException;
use analizzatore\exceptions\RequestException;
use function analizzatore\utils\{request, parse_robots_txt};

$rc_instants = [
  'full_disallow' => [
    'User-Agent' => '~.*~',
    'Allow' => null,
    'Disallow' => '~(?:/.*)~',
    'Instant' => true
  ],
  'full_allow' => [
    'User-Agent' => '~.*~',
    'Allow' => '~(?:/.*)~',
    'Disallow' => null,
    'Instant' => true
  ]
];

function is_allowable_indexing_by_rc (array $robot_configuration, string $url): bool {
  // regExp match checker
  $robot_allowed = $robot_configuration['Allow'] === null ? false
    : preg_match($robot_configuration['Allow'], $url) === 1;
  $robot_disallowed = $robot_configuration['Disallow'] === null ? false
    : preg_match($robot_configuration['Disallow'], $url) === 1;
  // only 'disallowed' flag enabled, disallowed, as false.
  return !($robot_disallowed && !$robot_allowed);
}

/**
 * robots.txt checker
 **/
function check_indexing_permission_on_robots_txt (string $url, string $user_agent, $curl_ch = null): bool {
  global $rc_instants;

  $robots_url = ExUrl::join($url, '/robots.txt');
  $store = new RobotConfigurationStore();
  $robot_configuration = $store->find($robots_url);
  // return early, with stored robot_configuration
  if ($robot_configuration !== null)
    return is_allowable_indexing_by_rc($robot_configuration, $url);
  // if non configuration in store, get from internet
  try {
    // custom max age of robot_configuration will be stored.
    $robot_configuration_max_age = null;
    $robots_txt_response = request('GET', $robots_url, [
      'curl_ch' => $curl_ch,
      'headers' => [ 'User-Agent' => $user_agent ]
    ]);
    /**
     * in 4xx, take as "full allow". refer to
     * https://developers.google.com/search/reference/robots_txt
     */
    if (
      (500 > $robots_txt_response['status_code']) and
      ($robots_txt_response['status_code'] >= 400)
    ) {
      $robot_configuration = $rc_instants['full_allow'];
    }
    /**
     * in 5xx, take as "full disallow". refer to
     * https://developers.google.com/search/reference/robots_txt
     */
    else if (
      (600 > $robots_txt_response['status_code']) and
      ($robots_txt_response['status_code'] >= 500)
    ) {
      $robot_configuration = $rc_instants['full_disallow'];
      # 10 min
      $robot_configuration_max_age = 10 * 60;
    } else {
      $robots = parse_robots_txt($robots_txt_response['body'], $user_agent);
      // todo: improvement detection logic
      $robot_configuration = array_shift($robots) ?? $rc_instants['full_allow'];
    }
  } catch (RequestException $e) {
    $code = $e->getCode();
    /**
     * re-throw in error code not equal to CURLE_OPERATION_TIMEDOUT (as error code 28).
     * see https://curl.haxx.se/libcurl/c/libcurl-errors.html
     */
    if ($code !== 28) { throw $e; }
    /**
     * in timeout, take as "full allow". there are no references,
     * this behiver was decided on its own.
     */
    $robot_configuration = $rc_instants['full_allow'];
    # 5 min
    $robot_configuration_max_age = 5 * 60;
  }

  $store->save($robots_url, $robot_configuration, $robot_configuration_max_age);
  return is_allowable_indexing_by_rc($robot_configuration, $url);
}
