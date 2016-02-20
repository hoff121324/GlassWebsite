<?php
require_once(realpath(dirname(__FILE__) . '/DatabaseManager.php'));
require_once(realpath(dirname(__FILE__) . '/AddonObject.php'));
require_once(realpath(dirname(__FILE__) . '/NotificationManager.php'));

//this should be the only class to interact with table `addon_addons`
class AddonManager {
	private static $indexCacheTime = 3600;
	private static $objectCacheTime = 3600;
	private static $searchCacheTime = 600;

	public static $maxFileSize = 50000000; //50 mb

	public static $SORTNAMEASC = 0;
	public static $SORTNAMEDESC = 1;
	public static $SORTDOWNLOADASC = 2;
	public static $SORTDOWNLOADDESC = 3;
	public static $SORTRATINGASC = 4; //aka bad ratings first I think
	public static $SORTRATINGDESC = 5;

	//this stuff should probably be moved to a different class
	//I don't know how often this is going to be called and in what context,
	// but it looks like an expensive function so things like session_write_close()
	// should be utilized
	public static function checkUpstreamRepos() {
		$channelId[1] = "stable";
		$channelId[2] = "unstable";
		$channelId[3] = "development";

		$addons = AddonManager::getAll();
		foreach($addons as $addon) {
			$versionInfo = $addon->getVersionInfo();
			if(isset($versionInfo->upstream)) {
				$upstream = $versionInfo->upstream;

				//For consistency I like using echo() with parenthesis and sticking with 'ID' instead of 'Id'
				echo $addon->getId() . "\n";
				$url = $upstream->url;
				if(isset($upstream->mod)) {
					$url .= "?mods=" . $upstream->mod;
				}

				if(strpos($url, "http") !== 0) {
					$url = "http://" . $url;
				}

				$opts = array(
				  'http'=>array(
				    'method'=>"GET",
				    'header'=>"Accept-language: en\r\n" .
				              "User-Agent: Torque/1.3\r\n"
				  )
				);

				$context = stream_context_create($opts);
				$response = file_get_contents($url, false, $context);

				if($response !== false) {
					if(($res = json_decode($response)) !== null) {
						$a = "add-ons";
						foreach($res->$a as $ad) {
							if($ad->name == $addon->getFilename()) {
								foreach($ad->channels as $channel=>$info) {
									if(in_array($channel, $upstream->branch)) {
										$localBranchId = array_search($channel, $upstream->branch);
										echo "remote [$channel]:" . $info->version . "\n";
										echo "local [$localBranchId]:" . $addon->getBranchInfo($localBranchId)->version . "\n";
										if($info->version !== $addon->getBranchInfo($localBranchId)->version) {
											AddonManager::doUpstreamUpdate($addon->getId(), $localBranchId, $info->file, $info->version);
										}
									}
								}
							}
						}
					} else {
						echo($response);
						$lines = explode("\n", $response);
						//basically we're going to construct a fake json response
						foreach($lines as $line) {
							$line = trim($line);
							if(!isset($res)) {
								if(strpos($line, "<addon:") === 0) {
									$ad = substr($line, 7, strlen($line)-8);
									$res = new stdClass();
									$res->name = "$ad.zip";
								}
							} else {
								if(strpos($line, "</addon>") === 0) {
									break;
								}

								if(!isset($currentChannel)) {
									if(strpos($line, "<channel:") === 0) {
										$ch = substr($line, 9, strlen($line)-10);
										$currentChannel = new stdClass();
										$currentChannel->name = $ch;
									}
								} else {
									if(strpos($line, "</channel>") === 0) {
										$res->channels[$currentChannel->name] = $currentChannel;
										unset($currentChannel);
									}

									if(strpos($line, "<version:") === 0) {
										$ch = substr($line, 9, strlen($line)-10);
										$currentChannel->version = $ch;
									}

									if(strpos($line, "<file:") === 0) {
										$file = substr($line, 6, strlen($line)-7);
										$currentChannel->file = $file;
									}
								}
							}
						}

						if(isset($res)) {
							foreach($res->channels as $channel=>$info) {
								$arr = (array) $upstream->branch;
								if(in_array($channel, $arr)) {
									$localBranchId = array_search($channel, $arr);
									echo "remote [$channel]:" . $info->version . "\n";
									echo "local [$localBranchId]:" . $addon->getBranchInfo($localBranchId)->version . "\n";
									//if($info->version !== $addon->getBranchInfo($localBranchId)->version) {
										AddonManager::doUpstreamUpdate($addon->getId(), $localBranchId, $info->file, $info->version);
									//}
								}
							}
						}
					}
				} else {

				}
			}
		}
	}

