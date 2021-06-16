<?php


namespace go\core\model;


use go\core\orm\Property;

class Permission extends Property {

	public $moduleId;
	public $groupId;
	protected $rights;

	protected static function defineMapping() {
		return parent::defineMapping()->addTable("core_permission");
	}

	public function hasRight($name){
		$types = $this->ownerEntity->module()->getRights();
		return !!($this->rights & $types[$name]);
	}

	// int to [name => bool]
	public function getRights(){
		$types = $this->ownerEntity->module()->getRights();
		$rights = [];
		foreach($types as $name => $bit){
			if($this->rights & $bit) {
				$rights[$name] = true;
			}
		}
		return $rights;
	}

	// [name => bool] to int
	public function setRights($rights){
		$types = $this->ownerEntity->module()->getRights();
		$this->rights = 0; // need to post all active rights this way
		foreach($rights as $name => $isTrue){
			if(!isset($types[$name])) continue; // do not set invalid permissions
			if($isTrue) {
				$this->rights |= $types[$name]; // add
			} else {
				$this->rights ^= $types[$name]; // remove
			}
		}
	}
}