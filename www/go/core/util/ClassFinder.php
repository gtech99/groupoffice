<?php

namespace go\core\util;

use Closure;
use go\core\App;
use go\core\Environment;
use go\core\fs\Folder;
use go\core\model\Module;
use ReflectionClass;
use go\core\fs\File;

/**
 * Finds classes within Group-Office.
 * 
 * This only finds classes in the new framwwork under "go/*".
 * 
 * Warning: Using this is expensive. Caching the results is recommended.
 * 
 */
class ClassFinder {	
	
	/**
	 * Get all the Group-Office namespaces to search in.
	 * 
	 * @return string[]
	 */
	public static function getDefaultNamespaces() {		
		$ns = GO()->getCache()->get("class-finder-default-namespaces");
		
		if(!$ns) {
			$ns = ['go\\core'];		
			
			$modules = Module::find()->where(['enabled' => true]);
			foreach ($modules as $module) {
				if(!isset($module->package) || $module->package == "core" ||  !$module->isAvailable()) {
					continue;
				}
				$namespace = "go\\modules\\" . $module->package . "\\" . $module->name;
				$ns[] = $namespace;
			}
			
			GO()->getCache()->set("class-finder-default-namespaces", $ns);
		}
		
		return $ns;
	}

	/**
	 * 
	 * @param boolean $useDefaultNamespaces Load go\\core and all installed module namespaces
	 */
	public function __construct($useDefaultNamespaces = true) {
		if($useDefaultNamespaces) {			
			foreach(self::getDefaultNamespaces() as $namespace){
				$this->addNamespace($namespace);
			}
		}
	}

	private $namespaces = [];
	private $allClasses;

	/**
	 * Add a namespace to search
	 * 
	 * @param string $namespace
	 * @param Folder $folder If not given it will use the installation root + namespace
	 */
	public function addNamespace($namespace, Folder $folder = null) {		
		if(!isset($folder)) {
			$folder = Environment::get()->getInstallFolder()->getFolder(str_replace('\\', '/', $namespace));
		}		
		$this->namespaces[$namespace] = $folder;
	}

	/**
	 * Find all classes
	 * 
	 * @param string[] Full class name without leading "\" eg. ["IFW\App"]
	 */
	public function find() {
		
		GO()->debug("ClassFinder find() used.");
		
		$this->allClasses = [];
		foreach ($this->namespaces as $namespace => $folder) {

			$classesForNs = App::get()->getCache()->get('ns-classes-'.$namespace);

			if(!isset($classesForNs)) {
				$classesForNs = $this->folderToClassNames($folder, $namespace);
				App::get()->getCache()->set('ns-classes-'.$namespace, $classesForNs);
			}

			$this->allClasses = array_merge($this->allClasses, $classesForNs);
		}

		sort($this->allClasses);
		
		return $this->allClasses;
	}

	/**
	 * Find class names that are sub classes of the given class or implement this as an interface
	 * 
	 * @param string $name Parent class name or interface eg. go\core\orm\Record::class
	 * @param string[] Full class name eg. ["go\core\App"]
	 */
	public function findByParent($name) {
		return $this->findBy(function($className) use ($name) {
							$reflection = new ReflectionClass($className);
							return $reflection->isInstantiable() && ($reflection->isSubclassOf($name) || in_array($name, $reflection->getInterfaceNames()));
						});
	}
	
	/**
	 * Find classes that use a given trait
	 * 
	 * @param string $name Name of the trait eg. go\core\db\Searchable::class
	 * @return string[] Full class name eg. ["go\core\App"]
	 */
	public function findByTrait($name) {
		 return $this->findBy(function($className) use($name){
			return in_array($name, class_uses($className));
		});
	}

	/**
	 * Find class names by a closure function
	 * 
	 * If you return true in the closure function it will be included in the results.
	 * The closure funciton is called with the class name
	 * 
	 * @param Closure $fn
	 * @param string[] Full class name eg. ["go\core\App"]
	 */
	public function findBy(Closure $fn) {
		$classes = $this->find();

		$r = [];
		foreach ($classes as $class) {
			if ($fn($class)) {
				$r[] = $class;
			}
		}

		return $r;
	}

	private function hasLicense(File $file, $namespace) {
		//check for pro license on business package
		if(!strstr($namespace, "go\\modules\\business")) {
			return true;
		}

		//check data for presence of ionCube in code.
		// $data = $file->getContents(0, 100);
		$data = file_get_contents($file->getPath(), false, null, 0, 100);
		if(strpos($data, 'ionCube') === false) {			
			return true;
		}

		if(!extension_loaded('ionCube Loader')) {
			return false;
		}
		
		if(!GO()->getEnvironment()->getInstallFolder()->getFile('groupoffice-pro-' . substr(GO()->getVersion(), 0, 3) .' license.txt')->exists()) {
			return false;
		}

		return true;
	}

	private function folderToClassNames(Folder $folder, $namespace) {	

		$classes = [];
		foreach ($folder->getFiles() as $file) {

			if ($file->getExtension() == 'php') {

				$name = $file->getNameWithoutExtension();
				$firstChar = substr($name, 0, 1);
				if($firstChar !== strtoupper($firstChar)) {
					//skip filenames that start with a lower case char
					continue;
				}

				if(!$this->hasLicense($file, $namespace)) {
					continue;
				}

				$className = $namespace . '\\'. $name;

				if (!class_exists($className)) {
					continue;
				}

				$classes[] = $className;
			}
		}

		foreach ($folder->getFolders() as $folder) {
			$classes = array_merge($classes, $this->folderToClassNames($folder, $namespace . '\\' . $folder->getName()));
		}

		return $classes;
	}

}
