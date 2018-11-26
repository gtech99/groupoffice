<?php

namespace go\core;

use Exception;
use GO;
use go\core\App;
use go\core\cache\Disk;
use go\core\cache\None;
use go\core\db\Query;
use go\core\db\Utils;
use go\core\Environment;
use go\core\event\Listeners;
use go\core\jmap\Entity as Entity2;
use go\core\module\Base;
use go\core\orm\Entity;
use go\core\util\ClassFinder;
use go\core\util\Lock;
use go\modules\core\groups\model\Group;
use go\modules\core\groups\model\Settings;
use go\modules\core\modules\model\Module;
use go\modules\core\users\model\User;
use go\modules\core\users\model\UserGroup;
use PDOException;

class Installer {

	private $isInProgress = false;

	/**
	 * Check if it's installing or upgrading
	 * 
	 * @return bool
	 */
	public function isInProgress() {
		return $this->isInProgress;
	}

	/**
	 * 
	 * @param array $adminValues
	 * @param Base[] $installModules
	 * @return boolean
	 * @throws Exception
	 */
	public function install(array $adminValues = [], $installModules = []) {


		//don't cache on install
		App::get()->getCache()->flush(false);
		App::get()->setCache(new None());

		$this->isInProgress = true;

		Entity2::$trackChanges = false;

		$database = App::get()->getDatabase();

		if (count($database->getTables())) {
			throw new Exception("Database is not empty");
		}

		$database->setUtf8();

		Utils::runSQLFile(Environment::get()->getInstallFolder()->getFile("install/install.sql"));


		App::get()->getDbConnection()->query("SET FOREIGN_KEY_CHECKS=0;");
		foreach (["Admins", "Everyone", "Internal"] as $groupName) {
			$group = new Group();
			$group->name = $groupName;
			if (!$group->save()) {
				throw new Exception("Could not create group");
			}
		}

		$admin = new User();
		$admin->displayName = "System administrator";
		$admin->username = "admin";
		$admin->email = "admin@localhost.localdomain";
		$admin->setPassword("admin");

		$admin->setValues($adminValues);

		if (!isset($admin->recoveryEmail)) {
			$admin->recoveryEmail = $admin->email;
		}

		$admin->groups[] = (new UserGroup)->setValues(['groupId' => Group::ID_ADMINS]);
		//$admin->groups[] = (new UserGroup)->setValues(['groupId' => Group::ID_INTERNAL]);


		if (!$admin->save()) {
			throw new Exception("Failed to create admin user: " . var_export($admin->getValidationErrors(), true));
		}

		//By default everyone can share with any group
		Settings::get()->setDefaultGroups([Group::ID_EVERYONE]);



		$classFinder = new ClassFinder(false);
		$classFinder->addNamespace("go\\modules\\core");

		$coreModules = $classFinder->findByParent(Base::class);

		foreach ($coreModules as $coreModule) {
			$mod = new $coreModule();

			if (!$mod->install()) {
				throw new \Exception("Failed to install core module " . $coreModule);
			}
		}


		//register core entities
		$classFinder = new ClassFinder(false);
		$classFinder->addNamespace("go\\core");
		$classFinder->addNamespace("go\\modules\\core");

		$entities = $classFinder->findByParent(Entity::class);

		foreach ($entities as $entity) {
			if (!$entity::getType()) {
				return false;
			}
		}

		foreach ($installModules as $installModule) {
			$installModule->install();
		}


		//for new framework
		App::get()->getSettings()->databaseVersion = App::get()->getVersion();
		App::get()->getSettings()->save();

		App::get()->setCache(new Disk());
		Listeners::get()->init();
	}

	private $minUpgradableVerion = "6.3.53";

	public function isValidDb() {
		if (!GO()->getDatabase()->hasTable("core_module")) {
			throw new \Exception("This is not a Group-Office 6.3+ database. Please upgrade to " . $this->minUpgradableVerion . " first.");
		}

		if (GO()->getSettings()->databaseVersion !== $this->minUpgradableVerion) {
			throw new \Exception("Your version is " . GO()->getSettings()->databaseVersion . ". Please upgrade to " . $this->minUpgradableVerion . " first.");
		}
	}

	public function getUnavailableModules() {
		$modules = (new Query)
						->select('name, package')
						->from('core_module')
						->where('enabled', '=', true)
						->all();


		$unavailable = [];
		foreach ($modules as $module) {

			if (isset($module['package']) && $module['package'] != 'legacy') {
				//SKIP for now as there no encoded refactored moules yet.
				continue;
			}

			$moduleCls = "GO\\" . ucfirst($module['name']) . "\\" . ucfirst($module['name']) . "Module";

			if (is_dir(__DIR__ . '/../go/modules/core/' . $module['name']) || is_dir(__DIR__ . '/../go/modules/community/' . $module['name'])) {
				continue;
			}

			if (!class_exists($moduleCls)) {
				$unavailable[] = ["package" => $module['package'], "name" => $module['name']];
				continue;
			}

			$mod = new $moduleCls();

			if (!$mod->isAvailable()) {
				$unavailable[] = ["package" => $module['package'], "name" => $module['name']];
			}
		}
		
		return $unavailable;
		
	}

