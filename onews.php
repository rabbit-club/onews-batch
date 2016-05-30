<?php
ini_set('display_errors', 1);

set_time_limit(500);
mb_language('Japanese');
date_default_timezone_set('Asia/Tokyo');

echo 'start';

CONST VOICE_TEXT_RETRY_COUNT = 3;
CONST DROPBOX_RETRY_COUNT    = 3;

require_once('./phpQuery-onefile.php');
require_once('./key.php');
require_once('./vendor/autoload.php');
// use \CloudConvert\Api;

// バッチを動かす時間によって、違うapikeyを使用
// $cc_apikey_index = (int)(date('G'));
// $cc_api = new Api($cloud_convert_apikey[$cc_apikey_index]);
$dropbox = new \Dropbox\Client($dropbox_apikey, 'onews');

define('OUTPUT_ONEWS', './onews/');

$url = 'http://news.yahoo.co.jp/pickup/rss.xml';
try {
  $links = getLinks($url);
} catch (Exception $e) {
  sendMessageToSlack($slack_webhook_url, ' <!channel> ' . $e->getMessage());
}

$data_list = [];
foreach ($links as $link) {
  $data_list[] = getData($link);
}

if (empty($data_list)) {
  sendMessageToSlack($slack_webhook_url, ' <!channel> ニュース記事データの取得に失敗しました.');
  exit(1);
}

$speaker = 'haruka';
$voice_text_format = 'ogg';
// $cloud_convert_format = 'mp3';

$now_h = date('H');
$now_i = (date('i') < 30) ? '00' : '30';

// articles_url_listを更新する
// 1日1回の更新とする
if ($now_h == '04' && $now_i == '00') {
  $list_file_name = 'articles_url_list.json';
  try {
    createArticlesUrlList($dropbox, OUTPUT_ONEWS . $list_file_name);
  } catch (Exception $e) {
    sendMessageToSlack($slack_webhook_url, ' <!channel> ' . $e->getMessage());
  }
  try {
    uploadDropBox($dropbox, "/{$list_file_name}", OUTPUT_ONEWS . $list_file_name, OUTPUT_ONEWS . "{$list_file_name}.dbx");
  } catch (Exception $e) {
    sendMessageToSlack($slack_webhook_url, " <!channel> {$list_file_name_dbx} のdropboxへのファイルアップロードに失敗しました.");
  }
}

// 重複を防ぐために直近のデータを取得し、
// 記事URLのリストを作成する
$latest_articles_link_list = getLatestArticlesLinkList($now_i);

foreach ($data_list as $key => $data) {
  // 重複記事
  if (in_array($data['link'], $latest_articles_link_list)) {
    unset($data_list[$key]);
    continue;
  }

  $text = $data['title'] . $data['description'];
  $text = shortenSentence($text, '。', 200);

  $voice_text_status = false;
  $err_msg = '';
  for ($i = 0; $i <= VOICE_TEXT_RETRY_COUNT; ++$i) {
    try {
      $file = getVoiceText($text, $speaker, $voice_text_format, $docomo_apikey);
      $voice_text_status = true;
      break;
    } catch (Exception $e) {
      $err_msg = $e->getMessage();
      sleep(1);
      continue;
    }
  }
  if (!$voice_text_status) {
    sendMessageToSlack($slack_webhook_url, ' <!channel> ' . $err_msg);
    unset($data_list[$key]);
    continue;
  }

  $file_name = 'voice_' . $key . '.' . $voice_text_format;
  file_put_contents(OUTPUT_ONEWS . $file_name, $file);
  $file_name_dbx = "voice_{$key}_{$now_h}{$now_i}.{$voice_text_format}";
  $upload_dbx_status = false;
  for ($i = 0; $i <= DROPBOX_RETRY_COUNT; ++$i) {
    try {
      uploadDropBox($dropbox, "/{$file_name_dbx}", OUTPUT_ONEWS . $file_name, OUTPUT_ONEWS . "{$file_name}.dbx");
      $upload_dbx_status = true;
      break;
    } catch (Exception $e) {
      $err_msg = $e->getMessage();
      sleep(1);
      continue;
    }
  }
  if (!$upload_dbx_status) {
    sendMessageToSlack($slack_webhook_url, " <!channel> {$file_name_dbx} のdropboxへのファイルアップロードに失敗しました. msg:{$err_msg}");
    unset($data_list[$key]);
    continue;
  }

  $get_dbx_url_status = false;
  for ($i = 0; $i <= DROPBOX_RETRY_COUNT; ++$i) {
    try {
      $voice_url = getDropBoxSharedUrl($dropbox, "/{$file_name_dbx}");
      $get_dbx_url_status = true;
      break;
    } catch (Exception $e) {
      $err_msg = $e->getMessage();
      sleep(1);
      continue;
    }
  }
  if (!$get_dbx_url_status) {
    sendMessageToSlack($slack_webhook_url, " <!channel> {$file_name_dbx} のdropboxの共有URLの取得に失敗しました. msg:{$err_msg}");
    unset($data_list[$key]);
    continue;
  }

  if (empty($voice_url)) {
    sendMessageToSlack($slack_webhook_url, " <!channel> {$file_name_dbx} のdropboxの共有URLの取得に失敗しました.");
    unset($data_list[$key]);
    continue;
  }

  $voice_url = substr($voice_url, 0, strcspn($voice_url, '?'));
  $data_list[$key]['voice'] = $voice_url;

  // try {
  //   $data_list[$key]['voice'] = doCloudConvert($cc_api, $file_name, $voice_text_format, $cloud_convert_format);
  // } catch (Exception $e) {
  //   echo $e->getMessage() . '\n';
  //   unset($data_list[$key]);
  // }
}