	public static function doUpstreamUpdate($aid, $branchId, $file, $version) {
		$dir = dirname(__DIR__) . "/../addons/upload/files/";
		$addonObject = AddonManager::getFromID($aid);

		if(strpos($file, "http") !== 0) {
			$file = "http://" . $file;
		}

		$filename = "upstream_{$branchId}_" . $addonObject->getFilename();
		$filepath = $dir . $filename;
		file_put_contents($filepath, fopen($file, 'r'));
		echo "\n\nDownloaded $file to $filepath\n\n";

		AddonManager::submitUpdate($addonObject, $version, $branchId, realpath($filepath), "Imported from upstream.");
	}

	//this whole channel/branch system seems like a mess and we really should rethink this
	//I believe the ideal solution is to keep everything simple and not have branches.
	//The average end user does not care about different branches and there are a lot of
	// features that are required for them to not be a complete pain for developers.
	//However I understand that there is already quite a bit invested in the system.
	//I think we should move away from the whole channels idea and maybe have something
	// similar to steam betas if anything, where developers can put out "beta" versions
	public static function submitUpdate($addon, $version, $branch, $file, $changelog) {
		if(!is_object($addon)) {
			$addon = AddonManager::getFromID($addon);
		}

		// TODO Pending updates
		// keep the file and update on record, but wait for approval before processing entirely
		$versionInfo = $addon->getVersionInfo();

		$channelId[1] = "stable";
		$channelId[2] = "unstable";
		$channelId[3] = "development";

		$chan = $versionInfo->$channelId[$branch];
		$pending = new stdClass();
		$pending->version = $version;
		$pending->file = $file; //locally kept file
		$pending->changelog = $changelog;
		$pending->submitted = time();
		$chan->pending = $pending;

		$db = new DatabaseManager();
		$db->query("UPDATE `addon_addons` SET `versionInfo` = '" . $db->sanitize(json_encode($versionInfo)) . "' WHERE `id` = '" . $db->sanitize($addon->getId()) . "';");

		apc_delete('addonObject_' . $addon->getId());
	}

	public static function doUpdate($addon, $version, $branch, $file, $changelog) {
		// TODO Processing update
		// this is the part that actually makes the update go live
	}

