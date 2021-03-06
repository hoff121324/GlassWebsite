<?php
require_once(realpath(dirname(__FILE__) . '/GroupManager.php'));

class GroupObject {
	public $id;
	public $leader;
	public $name;
	public $description;
	public $color;
	public $icon;
	public $memberCount;

	public function __construct($resource) {
		$this->id = $resource->id;
		$this->leader = $resource->leader;
		$this->name = $resource->name;
		$this->description = $resource->description;
		$this->color = $resource->color;
		$this->icon = $resource->icon;
		$this->memberCount = GroupManager::getMemberCountByID($this->id);
	}

	public function getID() {
		return $this->id;
	}

	public function getLeader() {
		return $this->name;
	}

	public function getName() {
		return $this->name;
	}

	public function getDescription() {
		return $this->description;
	}

	public function getColor() {
		return $this->color;
	}

	public function getIcon() {
		return $this->icon;
	}

	public function getMemberCount() {
		return $this->memberCount;
	}
}
?>
