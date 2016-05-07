<?php
set_time_limit(500);
mb_language('Japanese');

require_once('./phpQuery-onefile.php');
require_once('./key.php');
require __DIR__ . '/vendor/autoload.php';
use \CloudConvert\Api;
// @TODO アカウントいっぱい作ってバッチ動かすたびにapikeyを変える
$cc_api = new Api($cloud_convert_apikey[0]);

//フォルダの定義
define('OUTPUT_ONEWS', './onews/');

$url = 'http://news.yahoo.co.jp/pickup/rss.xml';
$links = getLinks($url);

$data_list = [];
foreach ($links as $link) {
  $data_list[] = getData($link);
}

$speaker = 'haruka';
$format = 'wav';

foreach ($data_list as $key => $data) {
  $text = $data['title'] . $data['description'];
  $file = getVoiceText($text, $speaker, $format, $apikey);
  $file_name = 'voice_' . $key . '.' . $format;
  file_put_contents(OUTPUT_ONEWS . $file_name, $file);

  $api_object = $cc_api->convert([
    'inputformat' => 'wav',
    'outputformat' => 'mp3',
    'input' => 'upload',
    'file' => fopen(OUTPUT_ONEWS . $file_name, 'r'),
  ])
  ->wait();
  $download = $cc_api->get($api_object->url);
  $data_list[$key]['voice'] = 'https:' . $download['output']['url'];
}

$json = json_encode($data_list, JSON_UNESCAPED_UNICODE);
file_put_contents(OUTPUT_ONEWS . 'articles.json', $json);

echo 'done';


function getVoiceText($text, $speaker, $format, $apikey)
{
  $url = 'https://api.apigw.smt.docomo.ne.jp/voiceText/v1/textToSpeech?APIKEY=' . $apikey;

  $data = [
    'speaker' => $speaker,
    'text'    => $text,
    'format'  => $format,
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
