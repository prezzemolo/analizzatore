<?php

namespace analizzatore\utils;

function ogp_extractor ($meta_DOMNodeList) {
  $ogp_tags = [];
  foreach ($meta_DOMNodeList as $meta_DOMNode) {
   /**
    * check existance of attributes
    * http://php.net/manual/en/class.domnode.php#domnode.props.attributes
    * note: no special reason for disuse hasElements method
    */
   if ($meta_DOMNode->attributes === null) {
     continue;
   }
   // skip non OGP tag
   if (!$meta_DOMNode->attributes->getNamedItem('property')
    || substr($meta_DOMNode->attributes->getNamedItem('property')->textContent, 0, 3) !== 'og:') {
     continue;
   }
   // skip no content
   if (!$meta_DOMNode->attributes->getNamedItem('content')
    || !$meta_DOMNode->attributes->getNamedItem('content')->textContent) {
     continue;
   }
   $property = substr($meta_DOMNode->attributes->getNamedItem('property')->textContent, 3);
   $content = $meta_DOMNode->attributes->getNamedItem('content')->textContent;
   $ogp_tags[$property] = $content;
 }
 return $ogp_tags;
}

function metadata_extractor ($meta_DOMNodeList) {
  $metadata = [];

  foreach ($meta_DOMNodeList as $meta_DOMNode) {
   /**
    * check existance of attributes
    * http://php.net/manual/en/class.domnode.php#domnode.props.attributes
    * note: no special reason for disuse hasElements method
    */
   if ($meta_DOMNode->attributes === null) {
     continue;
   }
   // skip non metadata tag
   if (!$meta_DOMNode->attributes->getNamedItem('name')
    || !$meta_DOMNode->attributes->getNamedItem('name')->textContent) {
     continue;
   }
   // skip no content
   if (!$meta_DOMNode->attributes->getNamedItem('content')
    || !$meta_DOMNode->attributes->getNamedItem('content')->textContent) {
     continue;
   }
   $name = $meta_DOMNode->attributes->getNamedItem('name')->textContent;
   $content = $meta_DOMNode->attributes->getNamedItem('content')->textContent;
   $metadata[$name] = $content;
  }
  return $metadata;
}
?>
