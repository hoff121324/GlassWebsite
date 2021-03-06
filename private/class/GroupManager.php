<?php
require_once(realpath(dirname(__FILE__) . '/DatabaseManager.php'));
require_once(realpath(dirname(__FILE__) . '/UserManager.php'));
require_once(realpath(dirname(__FILE__) . '/GroupObject.php'));

class GroupManager {
	private static $objectCacheTime = 3600;
	private static $userGroupsCacheTime = 3600;
	private static $groupUsersCacheTime = 3600;

	public static function getFromID($id, $resource = false) {
		$groupObject = apc_fetch('groupObject_' . $id, $success);

		if($success === false) {
			if($resource !== false) {
				$groupObject = new GroupObject($resource);
			} else {
				$database = new DatabaseManager();
				GroupManager::verifyTable($database);
				$resource = $database->query("SELECT * FROM `group_groups` WHERE `id` = '" . $database->sanitize($id) . "' LIMIT 1");

				if(!$resource) {
					throw new Exception("Database error: " . $database->error());
				}

				if($resource->num_rows == 0) {
					$groupObject = false;
				} else {
					$groupObject = new GroupObject($resource->fetch_object());
				}
				$resource->close();
			}
			apc_store('tagObject_' . $id, $groupObject, GroupManager::$objectCacheTime);
		}
		return $groupObject;
	}

	public static function getFromName($name, $resource = false) {
		$groupObject = apc_fetch('groupObject_' . $name, $success);

		if($success === false) {
			if($resource !== false) {
				$groupObject = new GroupObject($resource);
			} else {
				$database = new DatabaseManager();
				GroupManager::verifyTable($database);
				$resource = $database->query("SELECT * FROM `group_groups` WHERE `name` = '" . $database->sanitize($name) . "' LIMIT 1");

				if(!$resource) {
					throw new Exception("Database error: " . $database->error());
				}

				if($resource->num_rows == 0) {
					$groupObject = false;
				} else {
					$groupObject = new GroupObject($resource->fetch_object());
				}
				$resource->close();
			}
			apc_store('groupObject_' . $name, $groupObject, GroupManager::$objectCacheTime);
		}
		return $groupObject;
	}

	public static function getGroupsFromBLID($id) {
		$userGroups = apc_fetch('userGroups_' . $id, $success);

		if($success === false) {
			$database = new DatabaseManager();
			GroupManager::verifyTable($database);
			$resource = $database->query("SELECT * FROM `group_usermap` WHERE `blid` = '" . $database->sanitize($id) . "'");

			if(!$resource) {
				throw new Exception("Database error: " . $database->error());
			}
			$userGroups = [];

			while($row = $resource->fetch_object()) {
				$userGroups[] = GroupManager::getFromID($row->id)->getID();
			}
			$resource->close();
			apc_store('userGroups_' . $id, $userGroups, GroupManager::$userGroupsCacheTime);
		}
		return $userGroups;
	}

	public static function getUsersFromGroupID($id) {
		$groupUsers = apc_fetch('groupUsers_' . $id, $success);

		if($success === false) {
			$database = new DatabaseManager();
			GroupManager::verifyTable($database);
			$resource = $database->query("SELECT * FROM `group_groupmap` WHERE `gid` = '" . $database->sanitize($id) . "'");

			if(!$resource) {
				throw new Exception("Database error: " . $database->error());
			}
			$groupUsers = [];

			while($row = $resource->fetch_object()) {
				$groupUsers[] = UserManager::getFromID($row->blid)->getID();
			}
			$resource->close();
			apc_store('groupUsers_' . $id, $groupUsers, GroupManager::$groupUsersCacheTime); //this cache time is arbitrary
		}
		return $groupUsers;
	}

	public static function getMemberCountByID($id) {
		$count = apc_fetch('groupMemberCount_' . $id, $success);

		if($success === false) {
			$database = new DatabaseManager();
			GroupManager::verifyTable($database);
			$resource = $database->query("SELECT COUNT(*) FROM `group_usermap` WHERE `gid` = '" . $database->sanitize($id) . "'");

			if(!$resource) {
				throw new Exception("Database error: " . $database->error());
			}
			$count = $resource->fetch_row()[0];
			$resource->close();
			apc_store('groupMemberCount_' . $id, $count, GroupManager::$groupUsersCacheTime);
		}
		return $count;
	}

	//modifiers
	public static function createGroupWithLeaderBLID($name, $description, $color, $icon, $blid) {
		$user = UserManager::getFromBLID($blid);

		if($user === false) {
			return false;
		}
		return GroupManager::createGroupWithLeader($name, $description, $color, $icon, $user);
	}

	public static function createGroupWithLeader($name, $description, $color, $icon, $user) {
		$database = new DatabaseManager();
		GroupManager::verifyTable($database);
		$resource = $database->query("SELECT 1 FROM `group_groups` where `name` = '" . $database->sanitize($name) . "' LIMIT 1");

		if(!$resource) {
			throw new Exception("Database error: " . $database->error());
		}

		if($resource->num_rows > 0 ) {
			$resource->close();
			return false;
		}
		$resource->close();

		if(!$database->query("INSERT INTO `group_groups` (name, leader, description, color, icon) VALUES ('" .
			$database->sanitize($name) . "', '" .
			$database->sanitize($user->getBLID()) . "', '" .
			$database->sanitize($description) . "', '" .
			$database->sanitize($color) . "', '" .
			$database->sanitize($icon) . "')")) {
			throw new Exception("Failed to create new tag: " . $database->error());
		}
		$group = GroupManager::getFromID($database->fetchMysqli()->insert_id);

		if($group === false) {
			throw new Exception("Newly generated group not found!");
		}

		if($database->query("INSERT INTO `group_groupmap` (`gid`, `blid`, `administrator`), ('" . $database->sanitize($group->getId()) . "', '" . $database->sanitize($user->getBLID()) . "', '1')")) {
			throw new Exception("Failed to add leader to new group");
		}
		return true;
	}