	public static function uploadNewAddon($user, $name, $type, $file, $filename, $description) {
		$database = new DatabaseManager();
		AddonManager::verifyTable($database);

		$rsc = $database->query("SELECT * FROM `addon_addons` WHERE `name` = '" . $database->sanitize($name) . "' LIMIT 1");

		//I think we should enforce a unique file name, but not a unique addon name
		if($rsc->num_rows > 0) {
			$response = [
				"message" => "An add-on by this name already exists!"
			];
			$rsc->close();
			return $response;
		}
		$rsc->close();

		$rsc = $database->query("SELECT * FROM `addon_addons` WHERE `filename` = '" . $database->sanitize($filename) . "'");
		if($rsc->num_rows > 0) {
			$response = [
				"message" => "An add-on with this filename already exists!"
			];
			$rsc->close();
			return $response;
		}
		$rsc->close();


		$bid = 1; // TODO Specify branch in upload

		//generate blank version data
		$versionInfo = AddonFileHandler::getVersionInfo($file);
		var_dump($versionInfo);
		if($versionInfo !== false) {
			// information to use for upstream repos
			$version = new stdClass();
			$version->stable = new stdClass();
			$version->stable->version = $versionInfo->version;
			$version->stable->restart = "0.0.0";

			$url = parse_url($versionInfo->repo->url);

			if(!isset($url['host'])) {
				$url['host'] = $versionInfo->repo->url;
			}

			if($url['host'] == "blocklandglass.com" || $url['host'] == "api.blocklandglass.com") {
				// nothing?
			} else {
				$upstream = new stdClass();
				$upstream->url = $versionInfo->repo->url;
				if(isset($versionInfo->repo->mod))
					$upstream->mod = $versionInfo->repo->mod;
				$upstream->branch = array();
				$upstream->branch[$bid] = $versionInfo->channel;
				$version->upstream = $upstream;
			}
		} else {
			$version = new stdClass();
			$version->stable = new stdClass();
			$version->stable->version = "0.0.0";
			$version->stable->restart = "0.0.0";

			$repo = new stdClass();
		}

		$authorInfo = new stdClass();
		$authorInfo->blid = $user->getBlid();
		$authorInfo->main = true;
		$authorInfo->role = "Manager";
		$authorArray = [$authorInfo];

		// NOTE boards will be decided by reviewers now, they just seem to confuse and anger people
		// I think making that change at this point will cause more problems than it solves.
		// It is better to just have reviewers move boards
		$res = $database->query("INSERT INTO `addon_addons` (`id`, `board`, `blid`, `name`, `filename`, `description`, `versionInfo`, `authorInfo`, `reviewInfo`, `deleted`, `approved`, `uploadDate`) VALUES " .
		"(NULL," .
		"NULL," .
		"'" . $database->sanitize($user->getBlid()) . "'," .
		"'" . $database->sanitize($name) . "'," .
		"'" . $database->sanitize($filename) . "'," .
		"'" . $database->sanitize($description) . "'," .
		"'" . $database->sanitize(json_encode($version)) . "'," .
		"'" . $database->sanitize(json_encode($authorArray)) . "'," .
		"'{}'," .
		"'0'," .
		"'0'," .
		"CURRENT_TIMESTAMP);");
		if(!$res) {
			throw new Exception("Database error: " . $database->error());
		}

		$id = $database->fetchMysqli()->insert_id;

		AddonFileHandler::injectGlassFile($id, $file);
		//AddonFileHandler::injectVersionInfo($id, $bid, $file); // TODO need to specify branch in upload

		require_once(realpath(dirname(__FILE__) . '/AWSFileManager.php'));
		//AWSFileManager::uploadNewAddon($id, $bid, $filename, $file);
		require_once(realpath(dirname(__FILE__) . '/StatManager.php'));
		StatManager::addStatsToAddon($id);

		$response = [
			"redirect" => "/addons/upload/success.php?id=" . $id
		];
		return $response;
	}

	//to do: should have dedicated board move function instead
	public static function approveAddon($id, $board, $approver) {
		$database = new DatabaseManager();

		//to do: check for mysql error and handle it
		$database->query("UPDATE `addon_addons` SET `approved`='1', `board`='" . $database->sanitize($board) . "' WHERE `id`='" . $database->sanitize($id) . "'");
		apc_delete('addonObject_' . $id);

		$manager = AddonManager::getFromId($id)->getManagerBLID();

		$params = new stdClass();
		$params->vars = array();

		$user = new stdClass();
		$user->type = "user";
		$user->blid = $approver;

		$addon = new stdClass();
		$addon->type = "addon";
		$addon->id = $id;

		$params->vars[] = $user;
		$params->vars[] = $addon;
		NotificationManager::createNotification($manager, '$2 was approved by $1', $params);
	}

	public static function getFromID($id, $resource = false) {
		$addonObject = apc_fetch('addonObject_' . $id, $success);

		//if(!is_object($addonObject)) {
		//	$success = false;
		//}

		if($success === false) {
			if($resource !== false) {
				$addonObject = new AddonObject($resource);
			} else {
				$database = new DatabaseManager();
				AddonManager::verifyTable($database);
				$resource = $database->query("SELECT * FROM `addon_addons` WHERE `id` = '" . $database->sanitize($id) . "'");

				if(!$resource) {
					throw new Exception("Database error: " . $database->error());
				}

				if($resource->num_rows == 0) {
					$addonObject = false;
				} else {
					$addonObject = new AddonObject($resource->fetch_object());
				}
				$resource->close();
			}
			//cache result for one hour
			//I don't think we want this check because storing 'false' on failure is intentional
			//if(is_object($addonObject)) {
				apc_store('addonObject_' . $id, $addonObject, AddonManager::$objectCacheTime);
			//}
		}
		return $addonObject;
	}

