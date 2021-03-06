<?php
require_once dirname(__DIR__) . "/../../../private/class/AddonManager.php";
require_once dirname(__DIR__) . "/../../../private/class/BoardManager.php";
require_once dirname(__DIR__) . "/../../../private/class/ScreenshotManager.php";

$ret = new stdClass();

if(isset($_REQUEST['id']) & $_REQUEST['id'] != "") {
  $aid = $_REQUEST['id'];
  $ret->status = "success";
} else {
  $ret->status = "error";
  $ret->error = "Add-On not found!";
  die(json_encode($ret, JSON_PRETTY_PRINT));
}

$addonObject = AddonManager::getFromID($aid);
//$screens = ScreenshotManager::getScreenshotsFromAddon($aid); //I dont think this is done


$ret->aid = $aid;
$ret->filename = $addonObject->getFilename();
$ret->boardId = $addonObject->getBoard();
$ret->board = BoardManager::getFromID($addonObject->getBoard())->getName();
$ret->name = $addonObject->getName();
$ret->description = htmlspecialchars_decode($addonObject->getDescription());

$ret->screenshots = array();
/*foreach($screens as $screen) {
  $screenshot = new stdClass();
  $screenshot->id = $screen->getId();
  $screenshot->url = "http://api.blocklandglass.com/files/screenshots/" . $addonObject->getId() . "/" . $i . ".png";
  $screenshot->thumbnail = "http://api.blocklandglass.com/files/screenshots/" . $addonObject->getId() . "/" . $i . "_thumb.png";
  list($width, $height) = getimagesize(dirname(__DIR__) . "/files/screenshots/" . $addonObject->getId() . "/" . $i . ".png");
  $screenshot->extent = $width . " " . $height;
  $ret->screenshots[] = $screenshot;
}*/

$author = new stdClass();
//$author->blid = $addonObject->getAuthor()->getBlid();
//$author->name = $addonObject->getAuthor()->getName();
$ret->authors = $author;

$channelId[1] = "stable";
$channelId[2] = "unstable";
$channelId[3] = "development";
foreach($channelId as $cid=>$name) {
  $channel = new stdClass();
  $chanDat = $addonObject->getBranchInfo($cid);

  if($chanDat !== false) {
    $channel->id = $cid;
    $channel->name = $channelId[$cid];
    $channel->version = $chanDat->version;
    // TODO
    //$channel->malicious = $chanDat->malicious;

    $ret->branches[] = $channel;
  }
}

echo json_encode($ret, JSON_PRETTY_PRINT);
?>