	public static function addBLIDToGroupID($blid, $gid) {
		//make sure addon exists
		$user = UserManager::getFromID($blid);

		if($user === false) {
			return false;
		}

		//make sure tag exists
		$group = GroupManager::getFromID($gid);

		if($group === false) {
			return false;
		}
		//call real function
		return GroupManager::addUserToGroup($user, $group);
	}

	//maybe the select 1 ... insert could be replaced with a single conditional insert query
	public static function addUserToGroup($user, $group) {
		//check if link already exists
		$database = new DatabaseManager();
		GroupManager::verifyTable($database);
		$resource = $database->query("SELECT 1 FROM `group_groupmap` WHERE `blid` = '" . $database->sanitize($user->getBLID()) . "' AND `gid` = '" . $database->sanitize($group->getID()) . "' LIMIT 1");

		if(!$resource) {
			throw new Exception("Database error: " . $database->error());
		}

		if($resource->num_rows > 0) {
			$resource->close();
			return false;
		}
		$resource->close();

		if(!$database->query("INSERT INTO `group_groupmap` (`blid`, `gid`) VALUES ('" . $database->sanitize($user->getBLID()) . "', '" . $database->sanitize($group->getID()) . "')")) {
			throw new Exception("Error adding new groupmap entry: " . $database->error());
		}
		apc_delete('groupUsers_' . $group->getID());
		apc_delete('userGroups' . $user->getID());
	}

	public static function removeBLIDFromGroupID($blid, $gid) {
		$user = UserManager::getFromID($blid);

		if($user === false) {
			return false;
		}
		$group = GroupManager::getFromID($gid);

		if($group === false) {
			return false;
		}
		return GroupManager::removeUserFromGroup($user, $group);
	}

	//for now, leader is not able to leave group
	//in the future, the leader leaving should cause the group to be deleted
	public static function removeUserFromGroup($user, $group) {
		if($group->getLeader() === $user->getBLID()) {
			return false;
		}
		$database = new DatabaseManager();
		GroupManager::verifyTable($database);
		$resource = $database->query("SELECT 1 FROM `group_groupmap` WHERE `blid` = '" . $database->sanitize($user->getBLID()) . "' AND `gid` = '" . $database->sanitize($group->getID()) . "' LIMIT 1");

		if(!$resource) {
			throw new Exception("Database error: " . $database->error());
		}

		if($resource->num_rows == 0) {
			$resource->close();
			return false;
		}
		$resource->close();

		if(!$database->query("DELETE FROM `group_groupmap` WHERE `blid` = '" . $database->sanitize($user->getBLID()) . "' `gid` = '" . $database->sanitize($group->getID()) . "'")) {
			throw new Exception("Error removing groupmap entry: " . $database->error());
		}
		$resource->close();
		apc_delete('addonTags_' . $group->getID());
		apc_delete('tagAddons_' . $tag->getID());
		return true;
	}

	public static function createDefaultGroups() {
		GroupManager::createGroupWithLeaderBLID("Administrator", "", "EB2B36", "crown_gold", 9789);
		GroupManager::createGroupWithLeaderBLID("Moderator", "", "336699", "crown_silver", 9789);
		GroupManager::createGroupWithLeaderBLID("Reviewer", "", "00ff00", "star", 9789);
	}

	public static function verifyTable($database) {
		if($database->debug()) {
			UserManager::verifyTable($database);

			if(!$database->query("CREATE TABLE IF NOT EXISTS `group_groups` (
				`id` INT NOT NULL AUTO_INCREMENT,
				`leader` INT NOT NULL,
				`name` varchar(16) NOT NULL,
				`description` TEXT,
				`color` varchar(6) NOT NULL,
				`icon` text NOT NULL,
				FOREIGN KEY (`leader`)
					REFERENCES users(`blid`)
					ON UPDATE CASCADE
					ON DELETE CASCADE,
				PRIMARY KEY (`id`))")) {
				throw new Exception("Error creating group table: " . $database->error());
			}

			//this table might not need a primary key
			if(!$database->query("CREATE TABLE IF NOT EXISTS `group_usermap` (
				`id` INT NOT NULL AUTO_INCREMENT,
				`gid` INT NOT NULL,
				`blid` INT NOT NULL,
				`administrator` TINYINT NOT NULL DEFAULT 0,
				FOREIGN KEY (`gid`)
					REFERENCES group_groups(`id`)
					ON UPDATE CASCADE
					ON DELETE CASCADE,
				FOREIGN KEY (`blid`)
					REFERENCES users(`blid`)
					ON UPDATE CASCADE
					ON DELETE CASCADE,
				PRIMARY KEY (`id`))")) {
				throw new Exception("Error creating group usermap table: " . $database->error());
			}
		}
	}
}
?>