	/**
	 *  $search - contains a number of optional parameters in an array
	 *  	$name - (STRING) string to search for in addon name
	 *  	$blid - (INT) BLID of addon uploader
	 *  	$board - (INT) id of board to search in
	 *  	$tag - (INT ARRAY) an array of integers representing tag ids
	 *  	$offset - (INT) offset for results
	 *  	$limit - (INT) maximum number of results to return, defaults to 10
	 *  	$sort - (INT) a number representing the sorting method, defaults to ORDER BY `name` ASC
	 *
	 *  	Needs to be updated to reflect new tag system
	 */
	public static function searchAddons($search) { //$name = false, $blid = false, $board = false, $tag = false) {
		//Caching this seems difficult and can cause issues with stale data easily
		//oh well whatever
		if(!isset($search['offset'])) {
			$search['offset'] = 0;
		}

		if(!isset($search['limit'])) {
			$search['limit'] = 10;
		}

		if(!isset($search['sort'])) {
			$search['sort'] = AddonManager::$SORTNAMEASC;
		}
		$cacheString = serialize($search);
		$searchAddons = apc_fetch('searchAddons_' . $cacheString);

		if($searchAddons === false) {
			$database = new DatabaseManager();
			AddonManager::verifyTable($database);
			$query = "SELECT * FROM `addon_addons` WHERE ";

			if(isset($search['name'])) {
				$query .= "`name` LIKE '%" . $database->sanitize($search['name']) . "%' AND ";
			}

			if(isset($search['blid'])) {
				$query .= "`blid` = '" . $database->sanitize($search['blid']) . "' AND ";
			}

			if(isset($search['board'])) {
				$query .= "`board` = '" . $database->sanitize($search['board']) . "' AND ";
			}

			//to do: tag searching, probably requires quite a bit of this to be reworked
			//if(isset($search['tag']) && !empty($search['tag']) {
			//	//
			//	$query .= "`tags` LIKE '%" . $database->sanitize($search['tag']) . "%' AND ";
			//}
			$query .= "`deleted` = 0 ORDER BY ";

			switch($search['sort']) {
				case AddonManager::$SORTNAMEASC:
					$query .= "`name` ASC ";
					break;
				case AddonManager::$SORTNAMEDESC:
					$query .= "`name` DESC ";
					break;
				case AddonManager::$SORTDOWNLOADASC:
					$query .= "(`downloads_web` + `downloads_ingame` + `downloads_update`) ASC ";
					break;
				case AddonManager::$SORTDOWNLOADSDESC:
					$query .= "(`downloads_web` + `downloads_ingame` + `downloads_update`) DESC ";
					break;
				case AddonManager::$SORTRATINGASC:
					$query .= "-rating DESC "; //this forces NULL values to be last
					break;
				case AddonManager::$SORTRATINGDESC:
					$query .= "`rating` ASC ";
					break;
				default:
					$query .= "`name` ASC ";
			}
			$query .= "LIMIT " . $database->sanitize(intval($search['offset'])) . ", " . $database->sanitize(intval($search['limit']));
			$resource = $database->query($query);

			if(!$resource) {
				throw new Exception("Database error: " . $database->error());
			}
			$searchAddons = [];

			while($row = $resource->fetch_object()) {
				$searchAddons[] = AddonManager::getFromID($row->id, $row)->getID();
			}
			$resource->close();
			apc_store('searchAddons_' . $cacheString, $searchAddons, AddonManager::$searchCacheTime);
		}
		return $searchAddons;
	}

	//Approval information should be in its own table probably
	//the only thing that needs to be in the addons table is the true/false value
//	public static function getUnapproved() {
//		$unapprovedAddons = apc_fetch('unapprovedAddons');
//
//		if($unapprovedAddons === false) {
//			$database = new DatabaseManager();
//			AddonManager::verifyTable($database);
//			$resource = $database->query("SELECT * FROM `addon_addons` WHERE `approved` = 0");
//
//			if(!$resource) {
//				throw new Exception("Database error: " . $database->error());
//			}
//			$unapprovedAddons = [];
//
//			while($row = $resource->fetch_object()) {
//				$unapprovedAddons[] = AddonManager::getFromID($row->id, $row)->getID();
//			}
//			$resource->close();
//			apc_store('unapprovedAddons', $unapprovedAddons, AddonManager::$searchCacheTime);
//		}
//		return $unapprovedAddons;
//	}