$data_list = array_merge($data_list);
$json = json_encode($data_list, JSON_UNESCAPED_UNICODE);
$articles_file_name = "articles_{$now_h}{$now_i}.json";
file_put_contents(OUTPUT_ONEWS . $articles_file_name, $json);
try {
  uploadDropBox(
    $dropbox,
    "/{$articles_file_name}",
    OUTPUT_ONEWS . $articles_file_name,
    OUTPUT_ONEWS . 'articles.json.dbx'
  );
} catch (Exception $e) {
  sendMessageToSlack($slack_webhook_url, " <!channel> {$articles_file_name} のdropboxへのファイルアップロードに失敗しました.");
}

echo 'end';


function getVoiceText($text, $speaker, $voice_text_format, $docomo_apikey)
{
  $url = 'https://api.apigw.smt.docomo.ne.jp/voiceText/v1/textToSpeech?APIKEY=' . $docomo_apikey;

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

  $file = file_get_contents($url, false, stream_context_create($context));

  if (!$file) {
    throw new Exception('VoiceTextの取得に失敗しました. text: ' . $text);
  }

  return $file;
}

function doCloudConvert($cc_api, $file_name, $voice_text_format, $cloud_convert_format)
{
  try {
    $api_object = $cc_api->convert([
      'inputformat' => $voice_text_format,
      'outputformat' => $cloud_convert_format,
      'input' => 'upload',
      'file' => fopen(OUTPUT_ONEWS . $file_name, 'r'),
      'save' => true,
    ])
    ->wait();
    $download = $cc_api->get($api_object->url);
  } catch (CloudConvert\Exceptions\ApiBadRequestException $e) {
    throw new Exception($e->getMessage());
  } catch (CloudConvert\Exceptions\ApiConversionFailedException $e) {
    throw new Exception($e->getMessage());
  } catch (CloudConvert\Exceptions\ApiTemporaryUnavailableException $e) {
    throw new Exception($e->getMessage());
  } catch (CloudConvert\Exceptions\ApiException $e) {
    throw new Exception($e->getMessage());
  } catch (Exception $e) {
    throw new Exception($e->getMessage());
  }
  return 'https:' . $download['output']['url'];
}

function uploadDropBox($dropbox, $dropbox_file_path, $upload_file_path, $download_file_path = null)
{
  if (!empty($download_file_path)) {
    $fd = fopen($download_file_path, 'wb');
    $metadata = $dropbox->getFile($dropbox_file_path, $fd);
    fclose($fd);
    $rev = $metadata['rev'];
  }

  $fp = fopen($upload_file_path, 'rb');
  if (!empty($download_file_path)) {
    $dropbox->uploadFile($dropbox_file_path, \Dropbox\WriteMode::update($rev), $fp);
  } else {
    $dropbox->uploadFile($dropbox_file_path, \Dropbox\WriteMode::add(), $fp);
  }
  fclose($fp);
}

