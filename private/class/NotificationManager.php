<?php
require_once(realpath(dirname(__FILE__) . '/DatabaseManager.php'));
require_once(realpath(dirname(__FILE__) . '/UserManager.php'));

class NotificationManager {
	private static $objectCacheTime = 3600; //1 hour
	private static $userCacheTime = 3600;

	//avoid passing user objects instead of just a blid
	//I think we need to sit down and make sure we think this through completely
	//I thought we were going to have some sort of news/subscription system
	//Notification information should not really be plaintext, instead we should have it
	// so we have some set of parameters that define the message
	// and then have the actual message text generated when requested
	public static function createNotification($user, $text, $params) {
		if(isset($param) && !is_object($param)) {
			throw new Exception("Object expected form \$param");
		}

		if(is_object($user)) {
			$blid = $user->getBLID();
		} else {
			$blid = $user;
		}

		$database = new DatabaseManager();
		NotificationManager::verifyTable($database);

		//I hope this is all temporary
		$resource = $database->query("INSERT INTO `blocklandglass2`.`user_notifications` (`id`, `blid`, `date`, `text`, `params`, `seen`) VALUES " .
			"(NULL, '" . $database->sanitize($blid) . "', NOW(), '" . $database->sanitize($text) . "', '" . $database->sanitize(json_encode($params)) . "', '0');");
		apc_delete('userNotifications_' . $blid);
	}

	public static function getFromID($id, $resource = false) {
		$notificationObject = apc_fetch('notificationObject_' . $id, $success);

		if($success === false) {
			if($resource !== false) {
				$notificationObject = new NotificationObject($resource);
			} else {
				$database = new DatabaseManager();
				NotificationManager::verifyTable($database);
				$resource = $database->query("SELECT * FROM `user_notifications` WHERE id='" . $database->sanitize($id) . "'");

				if(!$resource) {
					throw new Exception("Database error: " . $database->error());
				}

				if($resource->num_rows == 0) {
					$notificationObject = false;
				}
				$notificationObject = new NotificationObject($resource->fetch_object());
				$resource->close();
			}
			apc_store('notificationObject_' . $id, $notificationObject, NotificationManager::$objectCacheTime);
		}
		return $notificationObject;
	}

	public static function getFromBLID($blid, $offset, $limit) {
		$userNotes = apc_fetch('userNotifications_' . $blid, $success);

		if($success === false) {
			$database = new DatabaseManager();
			NotificationManager::verifyTable($database);
			$resource = $database->query("SELECT * FROM `user_notifications` WHERE
				`blid` = '" . $database->sanitize($blid) . "'
				ORDER BY `date` DESC
				LIMIT " . $database->sanitize($offset) . ", " . $database->sanitize($limit));

			if(!$resource) {
				throw new Exception("Database error: " . $database->error());
			}
			$userNotes = [];

			while($row = $resource->fetch_object()) {
				$userNotes[] = $row->id;
			}
			$resource->close();
			apc_store('userNotifications_' . $blid, $userNotes, NotificationManager::$userCacheTime);
		}
		return $userNotes;
	}

	public static function verifyTable($database) {
		if($database->debug()) {
			UserManager::verifyTable($database);

			if(!$database->query("CREATE TABLE IF NOT EXISTS `user_notifications` (
				`id` INT NOT NULL AUTO_INCREMENT,
				`blid` INT NOT NULL,
				`date` timestamp NOT NULL,
				`text` text NOT NULL,
				`params` text NOT NULL,
				`seen` TINYINT NOT NULL DEFAULT 0,
				FOREIGN KEY (`blid`)
					REFERENCES users(`blid`)
					ON UPDATE CASCADE
					ON DELETE CASCADE,
				PRIMARY KEY (`id`))")) {
				throw new Exception("Error creating table: " . $database->error());
			}
		}
	}
}
?>