	//	$ret = array();
	//	foreach(AddonManager::getAll() as $addon) {
	//		if($addon->isDeleted() || $addon->getFile($addon->getLatestBranch())->getMalicious() == 2) {
	//			continue;
	//		}
    //
	//		$info = json_decode($addon->getApprovalInfo());
	//		if(isset($info->format) && $info->format == 2) {
	//			if(sizeof($info->reports) < 5) {
	//				$ret[] = $addon;
	//			}
	//		} else if($info == null) {
	//			$ret[] = $addon;
	//		}
	//	}
	//	return $ret;
	//}

	//bargain should be changed to a board
	//this should probably just call searchAddons()
	public static function getFromBoardID($id, $offset = 0, $limit = 10) {
		//the downside to this is that managing the cache is more difficult
		return AddonManager::searchAddons([
			"board" => $id,
			"offset" => $offset,
			"limit" => $limit
		]);
	}

		//$boardAddons = apc_fetch('boardAddons_' . $id . '_' . $offset . '_' . $limit);
        //
		//if($boardAddons === false) {
		//	$database = new DatabaseManager();
		//	AddonManager::verifyTable($database);
		//	$query = "SELECT * FROM `addon_addons` WHERE board='" . $database->sanitize($id) . "' AND deleted=0 ORDER BY `name` ASC";
        //
		//	if($limit > 0) {
		//		$query .= " LIMIT " . $database->sanitize($offset) . ", " . $database->sanitize($limit);
		//	}
		//	$resource = $database->query($query);
        //
		//	if(!$resource) {
		//		throw new Exception("Database error: " . $database->error());
		//	}
		//	$boardAddons = [];
        //
		//	while($row = $resource->fetch_object()) {
		//		$boardAddons[] = AddonManager::getFromID($row->id, $row)->getID();
		//	}
		//	$resource->close();
		//	apc_store('boardAddons_' . $id . '_' . $offset . '_' . $limit, $boardAddons, AddonManager::$searchCacheTime);
		//}
		//return $boardAddons;

	//bargain bin should probably just be a board instead of a flag in the database
//	public static function getBargain() {
//		$ret = array();
//
//		$db = new DatabaseManager();
//		$res = $db->query("SELECT `id` FROM `addon_addons` WHERE bargain=1 AND deleted=0 AND danger=0");
//		while($obj = $res->fetch_object()) {
//			$ret[$obj->id] = AddonManager::getFromId($obj->id);
//		}
//		$res->close();
//		return $ret;
//	}

	//this should probably be a board too
//	public static function getDangerous() {
//		$ret = array();
//
//		$db = new DatabaseManager();
//		$res = $db->query("SELECT `id` FROM `addon_addons` WHERE deleted=0 AND danger=1");
//		while($obj = $res->fetch_object()) {
//			$ret[$obj->id] = AddonManager::getFromId($obj->id);
//		}
//		return $ret;
//	}

	//this function should probably take a blid or aid instead of an object
	//should probably switch from Author to BLID for consistency
	//this should also probably just use searchAddons(0
	public static function getFromBLID($blid, $offset = 0, $limit = 10) {
		return AddonManager::searchAddons([
			"blid" => $blid,
			"offset" => $offset,
			"limit" => $limit
		]);
	}
	//	$authorAddons = apc_fetch('authorAddons_' . $blid);
    //
	//	if($authorAddons === false) {
	//		$authorAddons = array();
	//		$database = new DatabaseManager();
	//		AddonManager::verifyTable($database);
    //
	//		//include deleted addons here?
	//		$resource = $database->query("SELECT * FROM `addon_addons` WHERE `blid` = '" . $database->sanitize($blid) . "'");
    //
	//		if(!$resource) {
	//			throw new Exception("Database error: " . $database->error());
	//		}
    //
	//		while($row = $resource->fetch_object()) {
	//			$authorAddons[$row->id] = AddonManager::getFromId($row->id, $row);
	//		}
	//		$resource->close();
	//		apc_store('authorAddons_' . $blid, $authorAddons, AddonManager::$searchCacheTime);
	//	}
	//	return $authorAddons;
	//}

	//from a caching perspective, I already have each board cached, so I would like to avoid duplicate data
	//oh well, this function isn't actually used anyway
	public static function getAll() {
		$ret = array();

		$db = new DatabaseManager();
		$res = $db->query("SELECT `id` FROM `addon_addons`");
		while($obj = $res->fetch_object()) {
			$ret[$obj->id] = AddonManager::getFromId($obj->id);
		}
		return $ret;
	}