function getDropBoxSharedUrl($dropbox, $dropbox_file_path, $change_dl_option = false)
{
  $link = $dropbox->createShareableLink($dropbox_file_path);
  if ($change_dl_option) {
    $link = (substr($link, -1, 1) == '0') ? substr_replace($link, '1', -1) : $link;
  }
  return $link;
}

function createArticlesUrlList($dropbox, $file_path)
{
  $minutes_array = ['00', '30'];
  $articles_url_list = [];
  for ($i = 0; $i <= 23; ++$i) {
    $hour = str_pad($i, 2, 0, STR_PAD_LEFT);
    foreach ($minutes_array as $minutes) {
      $dropbox_file_path = "/articles_{$hour}{$minutes}.json";
      $articles_url = "";
      for ($j = 0; $j <= DROPBOX_RETRY_COUNT; ++$j) {
        try {
          $articles_url = getDropBoxSharedUrl($dropbox, $dropbox_file_path, true);
        } catch (Exception $e) {
          continue;
        }
        if (empty($articles_url)) {
          continue;
        } else {
          break;
        }
      }
      $articles_url_list[] = $articles_url;
    }
  }

  if (empty($articles_url_list)) {
    throw new Exception('articles_url_listの作成に失敗しました。');
  }

  $json = json_encode($articles_url_list, JSON_UNESCAPED_UNICODE);
  file_put_contents($file_path, $json);
}

function getLatestArticlesLinkList($now_i)
{
  $latest_articles_link_list = [];
  $date = date("Y-m-d H:{$now_i}:00");
  $timestamp = strtotime($date);
  $i = 0;
  while (count($latest_articles_link_list) <= 40) {
    if ($i >= 25) {
      break;
    }
    ++$i;
    $minutes = $i * 30;
    $target_time = date('Hi', strtotime("-{$minutes} minutes", $timestamp));

    $file_name = OUTPUT_ONEWS . "articles_{$target_time}.json";
    if (!file_exists($file_name)) {
      continue;
    }

    $articles = json_decode(fread(fopen($file_name, 'r'), filesize($file_name)));
    foreach ($articles as $article) {
      $latest_articles_link_list[] = $article->link;
    }
  }
  return $latest_articles_link_list;
}

function getLinks($url)
{
  $links = [];
  if ($xml = @file_get_contents($url)) {
    $feed = simplexml_load_string($xml);
    foreach ($feed->channel->item as $item) {
      $links[] = (string)$item->link;
    }
    if (empty($links)) {
      throw new Exception($url . ' 内部の取得に失敗しました.');
    }
  } else {
    throw new Exception($url . ' の取得に失敗しました.');
  };
  return $links;
}

function getData($link)
{
  $pq = phpQuery::newDocumentFile($link);
  $data['link']        = $link;
  $data['title']       = $pq['.topicsName']['h1']->text();
  $description         = $pq['.hbody']->text();
  $description         = str_replace(['\r\n', '\n', '\r'], '', $description);
  $description         = removeBrackets($description, '（', '）');
  $description         = removeBrackets($description, '(', ')');
  $description         = removeBrackets($description, '<', '>');
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
  while (strpos($target, $delimiter) !== false &&
         mb_strlen($target, 'UTF-8') > $length - 1) {
    $target_ex = explode($delimiter, $target);
    array_pop($target_ex);
    $target = implode($delimiter, $target_ex);
  }

  if (mb_strlen($target, 'UTF-8') > $length) {
    return mb_strimwidth($target, 0, $length, '…');
  }

  return (mb_substr($target, -1, 1, 'UTF-8') == $delimiter) ?
    $target : $target . $delimiter;
}

function sendMessageToSlack($webhook_url, $message)
{
  $msg = [
    'text' => $message,
  ];
  $msg = json_encode($msg);
  $msg = 'payload=' . urlencode($msg);

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $webhook_url);
  curl_setopt($ch, CURLOPT_HEADER, false);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $msg);
  curl_exec($ch);
  curl_close($ch);
}