	public function upgrade() {
		$this->isInProgress = true;


		$this->isValidDb();
		
		
		$unavailable = GO()->getInstaller()->getUnavailableModules();
		if(!empty($unavailable)) {
			throw new \Exception("There are unavailable modules");
		}

		$lock = new Lock("upgrade");
		if (!$lock->lock()) {
			throw new \Exception("Upgrade is already in progress");
		}


		GO()->getDbConnection()->query("SET sql_mode=''");
		GO()->getCache()->flush(false);
		GO()->setCache(new None());
		Entity2::$trackChanges = false;
		
		if (!$this->upgradeModules()) {
			echo "\n\nA module was refactored. Rerunning...\n\n";
			$this->upgradeModules();
		}

		echo "Rebuilding cache\n";

		//reset new cache
		$cls = GO()->getConfig()['general']['cache'];
		GO()->setCache(new $cls);

		GO()->rebuildCache();
		App::get()->getSettings()->databaseVersion = App::get()->getVersion();
		App::get()->getSettings()->save();
		
		echo "Resetting state\n";
		
		GO()->resetSyncState();
		
		echo "Registering all entities\n";		
		$modules = Module::find()->all();
		foreach($modules as $module) {
			if(isset($module->package) && $module->isAvailable()) {
				$module->module()->registerEntities();
			}
		}
	

		echo "Done!\n";
	}

	private function upgradeModules() {
		$u = [];

		$modules = Module::find()->all();

		$root = GO()->getEnvironment()->getInstallFolder();

		$modulesById = [];
		/* @var $module Module */
		foreach ($modules as $module) {

			if (!$module->isAvailable()) {
				echo "Skipping module " . $module->name . " because it's not available.\n";
				continue;
			}

			$modulesById[$module->id] = $module;

			if ($module->package == null) {


				//old not refactored yet
				$upgradefile = $root->getFile('modules/' . $module->name . '/install/updates.php');
				if (!$upgradefile->exists()) {
					$upgradefile = $root->getFile('modules/' . $module->name . '/install/updates.inc.php');
				}
			} else {
				$upgradefile = $module->module()->getFolder()->getFile('install/updates.php');
			}

			if (!$upgradefile->exists()) {
				continue;
			}

			$updates = array();
			require($upgradefile);

			//put the updates in an extra array dimension so we know to which module
			//they belong too.
			foreach ($updates as $timestamp => $updatequeries) {
				$u["$timestamp"][$module->id] = $updatequeries;
			}
		}

		ksort($u);

		$counts = array();

		$aModuleWasUpgradedToNewBackend = false;

		foreach ($u as $timestamp => $updateQuerySet) {

			foreach ($updateQuerySet as $moduleId => $queries) {

				//echo "Getting updates for ".$module."\n";
				$module = $modulesById[$moduleId];

				if (!is_array($queries)) {
					exit("Invalid queries in module: " . $module->name);
				}


				if (!isset($counts[$moduleId]))
					$counts[$moduleId] = 0;

//			/	echo $module->name ." installed version ".$module->version ." new version: ".$counts[$moduleId] ."\n";



				foreach ($queries as $query) {
					$counts[$moduleId] ++;
					if ($counts[$moduleId] <= $module->version) {
						continue;
					}

					if (is_callable($query)) {
						echo "Running callable function\n";
						call_user_func($query);
					} else if (substr($query, 0, 7) == 'script:') {
						$updateScript = $root->getFile('modules/' . $module->name . '/install/updatescripts/' . substr($query, 7));

						if (!$updateScript->exists()) {
							die($updateScript . ' not found!');
						}

						//if(!$quiet)
						echo 'Running ' . $updateScript . "\n";
						call_user_func(function() use ($updateScript) {
							require_once($updateScript);
						});
					} else {
						echo 'Excuting query: ' . $query . "\n";
						flush();
						try {
							if (!empty($query))
								GO()->getDbConnection()->query($query);
						} catch (PDOException $e) {
							//var_dump($e);		


							$errorsOccurred = true;

							echo $e->getMessage() . "\n";
							echo "Query: " . $query . "\n";
							echo "Package: " . ($module->package ?? "legacy") . "\n";
							echo "Module: " . $module->name . "\n";
							echo "Module installed version: " . $module->version . "\n";
							echo "Module source version: " . $counts[$moduleId] . "\n";

							if ($e->getCode() == 42000 || $e->getCode() == '42S21' || $e->getCode() == '42S01' || $e->getCode() == '42S22') {
								//duplicate and drop errors. Ignore those on updates
							} else {
								die();
							}
						}
					}

					flush();

					echo ($module->package ?? "legacy") . "/" . $module->name . ' updated from ' . $module->version . ' to ' . $counts[$moduleId] . "\n";


					//$moduleModel = GO\Base\Model\Module::model()->findByName($module);
					//refetch module to see if package was updated
					if (!$module->package) {
						$module = Module::findById($moduleId);
						$newBackendUpgrade = $module->package != null;
						if ($newBackendUpgrade) {
							$module->version = $counts[$moduleId] = 0;
							$aModuleWasUpgradedToNewBackend = true;
						} else {
							$module->version = $counts[$moduleId];
						}
					} else {
						$module->version = $counts[$moduleId];
					}

					//exit();

					if (!$module->save()) {
						throw new \Exception("Failed to save module");
					}
				}
			}
		}

		return !$aModuleWasUpgradedToNewBackend;
	}

}