	//to do: caching
	public static function getUnapproved() {
		$ret = array();

		$db = new DatabaseManager();
		$res = $db->query("SELECT `id` FROM `addon_addons` WHERE `approved`='0'");
		while($obj = $res->fetch_object()) {
			$ret[$obj->id] = AddonManager::getFromId($obj->id);
		}
		return $ret;
	}

	public static function getCountFromBoard($boardID) {
		$count = apc_fetch('boardData_count_' . $boardID);

		if($count === false) {
			$database = new DatabaseManager();
			AddonManager::verifyTable($database);
			$resource = $database->query("SELECT COUNT(*) FROM `addon_addons` WHERE board='" . $boardID . "'  AND deleted=0");

			if(!$resource) {
				throw new Exception("Database error: " . $database->error());
			}
			$count = $resource->fetch_row()[0];
			$resource->close();

			//Cache result for 1 hour
			//Ideally we cache indefinitely and flush the value when it updates
			//But I get the feeling that we may forget and end up with stale values
			apc_store('boardData_count_' . $boardID, $count, AddonManager::$indexCacheTime);
		}
		return $count;
	}

	public static function clearSearchCache() {
		$cached = new APCIterator('searchAddons_');
		apc_delete($cached);
	}

	//returns an array of just the ids in order
	//we should really be doing that more instead of caching entire objects in multiple places
	public static function getNewAddons($count = 10) {
		$count += 0;
		$newestAddonIDs = apc_fetch('newestAddonIDs_' . $count, $success);

		if($success === false) {
			$database = new DatabaseManager();
			AddonManager::verifyTable($database);
			$resource = $database->query("SELECT * FROM `addon_addons` ORDER BY `uploadDate` DESC LIMIT " . $database->sanitize($count));

			if(!$resource) {
				throw new Exception("Database error: " . $database->error());
			}
			$newestAddonIDs = [];

			while($row = $resource->fetch_object()) {
				$newestAddonIDs[] = AddonManager::getFromID($row->id, $row)->getID();
			}
			$resource->close();
			apc_store('newestAddonIDs_' . $count, $newestAddonIDs, AddonManager::$searchCacheTime);
		}
		return $newestAddonIDs;
	}

	public static function verifyTable($database) {
		/*TO DO:
			- screenshots
			- tags
			- approval info should probably be in a different table,
			or actually maybe not I dunno
			- do we really need stable vs testing vs dev?
			- bargain/danger should probably be boards
			- figure out how data is split between addon and file
			- I don't know much about how the file system works, but
			having 'name', 'file', 'filename', and a separate 'addon_files'
			table doesn't seem ideal.
			- Maybe we should just keep track of total downloads instead
			of 3 different columns
			- I think users should just credit people in their descriptions
			instead of having a dedicated authorInfo json object
		*/
		if($database->debug()) {
			require_once(realpath(dirname(__FILE__) . '/UserManager.php'));
			require_once(realpath(dirname(__FILE__) . '/BoardManager.php'));
			UserManager::verifyTable($database);
			BoardManager::verifyTable($database);

			//why is the blid foreign key constraint removed?
			//If you want to be able to set blid to null, you should reconsider.
			//blid is used to determine which account has control over the addon.
			//There is no need to set it to null.  Just default it to some admin.
			if(!$database->query("CREATE TABLE IF NOT EXISTS `addon_addons` (
				`id` INT NOT NULL AUTO_INCREMENT,
				`board` INT,
				`blid` INT NOT NULL,
				`name` VARCHAR(30) NOT NULL,
				`filename` TEXT NOT NULL,
				`description` TEXT NOT NULL,
				`versionInfo` TEXT NOT NULL,
				`authorInfo` TEXT NOT NULL,
				`reviewInfo` TEXT NOT NULL,
				`repositoryInfo` TEXT NOT NULL,
				`deleted` TINYINT NOT NULL DEFAULT 0,
				`approved` TINYINT NOT NULL DEFAULT 0,
				`uploadDate` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				FOREIGN KEY (`board`)
					REFERENCES addon_boards(`id`)
					ON UPDATE CASCADE
					ON DELETE CASCADE,,
				FOREIGN KEY (`blid`)
					REFERENCES users(`blid`)
					ON UPDATE CASCADE
					ON DELETE CASCADE,
				PRIMARY KEY (`id`))")) {
				throw new Exception("Failed to create table addon_addons: " . $database->error());
			}
		}
	}
}
?>
