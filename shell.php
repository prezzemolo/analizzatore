<?php

namespace analizzatore;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'request.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'extractors.php']);

use DOMDocument;
use function analizzatore\utils\{request, ogp_extractor, metadata_extractor, canonical_extractor};

$site_addr = $argv[1] ?? 'https://prezzemolo.ga/';
$res = request('GET', $site_addr);
$res_body = $res['body'];
$res_headers = $res['headers'];
$res_isHTML = preg_match('/^.*\/html(:?;.*)?$/', $res_headers['content-type']) === 1;
$res_body_DOM = $res_isHTML ? DOMDocument::loadHTML($res_body) : NULL;
$res_body_DOM_head = $res_body_DOM->getElementsByTagName('head')->item(0);
if (isset($res_body_DOM_head)) {
  var_dump($res_body_DOM_head->getElementsByTagName('title')->item(0)->textContent);
  $meta = $res_body_DOM_head->getElementsByTagName('meta');
  var_dump(ogp_extractor($meta));
  var_dump(metadata_extractor($meta));
  $link = $res_body_DOM_head->getElementsByTagName('link');
  var_dump(canonical_extractor($link));
}
$res_info = $res['info'];
var_dump($res_headers['Content-encoding']);
var_dump($res_info['http_code']);
?>
