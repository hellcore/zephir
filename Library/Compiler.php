<?php

/*
 +--------------------------------------------------------------------------+
 | Zephir Language                                                          |
 +--------------------------------------------------------------------------+
 | Copyright (c) 2013 Zephir Team and contributors                          |
 +--------------------------------------------------------------------------+
 | This source file is subject the MIT license, that is bundled with        |
 | this package in the file LICENSE, and is available through the           |
 | world-wide-web at the following url:                                     |
 | http://zephir-lang.com/license.html                                      |
 |                                                                          |
 | If you did not receive a copy of the MIT license and are unable          |
 | to obtain it through the world-wide-web, please send a note to           |
 | license@zephir-lang.com so we can mail you a copy immediately.           |
 +--------------------------------------------------------------------------+
*/

/**
 * Compiler
 *
 * Main compiler
 */
class Compiler
{

	protected $_files;

	protected $_definitions;

	protected $_compiledFiles;

	protected static $_reflections = array();

	const VERSION = '0.2.1a';

	/**
	 * Pre-compiles classes creating a CompilerFile definition
	 *
	 * @param string $filePath
	 */
	protected function _preCompile($filePath)
	{
		if (preg_match('/\.zep$/', $filePath)) {
			$className = str_replace('/', '\\', $filePath);
			$className = preg_replace('/.zep$/', '', $className);
			$className = join('\\', array_map(function($i) { return ucfirst($i); }, explode('\\', $className)));
			$this->_files[$className] = new CompilerFile($className, $filePath);
			$this->_files[$className]->preCompile();
			$this->_definitions[$className] = $this->_files[$className]->getClassDefinition();
		}
	}

