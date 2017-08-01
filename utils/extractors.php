<?php

namespace analizzatore\utils;

function ogp_extractor ($meta_DOMNodeList) {
  $ogp_tags = [];

  foreach ($meta_DOMNodeList as $meta_DOMNode) {
   // check existance of attributes
   // http://php.net/manual/en/class.domnode.php#domnode.props.attributes
   if ($meta_DOMNode->attributes === null) continue;
   // skip non OGP tag
   if (!$meta_DOMNode->hasAttribute('property')
    || substr($meta_DOMNode->getAttribute('property'), 0, 3) !== 'og:') continue;
   // skip no content
   if (!$meta_DOMNode->hasAttribute('content')
    || !$meta_DOMNode->getAttribute('content')) continue;
   $property = substr($meta_DOMNode->getAttribute('property'), 3);
   $content = $meta_DOMNode->getAttribute('content');
   $ogp_tags[$property] = $content;
 }

 return $ogp_tags;
}

function metadata_extractor ($meta_DOMNodeList) {
  $metadata = [];

  foreach ($meta_DOMNodeList as $meta_DOMNode) {
   // check existance of attributes
   // http://php.net/manual/en/class.domnode.php#domnode.props.attributes
   if ($meta_DOMNode->attributes === null) continue;
   // skip non metadata tag
   if (!$meta_DOMNode->hasAttribute('name')
    || !$meta_DOMNode->getAttribute('name')) continue;
   // skip no content
   if (!$meta_DOMNode->hasAttribute('content')
    || !$meta_DOMNode->getAttribute('content')) continue;
   $name = $meta_DOMNode->getAttribute('name');
   $content = $meta_DOMNode->getAttribute('content');
   $metadata[$name] = $content;
  }

  return $metadata;
}

function rel_extractor ($link_DOMNodeList) {
  $rel_tags = [];

  foreach ($link_DOMNodeList as $link_DOMNode) {
   // check existance of attributes
   // http://php.net/manual/en/class.domnode.php#domnode.props.attributes
   if ($link_DOMNode->attributes === null) continue;
   // skip no rel & href in attributes
   if (!$link_DOMNode->hasAttribute('rel')
    || !$link_DOMNode->getAttribute('rel')
    || !$link_DOMNode->hasAttribute('href')
    || !$link_DOMNode->getAttribute('href')) continue;
   $rel = $link_DOMNode->getAttribute('rel');
   $href = $link_DOMNode->getAttribute('href');
   $rel_tags[$rel] = $href;
 }

 return $rel_tags;
}
