<?php
set_time_limit(500);
mb_language('Japanese');
date_default_timezone_set('Asia/Tokyo');

require_once('./phpQuery-onefile.php');
require_once('./key.php');
require __DIR__ . '/vendor/autoload.php';
use \CloudConvert\Api;

// バッチを動かす時間によって、違うapikeyを使用
$cc_apikey_index = (int)(date('G') / 3);
$cc_api = new Api($cloud_convert_apikey[$cc_apikey_index]);

define('OUTPUT_ONEWS', './onews/');

$url = 'http://news.yahoo.co.jp/pickup/rss.xml';
$links = getLinks($url);

$data_list = [];
foreach ($links as $link) {
  $data_list[] = getData($link);
}

$speaker = 'haruka';
$voice_text_format = 'wav';
$cloud_convert_format = 'mp3';

foreach ($data_list as $key => $data) {
  $text = $data['title'] . $data['description'];
  $text = shortenSentence($text, '。', 200);

  $file = getVoiceText($text, $speaker, $voice_text_format, $apikey);
  $file_name = 'voice_' . $key . '.' . $voice_text_format;
  file_put_contents(OUTPUT_ONEWS . $file_name, $file);

  $data_list[$key]['voice'] = doCloudConvert($cc_api, $file_name, $voice_text_format, $cloud_convert_format);
}

$json = json_encode($data_list, JSON_UNESCAPED_UNICODE);
file_put_contents(OUTPUT_ONEWS . 'articles.json', $json);

echo 'done';


function getVoiceText($text, $speaker, $voice_text_format, $apikey)
{
  $url = 'https://api.apigw.smt.docomo.ne.jp/voiceText/v1/textToSpeech?APIKEY=' . $apikey;

  $data = [
    'speaker' => $speaker,
    'text'    => $text,
    'format'  => $voice_text_format,
  ];
  $data = http_build_query($data, '', '&');

  $header = [
    'Content-Type: application/x-www-form-urlencoded',
    'Content-Length: ' . strlen($data),
  ];

  $context = [
    'http' => [
      'method'  => 'POST',
      'header'  => $header,
      'content' => $data,
    ]
  ];

  return file_get_contents($url, false, stream_context_create($context));
}

function doCloudConvert($cc_api, $file_name, $voice_text_format, $cloud_convert_format)
{
  $api_object = $cc_api->convert([
    'inputformat' => $voice_text_format,
    'outputformat' => $cloud_convert_format,
    'input' => 'upload',
    'file' => fopen(OUTPUT_ONEWS . $file_name, 'r'),
  ])
  ->wait();
  $download = $cc_api->get($api_object->url);
  return 'https:' . $download['output']['url'];
}

function getLinks($url)
{
  $xml = file_get_contents($url);
  $feed = simplexml_load_string($xml);
  $links = [];
  foreach ($feed->channel->item as $item) {
    $links[] = (string)$item->link;
  }
  return $links;
}

function getData($link)
{
  $pq = phpQuery::newDocumentFile($link);
  $data['link']        = $link;
  $data['title']       = $pq['.topicsName']['h1']->text();
  $description         = $pq['.hbody']->text();
  $description         = removeBrackets($description, '（', '）');
  $description         = removeBrackets($description, '(', ')');
  $data['description'] = $description;
  $data['image']       = $pq['.headlinePic']['img']->attr('data-src');
  return $data;
}

function removeBrackets($target, $bracket_start, $bracket_end)
{
  while (strpos($target, $bracket_start) !== false &&
         strpos($target, $bracket_end)   !== false) {
    $left = explode($bracket_start, $target);
    $left = $left[0];

    $right = explode($bracket_end, $target);
    unset($right[0]);
    $right = implode($bracket_end, $right);

    $target = $left . $right;
  }
  return $target;
}

function shortenSentence($target, $delimiter, $length) {
  while (mb_strlen($target) > $length) {
    $target_ex = explode($delimiter, $target);
    array_pop($target_ex);
    $target = implode($delimiter, $target_ex);
  }
  return $target . $delimiter;
}