	/**
	 *
	 * @param string $path
	 */
	protected function _recursivePreCompile($path)
	{
		/**
		 * Pre compile all files
		 */
		$files = array();
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);
		foreach ($iterator as $item) {
			if (!$item->isDir()) {
				$files[] = $item->getPathname();
			}
		}
		sort($files, SORT_STRING);
		foreach ($files as $file) {
			$this->_preCompile($file);
		}
	}

	/**
	 * Allows to check if a class is part of the compiled extension
	 *
	 * @param string $className
	 * @return bolean
	 */
	public function isClass($className)
	{
		foreach ($this->_definitions as $key => $value) {
			if (!strcasecmp($key, $className)) {
				if ($value->getType() == 'class') {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Allows to check if an interface is part of the compiled extension
	 *
	 * @param string $className
	 * @return bolean
	 */
	public function isInterface($className)
	{
		foreach ($this->_definitions as $key => $value) {
			if (!strcasecmp($key, $className)) {
				if ($value->getType() == 'interface') {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Allows to check if a class is part of PHP
	 *
	 * @param string $className
	 * @return bolean
	 */
	public function isInternalClass($className)
	{
		return class_exists($className, false);
	}

	/**
	 * Allows to check if a interface is part of PHP
	 *
	 * @param string $className
	 * @return bolean
	 */
	public function isInternalInterface($className)
	{
		return interface_exists($className, false);
	}

	/**
	 * Returns class the class definition from a given class name
	 *
	 * @param string $className
	 * @return ClassDefinition
	 */
	public function getClassDefinition($className)
	{
		foreach ($this->_definitions as $key => $value) {
			if (!strcasecmp($key, $className)) {
				return $value;
			}
		}
		return false;
	}

	/**
	 * Returns class the class definition from a given class name
	 *
	 * @param string $className
	 * @return ClassDefinition
	 */
	public function getInternalClassDefinition($className)
	{
		if (!isset(self::$_reflections[$className])) {
			self::$_reflections[$className] = new ReflectionClass($className);
		}
		return self::$_reflections[$className];
	}

	/**
	 * Copies the base kernel to the extension destination
	 *
	 * @param string $path
	 */
	protected function _copyBaseKernel($path)
	{
		/**
		 * Pre compile all files
		 */
		$iterator = new DirectoryIterator($path);
		foreach ($iterator as $item) {
			if ($item->isDir()) {
				if ($item->getFileName() != '.' && $item->getFileName() != '..') {
					$this->_copyBaseKernel($item->getPathname());
				}
			} else {
				if (preg_match('/\.[hc]$/', $item->getPathname())) {
					if (strpos($item->getPathName(), 'alternative') !== false) {
						copy($item->getPathname(), 'ext/kernel/alternative/' . $item->getBaseName());
					} else {
						copy($item->getPathname(), 'ext/kernel/' . $item->getBaseName());
					}
				}
			}
		}
	}

	/**
	 * Initializes a zephir extension
	 *
	 * @param Config $config
	 * @param Config $logger
	 */
	public function init($config, $logger)
	{

		if (!is_dir('.temp')) {
			mkdir('.temp');
		}

		$namespace = strtolower(preg_replace('/[^0-9a-zA-Z]/', '', basename(getcwd())));
		if (!$namespace) {
			throw new Exception("Cannot obtain a valid initial namespace for the project");
		}

		file_put_contents('config.json', '{"namespace": "' . $namespace . '"}');

		/**
		 * Create 'kernel'
		 */
		if (!is_dir('ext')) {
			mkdir('ext');
			mkdir('ext/kernel');
			mkdir('ext/kernel/alternative');
			$this->_copyBaseKernel(__DIR__ . '/../ext/kernel/');
			copy(__DIR__ . '/../ext/install', 'ext/install');
			chmod('ext/install', 0755);
		}

		if (!is_dir($namespace)) {
			mkdir($namespace);
		}
	}

	/**
	 *
	 * @param Config $config
	 * @param Config $logger
	 */
	public function compile($config, $logger)
	{

		if (!file_exists('config.json')) {
			throw new Exception("Zephir extension is not initialized in this directory");
		}

		if (!is_dir('.temp')) {
			mkdir('.temp');
		}

		/**
		 * Get global namespace
		 */
		$namespace = $config->get('namespace');
		if (!$namespace) {
			throw new Exception("Extension namespace cannot be loaded");
		}

		/**
		 * Round 1. pre-compile all files in memory
		 */
		$this->_recursivePreCompile($namespace);
		if (!count($this->_files)) {
			throw new Exception("Zephir files to compile weren't found");
		}

		/**
		 * Round 2. Check 'extends' and 'implements' dependencies
		 */
		foreach ($this->_files as $compileFile) {
			$compileFile->checkDependencies($this, $config, $logger);
		}

		/**
		 * Round 3. compile all files to C sources
		 */
		$files = array();
		foreach ($this->_files as $compileFile) {
			$compileFile->compile($this, $config, $logger);
			$files[] = $compileFile->getCompiledFile();
		}

		$this->_compiledFiles = $files;

		/**
		 * Round 4. create config.m4 and config.w32 files
		 */
		$this->createConfigFiles($namespace);

		/**
		 * Round 5. create project.c and project.h files
		 */
		$this->createProjectFiles($namespace);
	}

	/**
	 * Compiles an installs the extension
	 */
	public function install()
	{
		if (!file_exists('ext/Makefile')) {
			system('export CC="gcc" && export CFLAGS="-O0 -g" && cd ext && phpize --silent && ./configure --silent --enable-test && sudo make --silent install 1> /dev/null');
		} else {
			system('cd ext && sudo make --silent install 1> /dev/null');
		}
	}

	/**
	 * Create config.m4 and config.w32 by compiled files to test extension
	 *
	 * @param string $project
	 */
	public function createConfigFiles($project)
	{

		$content = file_get_contents(__DIR__ . '/../templates/config.m4');
		if (empty($content)) {
			throw new Exception("Template config.m4 doesn't exists");
		}

		$toReplace = array(
			'%PROJECT_LOWER%' 		=> strtolower($project),
			'%PROJECT_UPPER%' 		=> strtoupper($project),
			'%PROJECT_CAMELIZE%' 	=> ucfirst($project),
			'%FILES_COMPILED%' 		=> implode(' ', $this->_compiledFiles),
		);

		foreach ($toReplace as $mark => $replace) {
			$content = str_replace($mark, $replace, $content);
		}

		file_put_contents('ext/config.m4', $content);

		/**
		 * php_ext.h
		 */
		$content = file_get_contents(__DIR__ . '/../templates/php_ext.h');
		if (empty($content)) {
			throw new Exception("Template php_ext.h doesn't exists");
		}

		$toReplace = array(
			'%PROJECT_LOWER%' 		=> strtolower($project)
		);

		foreach ($toReplace as $mark => $replace) {
			$content = str_replace($mark, $replace, $content);
		}

		file_put_contents('ext/php_ext.h', $content);

		/**
		 * ext.h
		 */
		$content = file_get_contents(__DIR__ . '/../templates/ext.h');
		if (empty($content)) {
			throw new Exception("Template ext.h doesn't exists");
		}

		$toReplace = array(
			'%PROJECT_LOWER%' 		=> strtolower($project)
		);

		foreach ($toReplace as $mark => $replace) {
			$content = str_replace($mark, $replace, $content);
		}

		file_put_contents('ext/ext.h', $content);

		/**
		 * ext_config.h
		 */
		$content = file_get_contents(__DIR__ . '/../templates/ext_config.h');
		if (empty($content)) {
			throw new Exception("Template ext_config.h doesn't exists");
		}

		$toReplace = array(
			'%PROJECT_LOWER%' 		=> strtolower($project)
		);

		foreach ($toReplace as $mark => $replace) {
			$content = str_replace($mark, $replace, $content);
		}

		file_put_contents('ext/ext_config.h', $content);
	}

	/**
	 * Create project.c and project.h by compiled files to test extension
	 *
	 * @param string $project
	 */
	public function createProjectFiles($project)
	{

		/**
		 * project.c
		 */
		$content = file_get_contents(__DIR__ . '/../templates/project.c');
		if (empty($content)) {
			throw new Exception("Template project.c doesn't exist");
		}

		$files = $this->_files;

		/**
		 * Round 1. Calculate the dependency rank
		 * Classes are ordered according to a dependency ranking
		 * Classes that are dependencies of classes that are dependency of other classes
		 * have more weight
		 */
		foreach ($files as $file) {
			$classDefinition = $file->getClassDefinition();
			if ($classDefinition) {
				$classDefinition->calculateDependencyRank();
			}
		}

		/**
		 * Round 1.5 Make a second pass to ensure classes will have the correct weight
		 */
		foreach ($files as $file) {
			$classDefinition = $file->getClassDefinition();
			if ($classDefinition) {
				$classDefinition->calculateDependencyRank();
			}
		}

		$classEntries = array();
		$classInits = array();

		$interfaceEntries = array();
		$interfaceInits = array();

		/**
		 * Round 2. Generate the ZEPHIR_INIT calls according to the dependency rank
		 */
		foreach ($files as $file) {
			$classDefinition = $file->getClassDefinition();
			if ($classDefinition) {
				$dependencyRank = $classDefinition->getDependencyRank();
				if ($classDefinition->getType() == 'class') {
					if (!isset($classInits[$dependencyRank])) {
						$classEntries[$dependencyRank] = array();
						$classInits[$dependencyRank] = array();
					}
					$classEntries[$dependencyRank][] = 'zend_class_entry *' . $classDefinition->getClassEntry() . ';';
					$classInits[$dependencyRank][] = 'ZEPHIR_INIT(' . $classDefinition->getCNamespace() . '_' . $classDefinition->getName() . ');';
				} else {
					if (!isset($interfaceInits[$dependencyRank])) {
						$interfaceEntries[$dependencyRank] = array();
						$interfaceInits[$dependencyRank] = array();
					}
					$interfaceEntries[$dependencyRank][] = 'zend_class_entry *' . $classDefinition->getClassEntry() . ';';
					$interfaceInits[$dependencyRank][] = 'ZEPHIR_INIT(' . $classDefinition->getCNamespace() . '_' . $classDefinition->getName() . ');';
				}
			}
		}

		krsort($classInits);
		krsort($classEntries);
		krsort($interfaceInits);
		krsort($interfaceEntries);

		$completeInterfaceInits = array();
		foreach ($interfaceInits as $dependencyRank => $rankInterfaceInits) {
			asort($rankInterfaceInits, SORT_STRING);
			$completeInterfaceInits = array_merge($completeInterfaceInits, $rankInterfaceInits);
		}

		$completeInterfaceEntries = array();
		foreach ($interfaceEntries as $dependencyRank => $rankInterfaceEntries) {
			asort($rankInterfaceEntries, SORT_STRING);
			$completeInterfaceEntries = array_merge($completeInterfaceEntries, $rankInterfaceEntries);
		}

		$completeClassInits = array();
		foreach ($classInits as $dependencyRank => $rankClassInits) {
			asort($rankClassInits, SORT_STRING);
			$completeClassInits = array_merge($completeClassInits, $rankClassInits);
		}

		$completeClassEntries = array();
		foreach ($classEntries as $dependencyRank => $rankClassEntries) {
			asort($rankClassEntries, SORT_STRING);
			$completeClassEntries = array_merge($completeClassEntries, $rankClassEntries);
		}

		$toReplace = array(
			'%PROJECT_LOWER%' 		=> strtolower($project),
			'%PROJECT_UPPER%' 		=> strtoupper($project),
			'%PROJECT_CAMELIZE%' 	=> ucfirst($project),
			'%CLASS_ENTRIES%' 		=> implode(PHP_EOL, array_merge($completeInterfaceEntries, $completeClassEntries)),
			'%CLASS_INITS%'			=> implode(PHP_EOL . "\t", array_merge($completeInterfaceInits, $completeClassInits)),
		);

		foreach ($toReplace as $mark => $replace) {
			$content = str_replace($mark, $replace, $content);
		}

		/**
		 * Round 3. Generate and place the entry point of the project
		 */
		file_put_contents('ext/' . $project . '.c', $content);
		unset($content);

		/**
		 * Round 4. Generate the project main header
		 */
		$content = file_get_contents(__DIR__ . '/../templates/project.h');
		if (empty($content)) {
			throw new Exception("Template project.h doesn't exists");
		}

		$includeHeaders = array();
		foreach ($this->_compiledFiles as $file) {
			if ($file) {
				$fileH = str_replace(".c", ".h", $file);
				$include = '#include "' . $fileH . '"';
				$includeHeaders[] = $include;
			}
		}

		$toReplace = array(
			'%INCLUDE_HEADERS%' => implode(PHP_EOL, $includeHeaders)
		);

		foreach ($toReplace as $mark => $replace) {
			$content = str_replace($mark, $replace, $content);
		}

		file_put_contents('ext/' . $project . '.h', $content);

		/**
		 * Round 5. Create php_project.h
		 */
		$content = file_get_contents(__DIR__ . '/../templates/php_project.h');
		if (empty($content)) {
			throw new Exception("Template php_project.h doesn't exist");
		}

		$toReplace = array(
			'%PROJECT_LOWER%' 		=> strtolower($project),
			'%PROJECT_UPPER%' 		=> strtoupper($project),
			'%PROJECT_EXTNAME%' 	=> strtolower($project),
			'%PROJECT_VERSION%' 	=> '0.0.1'
		);

		foreach ($toReplace as $mark => $replace) {
			$content = str_replace($mark, $replace, $content);
		}

		file_put_contents('ext/php_' . $project . '.h', $content);

	}

	/**
	 * Shows an exception opening the file and highlighing the wrong part
	 *
	 * @param Exception $e
	 * @param Config $config
	 */
	protected static function showException(Exception $e, Config $config)
	{
		echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
		if (method_exists($e, 'getExtra')) {
			$extra = $e->getExtra();
			if (is_array($extra)) {
				if (isset($extra['file'])) {
					echo PHP_EOL;
					$lines = file($extra['file']);
					if (isset($lines[$extra['line'] - 1])) {
						$line = $lines[$extra['line'] - 1];
						echo "\t", str_replace("\t", " ", $line);
						if (($extra['char'] - 1) > 0) {
							echo "\t", str_repeat("-", $extra['char'] - 1), "^", PHP_EOL;
						}
					}
				}
			}
		}
		echo PHP_EOL;
		if ($config->get('verbose')) {
			echo 'at ', str_replace(ZEPHIRPATH, '', $e->getFile()), '(', $e->getLine(), ')', PHP_EOL;
			echo str_replace(ZEPHIRPATH, '', $e->getTraceAsString()), PHP_EOL;
		}
		exit(1);
	}

	/**
	 * Boots the compiler executing the specified action
	 */
	public static function boot()
	{
		try {

			$c = new Compiler();

			/**
			 * Global config
			 */
			$config = new Config();

			/**
			 * Global logger
			 */
			$logger = new Logger($config);

			if (isset($_SERVER['argv'][1])) {
				$action = $_SERVER['argv'][1];
			} else {
				$action = 'compile';
			}

			/**
			 * Change configurations flags
			 */
			if ($_SERVER['argc'] >= 2) {
				for ($i = 2; $i < $_SERVER['argc']; $i++) {

					$parameter = $_SERVER['argv'][$i];
					if (preg_match('/^-fno-([a-z0-9\-]+)/', $parameter, $matches)) {
						$config->set($matches[1], false);
						continue;
					}

					if (preg_match('/^-W([a-z0-9\-]+)/', $parameter, $matches)) {
						$logger->set($matches[1], false);
						continue;
					}

					switch ($parameter) {
						case '-w':
							$config->set('silent', true);
							break;
						case '-v':
							$config->set('verbose', true);
							break;
						default:
							break;
					}
				}
			}

			switch ($action) {
				case 'init':
					$c->init($config , $logger);
					break;
				case 'compile-only':
					$c->compile($config , $logger);
					break;
				case 'compile':
					$c->compile($config , $logger);
					$c->install($config , $logger);
					break;
				case 'version':
					echo self::VERSION, PHP_EOL;
					break;
				default:
					throw new Exception('Unrecognized action "' . $action . '"');
			}

		} catch (Exception $e) {
			self::showException($e, $config);
		}
	}

	/**
	 * Returns a short path
	 *
	 * @param string $path
	 */
	public static function getShortPath($path)
	{
		return str_replace(ZEPHIRPATH . DIRECTORY_SEPARATOR, '', $path);
	}

	/**
	 * Returns a short user path
	 *
	 * @param string $path
	 */
	public static function getShortUserPath($path)
	{
		return str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $path);
	}

}
