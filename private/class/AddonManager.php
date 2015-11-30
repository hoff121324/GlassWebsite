<?php
require_once(realpath(dirname(__FILE__) . '/DatabaseManager.php'));
require_once(realpath(dirname(__FILE__) . '/AddonObject.php'));

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

	public static function uploadNewAddon($user, $name, $type, $file, $filename, $description) {
		$database = new DatabaseManager();
		AddonManager::verifyTable($database);

		$rsc = $database->query("SELECT * FROM `addon_addons` WHERE `name` = '" . $database->sanitize($name) . "'");
		if($rsc->num_rows > 0) {
			$response = [
				"message" => "An add-on by this name already exists!"
			];
			return $response;
		}

		$rsc = $database->query("SELECT * FROM `addon_addons` WHERE `filename` = '" . $database->sanitize($filename) . "'");
		if($rsc->num_rows > 0) {
			$response = [
				"message" => "An add-on with this filename already exists!"
			];
			return $response;
		}

		//generate blank version data
		$versionInfo = AddonFileHandler::getVersionInfo($file);
		if($versionInfo) {
			// TODO when I'm not on a plane...
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

		$bid = 1;

		AddonFileHandler::injectGlassFile($id, $file);
		AddonFileHandler::injectVersionInfo($id, $bid, $file); // TODO need to specify branch in upload

		require_once(realpath(dirname(__FILE__) . '/AWSFileManager.php'));
		//AWSFileManager::uploadNewBuild($id, $tempPath);
		require_once(realpath(dirname(__FILE__) . '/StatManager.php'));
		StatManager::addStatsToAddon($id);

		$response = [
			"redirect" => "/addons/upload/success.php?id=" . $id
		];
		return $response;
	}

	public static function getFromID($id, $resource = false) {
		$addonObject = apc_fetch('addonObject_' . $id, $success);

		if(!is_object($addonObject)) {
			$success = false;
		}

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
			if(is_object($addonObject)) {
				apc_store('addonObject_' . $id, $addonObject, AddonManager::$objectCacheTime);
			}
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
				FOREIGN KEY (`blid`)
					REFERENCES users(`blid`)
					ON UPDATE CASCADE
					ON DELETE CASCADE,
				FOREIGN KEY (`board`)
					REFERENCES addon_boards(`id`)
					ON UPDATE CASCADE
					ON DELETE CASCADE,
				PRIMARY KEY (`id`))")) {
				throw new Exception("Failed to create table addon_addons: " . $database->error());
			}
		}
	}
}
?>
