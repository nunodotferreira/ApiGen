<?php

/**
 * This file is part of the ApiGen (http://apigen.org)
 *
 * For the full copyright and license information, please view
 * the file license.md that was distributed with this source code.
 */

namespace ApiGen\Generator;

use ApiGen\ApiGen;
use ApiGen\Backend;
use ApiGen\Charset\CharsetConvertor;
use ApiGen\Templating\TemplateFactory;
use ApiGen\Tree;
use ApiGen\Configuration\Configuration;
use ApiGen\FileSystem;
use ApiGen\Reflection;
use ApiGen\Templating\Template;
use ArrayObject;
use InvalidArgumentException;
use Nette;
use RuntimeException;
use TokenReflection\Broker;


/**
 * Generates a HTML API documentation.
 * @method ArrayObject      getParsedClasses()
 * @method ArrayObject      getParsedConstants()
 * @method ArrayObject      getParsedFunctions()
 * @method HtmlGenerator    onParseStart($steps)
 * @method HtmlGenerator    onParseProgress($size)
 * @method HtmlGenerator    onGenerateStart($steps)
 * @method HtmlGenerator    onGenerateProgress($size)
 */
class HtmlGenerator extends Nette\Object implements Generator
{
	/**
	 * @var array
	 */
	public $onParseStart = array();

	/**
	 * @var array
	 */
	public $onParseProgress = array();

	/**
	 * @var array
	 */
	public $onGenerateStart = array();

	/**
	 * @var array
	 */
	public $onGenerateProgress = array();

	/**
	 * @var Configuration
	 */
	private $config;

	/**
	 * @var ArrayObject
	 */
	private $parsedClasses = NULL;

	/**
	 * @var ArrayObject
	 */
	private $parsedConstants = NULL;

	/**
	 * @var ArrayObject
	 */
	private $parsedFunctions = NULL;

	/**
	 * @var array
	 */
	private $packages = array();

	/**
	 * @var array
	 */
	private $namespaces = array();

	/**
	 * @var array
	 */
	private $classes = array();

	/**
	 * @var array
	 */
	private $interfaces = array();

	/**
	 * @var array
	 */
	private $traits = array();

	/**
	 * @var array
	 */
	private $exceptions = array();

	/**
	 * @var array
	 */
	private $constants = array();

	/**
	 * @var array
	 */
	private $functions = array();

	/**
	 * @var array
	 */
	private $symlinks = array();

	/**
	 * @var array
	 */
	private $files;

	/**
	 * @var Scanner
	 */
	private $scanner;

	/**
	 * @var CharsetConvertor
	 */
	private $charsetConvertor;

	/**
	 * @var SourceCodeHighlighter
	 */
	private $sourceCodeHighlighter;

	/**
	 * @var TemplateFactory
	 */
	private $templateFactory;


	public function __construct(Configuration $config, CharsetConvertor $charsetConvertor, Scanner $scanner,
	                            SourceCodeHighlighter $sourceCodeHighlighter, TemplateFactory $templateFactory)
	{
		$this->parsedClasses = new ArrayObject;
		$this->parsedConstants = new ArrayObject;
		$this->parsedFunctions = new ArrayObject;
		$this->config = $config;
		$this->charsetConvertor = $charsetConvertor;
		$this->scanner = $scanner;
		$this->sourceCodeHighlighter = $sourceCodeHighlighter;
		$this->templateFactory = $templateFactory;
	}


	/**
	 * Scans sources for PHP files.
	 */
	public function scan($sources, $exclude = array(), $extensions = array())
	{
		$this->files = $this->scanner->scan($sources, $exclude, $extensions);
		$this->symlinks = $this->scanner->getSymlinks();
	}


	/**
	 * Parses PHP files.
	 * @return array
	 * @throws \RuntimeException If no PHP files have been found.
	 */
	public function parse()
	{
		$files = $this->files;

		$this->onParseStart(array_sum($this->files));

		$broker = new Broker(
				new Backend($this),
				Broker::OPTION_DEFAULT & ~(Broker::OPTION_PARSE_FUNCTION_BODY | Broker::OPTION_SAVE_TOKEN_STREAM)
		);

		// @todo: should be service

		$errors = array();

		foreach ($files as $filePath => $size) {
			$content = $this->charsetConvertor->convertFile($filePath);

			try {
				$broker->processString($content, $filePath);

			} catch (\Exception $e) {
				$errors[] = $e;
			}

			$this->onParseProgress($size);
		}

		// Classes
		$this->parsedClasses->exchangeArray($broker->getClasses(Backend::TOKENIZED_CLASSES | Backend::INTERNAL_CLASSES | Backend::NONEXISTENT_CLASSES));
		$this->parsedClasses->uksort('strcasecmp');

		// Constants
		$this->parsedConstants->exchangeArray($broker->getConstants());
		$this->parsedConstants->uksort('strcasecmp');

		// Functions
		$this->parsedFunctions->exchangeArray($broker->getFunctions());
		$this->parsedFunctions->uksort('strcasecmp');

		$documentedCounter = function ($count, $element) {
			return $count += (int)$element->isDocumented();
		};

		return (object)array(
			'classes' => count($broker->getClasses(Backend::TOKENIZED_CLASSES)),
			'constants' => count($this->parsedConstants),
			'functions' => count($this->parsedFunctions),
			'internalClasses' => count($broker->getClasses(Backend::INTERNAL_CLASSES)),
			'documentedClasses' => array_reduce($broker->getClasses(Backend::TOKENIZED_CLASSES), $documentedCounter),
			'documentedConstants' => array_reduce($this->parsedConstants->getArrayCopy(), $documentedCounter),
			'documentedFunctions' => array_reduce($this->parsedFunctions->getArrayCopy(), $documentedCounter),
			'documentedInternalClasses' => array_reduce($broker->getClasses(Backend::INTERNAL_CLASSES), $documentedCounter),
			'errors' => $errors
		);
	}


	/**
	 * Wipes out the destination directory.
	 * @return boolean
	 */
	public function wipeOutDestination()
	{
		foreach ($this->getGeneratedFiles() as $path) {
			if (is_file($path) && !@unlink($path)) {
				return FALSE;
			}
		}

		$archive = $this->getArchivePath();
		if (is_file($archive) && !@unlink($archive)) {
			return FALSE;
		}

		return TRUE;
	}


	/**
	 * Generates API documentation.
	 * @throws \RuntimeException If destination directory is not writable.
	 */
	public function generate()
	{
		@mkdir($this->config->destination, 0755, TRUE);
		if (!is_dir($this->config->destination) || !is_writable($this->config->destination)) {
			throw new RuntimeException(sprintf('Directory "%s" isn\'t writable', $this->config->destination));
		}

		// Copy resources
		foreach ($this->config->template['resources'] as $resourceSource => $resourceDestination) {
			// File
			$resourcePath = $this->getTemplateDir() . DIRECTORY_SEPARATOR . $resourceSource;
			if (is_file($resourcePath)) {
				copy($resourcePath, $this->forceDir($this->config->destination . DIRECTORY_SEPARATOR . $resourceDestination));
				continue;
			}

			// Dir
			$iterator = Nette\Utils\Finder::findFiles('*')->from($resourcePath)->getIterator();
			foreach ($iterator as $item) {
				copy($item->getPathName(), $this->forceDir($this->config->destination . DIRECTORY_SEPARATOR . $resourceDestination . DIRECTORY_SEPARATOR . $iterator->getSubPathName()));
			}
		}

		// Categorize by packages and namespaces
		$this->categorize();

		// Prepare progressbar & stuffs
		$steps = count($this->packages)
			+ count($this->namespaces)
			+ count($this->classes)
			+ count($this->interfaces)
			+ count($this->traits)
			+ count($this->exceptions)
			+ count($this->constants)
			+ count($this->functions)
			+ count($this->config->template['templates']['common'])
			+ (int)$this->config->tree
			+ (int)$this->config->deprecated
			+ (int)$this->config->todo
			+ (int)$this->config->download
			+ (int)$this->isSitemapEnabled()
			+ (int)$this->isOpensearchEnabled()
			+ (int)$this->isRobotsEnabled();

		$tokenizedFilter = function (Reflection\ReflectionClass $class) {
			return $class->isTokenized();
		};
		$steps += count(array_filter($this->classes, $tokenizedFilter))
			+ count(array_filter($this->interfaces, $tokenizedFilter))
			+ count(array_filter($this->traits, $tokenizedFilter))
			+ count(array_filter($this->exceptions, $tokenizedFilter))
			+ count($this->constants)
			+ count($this->functions);
		unset($tokenizedFilter);

		$this->onGenerateStart($steps);

		// Prepare template
		$tmp = $this->config->destination . DIRECTORY_SEPARATOR . '_' . uniqid();
		$this->deleteDir($tmp);
		@mkdir($tmp, 0755, TRUE);
		$template = $this->templateFactory->create();
		$template->setGenerator($this);
		$template->setup();
		$template->setCacheStorage(new Nette\Caching\Storages\PhpFileStorage($tmp));
		$template->generator = ApiGen::NAME;
		$template->version = ApiGen::VERSION;
		$template->config = $this->config;
		$template->basePath = dirname($this->config->templateConfig);

		$this->registerCustomTemplateMacros($template);

		// Common files
		$this->generateCommon($template);

		// Optional files
		$this->generateOptional($template);

		// List of deprecated elements
		if ($this->config->deprecated) {
			$this->generateDeprecated($template);
		}

		// List of tasks
		if ($this->config->todo) {
			$this->generateTodo($template);
		}

		// Classes/interfaces/traits/exceptions tree
		if ($this->config->tree) {
			$this->generateTree($template);
		}

		// Generate packages summary
		$this->generatePackages($template);

		// Generate namespaces summary
		$this->generateNamespaces($template);

		// Generate classes, interfaces, traits, exceptions, constants and functions files
		$this->generateElements($template);

		// Generate ZIP archive
		if ($this->config->download) {
			$this->generateArchive();
		}

		// Delete temporary directory
		$this->deleteDir($tmp);
	}


	/**
	 * Loads template-specific macro and helper libraries.
	 * @param Template $template
	 * @throws \Exception
	 */
	private function registerCustomTemplateMacros(Template $template)
	{
		$latte = new Nette\Latte\Engine;

		if (!empty($this->config->template['options']['extensions'])) {
			$this->output("Loading custom template macro and helper libraries\n");
			$broker = new Broker(new Broker\Backend\Memory(), 0);

			$baseDir = dirname($this->config->template['config']);
			foreach ((array)$this->config->template['options']['extensions'] as $fileName) {
				$pathName = $baseDir . DIRECTORY_SEPARATOR . $fileName;
				if (is_file($pathName)) {
					try {
						$reflectionFile = $broker->processFile($pathName, TRUE);

						foreach ($reflectionFile->getNamespaces() as $namespace) {
							foreach ($namespace->getClasses() as $class) {
								if ($class->isSubclassOf('ApiGen\\MacroSet')) {
									// Macro set

									include $pathName;
									call_user_func(array($class->getName(), 'install'), $latte->compiler);

									$this->output(sprintf("  %s (macro set)\n", $class->getName()));
								} elseif ($class->implementsInterface('ApiGen\\IHelperSet')) {
									// Helpers set

									include $pathName;
									$className = $class->getName();
									$template->registerHelperLoader(callback(new $className($template), 'loader'));

									$this->output(sprintf("  %s (helper set)\n", $class->getName()));
								}
							}
						}

					} catch (\Exception $e) {
						throw new \Exception(sprintf('Could not load macros and helpers from file "%s"', $pathName), 0, $e);
					}

				} else {
					throw new \Exception(sprintf('Helper file "%s" does not exist.', $pathName));
				}
			}
		}

		$template->registerFilter($latte);
	}


	/**
	 * Categorizes by packages and namespaces.
	 * @return \ApiGen\Generator
	 */
	private function categorize()
	{
		foreach (array('classes', 'constants', 'functions') as $type) {
			foreach ($this->{'parsed' . ucfirst($type)} as $elementName => $element) {
				if (!$element->isDocumented()) {
					continue;
				}

				$packageName = $element->getPseudoPackageName();
				$namespaceName = $element->getPseudoNamespaceName();

				if ($element instanceof Reflection\ReflectionConstant) {
					$this->constants[$elementName] = $element;
					$this->packages[$packageName]['constants'][$elementName] = $element;
					$this->namespaces[$namespaceName]['constants'][$element->getShortName()] = $element;
				} elseif ($element instanceof Reflection\ReflectionFunction) {
					$this->functions[$elementName] = $element;
					$this->packages[$packageName]['functions'][$elementName] = $element;
					$this->namespaces[$namespaceName]['functions'][$element->getShortName()] = $element;
				} elseif ($element->isInterface()) {
					$this->interfaces[$elementName] = $element;
					$this->packages[$packageName]['interfaces'][$elementName] = $element;
					$this->namespaces[$namespaceName]['interfaces'][$element->getShortName()] = $element;
				} elseif ($element->isTrait()) {
					$this->traits[$elementName] = $element;
					$this->packages[$packageName]['traits'][$elementName] = $element;
					$this->namespaces[$namespaceName]['traits'][$element->getShortName()] = $element;
				} elseif ($element->isException()) {
					$this->exceptions[$elementName] = $element;
					$this->packages[$packageName]['exceptions'][$elementName] = $element;
					$this->namespaces[$namespaceName]['exceptions'][$element->getShortName()] = $element;
				} else {
					$this->classes[$elementName] = $element;
					$this->packages[$packageName]['classes'][$elementName] = $element;
					$this->namespaces[$namespaceName]['classes'][$element->getShortName()] = $element;
				}
			}
		}

		// Select only packages or namespaces
		$userPackagesCount = count(array_diff(array_keys($this->packages), array('PHP', 'None')));
		$userNamespacesCount = count(array_diff(array_keys($this->namespaces), array('PHP', 'None')));

		$namespacesEnabled = ('auto' === $this->config->groups && ($userNamespacesCount > 0 || 0 === $userPackagesCount)) || 'namespaces' === $this->config->groups;
		$packagesEnabled = ('auto' === $this->config->groups && !$namespacesEnabled) || 'packages' === $this->config->groups;

		if ($namespacesEnabled) {
			$this->packages = array();
			$this->namespaces = $this->sortGroups($this->namespaces);
		} elseif ($packagesEnabled) {
			$this->namespaces = array();
			$this->packages = $this->sortGroups($this->packages);
		} else {
			$this->namespaces = array();
			$this->packages = array();
		}

		return $this;
	}


	/**
	 * Sorts and filters groups.
	 * @param array $groups
	 * @return array
	 */
	private function sortGroups(array $groups)
	{
		// Don't generate only 'None' groups
		if (1 === count($groups) && isset($groups['None'])) {
			return array();
		}

		$emptyList = array('classes' => array(), 'interfaces' => array(), 'traits' => array(), 'exceptions' => array(), 'constants' => array(), 'functions' => array());

		$groupNames = array_keys($groups);
		$lowerGroupNames = array_flip(array_map(function ($y) {
			return strtolower($y);
		}, $groupNames));

		foreach ($groupNames as $groupName) {
			// Add missing parent groups
			$parent = '';
			foreach (explode('\\', $groupName) as $part) {
				$parent = ltrim($parent . '\\' . $part, '\\');
				if (!isset($lowerGroupNames[strtolower($parent)])) {
					$groups[$parent] = $emptyList;
				}
			}

			// Add missing element types
			foreach ($this->getElementTypes() as $type) {
				if (!isset($groups[$groupName][$type])) {
					$groups[$groupName][$type] = array();
				}
			}
		}

		$main = $this->config->main;
		uksort($groups, function ($one, $two) use ($main) {
			// \ as separator has to be first
			$one = str_replace('\\', ' ', $one);
			$two = str_replace('\\', ' ', $two);

			if ($main) {
				if (0 === strpos($one, $main) && 0 !== strpos($two, $main)) {
					return -1;
				} elseif (0 !== strpos($one, $main) && 0 === strpos($two, $main)) {
					return 1;
				}
			}

			return strcasecmp($one, $two);
		});

		return $groups;
	}


	/**
	 * Generates common files.
	 * @param \ApiGen\Template $template Template
	 * @return \ApiGen\Generator
	 */
	private function generateCommon(Template $template)
	{
		$template->namespace = NULL;
		$template->namespaces = array_keys($this->namespaces);
		$template->package = NULL;
		$template->packages = array_keys($this->packages);
		$template->class = NULL;
		$template->classes = array_filter($this->classes, $this->getMainFilter());
		$template->interfaces = array_filter($this->interfaces, $this->getMainFilter());
		$template->traits = array_filter($this->traits, $this->getMainFilter());
		$template->exceptions = array_filter($this->exceptions, $this->getMainFilter());
		$template->constant = NULL;
		$template->constants = array_filter($this->constants, $this->getMainFilter());
		$template->function = NULL;
		$template->functions = array_filter($this->functions, $this->getMainFilter());
		$template->archive = basename($this->getArchivePath());

		// Elements for autocomplete
		$elements = array();
		$autocomplete = array_flip((array)$this->config->autocomplete);
		foreach ($this->getElementTypes() as $type) {
			foreach ($this->$type as $element) {
				if ($element instanceof Reflection\ReflectionClass) {
					if (isset($autocomplete['classes'])) {
						$elements[] = array('c', $element->getPrettyName());
					}
					if (isset($autocomplete['methods'])) {
						foreach ($element->getOwnMethods() as $method) {
							$elements[] = array('m', $method->getPrettyName());
						}
						foreach ($element->getOwnMagicMethods() as $method) {
							$elements[] = array('mm', $method->getPrettyName());
						}
					}
					if (isset($autocomplete['properties'])) {
						foreach ($element->getOwnProperties() as $property) {
							$elements[] = array('p', $property->getPrettyName());
						}
						foreach ($element->getOwnMagicProperties() as $property) {
							$elements[] = array('mp', $property->getPrettyName());
						}
					}
					if (isset($autocomplete['classconstants'])) {
						foreach ($element->getOwnConstants() as $constant) {
							$elements[] = array('cc', $constant->getPrettyName());
						}
					}
				} elseif ($element instanceof Reflection\ReflectionConstant && isset($autocomplete['constants'])) {
					$elements[] = array('co', $element->getPrettyName());
				} elseif ($element instanceof Reflection\ReflectionFunction && isset($autocomplete['functions'])) {
					$elements[] = array('f', $element->getPrettyName());
				}
			}
		}
		usort($elements, function ($one, $two) {
			return strcasecmp($one[1], $two[1]);
		});
		$template->elements = $elements;

		foreach ($this->config->template['templates']['common'] as $source => $destination) {
			$template
				->setFile($this->getTemplateDir() . DIRECTORY_SEPARATOR . $source)
				->save($this->forceDir($this->config->destination . DIRECTORY_SEPARATOR . $destination));

			$this->onGenerateProgress(1);
		}

		unset($template->elements);

		return $this;
	}


	/**
	 * Generates optional files.
	 * @param \ApiGen\Template $template Template
	 * @return \ApiGen\Generator
	 */
	private function generateOptional(Template $template)
	{
		if ($this->isSitemapEnabled()) {
			$template->setFile($this->getTemplatePath('sitemap', 'optional'))
				->save($this->forceDir($this->getTemplateFileName('sitemap', 'optional')));

			$this->onGenerateProgress(1);
		}

		if ($this->isOpensearchEnabled()) {
			$template->setFile($this->getTemplatePath('opensearch', 'optional'))
				->save($this->forceDir($this->getTemplateFileName('opensearch', 'optional')));

			$this->onGenerateProgress(1);
		}

		if ($this->isRobotsEnabled()) {
			$template->setFile($this->getTemplatePath('robots', 'optional'))
				->save($this->forceDir($this->getTemplateFileName('robots', 'optional')));

			$this->onGenerateProgress(1);
		}

		return $this;
	}


	/**
	 * Generates list of deprecated elements.
	 * @param \ApiGen\Template $template Template
	 * @return \ApiGen\Generator
	 * @throws \RuntimeException If template is not set.
	 */
	private function generateDeprecated(Template $template)
	{
		$this->prepareTemplate('deprecated');

		$deprecatedFilter = function ($element) {
			return $element->isDeprecated();
		};

		$template->deprecatedMethods = array();
		$template->deprecatedConstants = array();
		$template->deprecatedProperties = array();
		foreach (array_reverse($this->getElementTypes()) as $type) {
			$template->{'deprecated' . ucfirst($type)} = array_filter(array_filter($this->$type, $this->getMainFilter()), $deprecatedFilter);

			if ('constants' === $type || 'functions' === $type) {
				continue;
			}

			foreach ($this->$type as $class) {
				if (!$class->isMain()) {
					continue;
				}

				if ($class->isDeprecated()) {
					continue;
				}

				$template->deprecatedMethods = array_merge($template->deprecatedMethods, array_values(array_filter($class->getOwnMethods(), $deprecatedFilter)));
				$template->deprecatedConstants = array_merge($template->deprecatedConstants, array_values(array_filter($class->getOwnConstants(), $deprecatedFilter)));
				$template->deprecatedProperties = array_merge($template->deprecatedProperties, array_values(array_filter($class->getOwnProperties(), $deprecatedFilter)));
			}
		}
		usort($template->deprecatedMethods, array($this, 'sortMethods'));
		usort($template->deprecatedConstants, array($this, 'sortConstants'));
		usort($template->deprecatedFunctions, array($this, 'sortFunctions'));
		usort($template->deprecatedProperties, array($this, 'sortProperties'));

		$template
			->setFile($this->getTemplatePath('deprecated'))
			->save($this->forceDir($this->getTemplateFileName('deprecated')));

		foreach ($this->getElementTypes() as $type) {
			unset($template->{'deprecated' . ucfirst($type)});
		}
		unset($template->deprecatedMethods);
		unset($template->deprecatedProperties);

		$this->onGenerateProgress(1);

		return $this;
	}


	/**
	 * Generates list of tasks.
	 * @return Generator
	 * @throws \RuntimeException If template is not set.
	 */
	private function generateTodo(Template $template)
	{
		$this->prepareTemplate('todo');

		$todoFilter = function ($element) {
			return $element->hasAnnotation('todo');
		};

		$template->todoMethods = array();
		$template->todoConstants = array();
		$template->todoProperties = array();
		foreach (array_reverse($this->getElementTypes()) as $type) {
			$template->{'todo' . ucfirst($type)} = array_filter(array_filter($this->$type, $this->getMainFilter()), $todoFilter);

			if ('constants' === $type || 'functions' === $type) {
				continue;
			}

			foreach ($this->$type as $class) {
				if (!$class->isMain()) {
					continue;
				}

				$template->todoMethods = array_merge($template->todoMethods, array_values(array_filter($class->getOwnMethods(), $todoFilter)));
				$template->todoConstants = array_merge($template->todoConstants, array_values(array_filter($class->getOwnConstants(), $todoFilter)));
				$template->todoProperties = array_merge($template->todoProperties, array_values(array_filter($class->getOwnProperties(), $todoFilter)));
			}
		}
		usort($template->todoMethods, array($this, 'sortMethods'));
		usort($template->todoConstants, array($this, 'sortConstants'));
		usort($template->todoFunctions, array($this, 'sortFunctions'));
		usort($template->todoProperties, array($this, 'sortProperties'));

		$template
			->setFile($this->getTemplatePath('todo'))
			->save($this->forceDir($this->getTemplateFileName('todo')));

		foreach ($this->getElementTypes() as $type) {
			unset($template->{'todo' . ucfirst($type)});
		}
		unset($template->todoMethods);
		unset($template->todoProperties);

		$this->onGenerateProgress(1);

		return $this;
	}


	/**
	 * @return Generator
	 * @throws \RuntimeException If template is not set.
	 */
	private function generateTree(Template $template)
	{
		$this->prepareTemplate('tree');

		$classTree = array();
		$interfaceTree = array();
		$traitTree = array();
		$exceptionTree = array();

		$processed = array();
		foreach ($this->parsedClasses as $className => $reflection) {
			if (!$reflection->isMain() || !$reflection->isDocumented() || isset($processed[$className])) {
				continue;
			}

			if (NULL === $reflection->getParentClassName()) {
				// No parent classes
				if ($reflection->isInterface()) {
					$t = &$interfaceTree;

				} elseif ($reflection->isTrait()) {
					$t = &$traitTree;

				} elseif ($reflection->isException()) {
					$t = &$exceptionTree;

				} else {
					$t = &$classTree;
				}

			} else {
				foreach (array_values(array_reverse($reflection->getParentClasses())) as $level => $parent) {
					if (0 === $level) {
						// The topmost parent decides about the reflection type
						if ($parent->isInterface()) {
							$t = &$interfaceTree;

						} elseif ($parent->isTrait()) {
							$t = &$traitTree;

						} elseif ($parent->isException()) {
							$t = &$exceptionTree;

						} else {
							$t = &$classTree;
						}
					}
					$parentName = $parent->getName();

					if (!isset($t[$parentName])) {
						$t[$parentName] = array();
						$processed[$parentName] = TRUE;
						ksort($t, SORT_STRING);
					}

					$t = &$t[$parentName];
				}
			}
			$t[$className] = array();
			ksort($t, SORT_STRING);
			$processed[$className] = TRUE;
			unset($t);
		}

		$template->classTree = new Tree($classTree, $this->parsedClasses);
		$template->interfaceTree = new Tree($interfaceTree, $this->parsedClasses);
		$template->traitTree = new Tree($traitTree, $this->parsedClasses);
		$template->exceptionTree = new Tree($exceptionTree, $this->parsedClasses);

		$template->setFile($this->getTemplatePath('tree'))
			->save($this->forceDir($this->getTemplateFileName('tree')));

		unset($template->classTree);
		unset($template->interfaceTree);
		unset($template->traitTree);
		unset($template->exceptionTree);

		$this->onGenerateProgress(1);
	}


	/**
	 * Generates packages summary.
	 * @throws \RuntimeException If template is not set.
	 */
	private function generatePackages(Template $template)
	{
		if (empty($this->packages)) {
			return $this;
		}

		$this->prepareTemplate('package');

		$template->namespace = NULL;

		foreach ($this->packages as $packageName => $package) {
			$template->package = $packageName;
			$template->subpackages = array_filter($template->packages, function ($subpackageName) use ($packageName) {
				return (bool)preg_match('~^' . preg_quote($packageName) . '\\\\[^\\\\]+$~', $subpackageName);
			});
			$template->classes = $package['classes'];
			$template->interfaces = $package['interfaces'];
			$template->traits = $package['traits'];
			$template->exceptions = $package['exceptions'];
			$template->constants = $package['constants'];
			$template->functions = $package['functions'];
			$template->setFile($this->getTemplatePath('package'))
				->save($this->config->destination . DIRECTORY_SEPARATOR . $template->getPackageUrl($packageName));

			$this->onGenerateProgress(1);
		}
		unset($template->subpackages);
	}


	/**
	 * Generates namespaces summary.
	 * @throws \RuntimeException If template is not set.
	 */
	private function generateNamespaces(Template $template)
	{
		if (empty($this->namespaces)) {
			return $this;
		}

		$this->prepareTemplate('namespace');

		$template->package = NULL;

		foreach ($this->namespaces as $namespaceName => $namespace) {
			$template->namespace = $namespaceName;
			$template->subnamespaces = array_filter($template->namespaces, function ($subnamespaceName) use ($namespaceName) {
				return (bool)preg_match('~^' . preg_quote($namespaceName) . '\\\\[^\\\\]+$~', $subnamespaceName);
			});
			$template->classes = $namespace['classes'];
			$template->interfaces = $namespace['interfaces'];
			$template->traits = $namespace['traits'];
			$template->exceptions = $namespace['exceptions'];
			$template->constants = $namespace['constants'];
			$template->functions = $namespace['functions'];
			$template->setFile($this->getTemplatePath('namespace'))
				->save($this->config->destination . DIRECTORY_SEPARATOR . $template->getNamespaceUrl($namespaceName));

			$this->onGenerateProgress(1);
		}
		unset($template->subnamespaces);
	}


	/**
	 * Generate classes, interfaces, traits, exceptions, constants and functions files.
	 * @throws \RuntimeException If template is not set.
	 */
	private function generateElements(Template $template)
	{
		if (!empty($this->classes) || !empty($this->interfaces) || !empty($this->traits) || !empty($this->exceptions)) {
			$this->prepareTemplate('class');
		}
		if (!empty($this->constants)) {
			$this->prepareTemplate('constant');
		}
		if (!empty($this->functions)) {
			$this->prepareTemplate('function');
		}
		$this->prepareTemplate('source');

		// Add @usedby annotation
		foreach ($this->getElementTypes() as $type) {
			foreach ($this->$type as $parentElement) {
				$elements = array($parentElement);
				if ($parentElement instanceof Reflection\ReflectionClass) {
					$elements = array_merge(
						$elements,
						array_values($parentElement->getOwnMethods()),
						array_values($parentElement->getOwnConstants()),
						array_values($parentElement->getOwnProperties())
					);
				}
				foreach ($elements as $element) {
					$uses = $element->getAnnotation('uses');
					if (NULL === $uses) {
						continue;
					}
					foreach ($uses as $value) {
						list($link, $description) = preg_split('~\s+|$~', $value, 2);
						$resolved = $this->resolveElement($link, $element);
						if (NULL !== $resolved) {
							$resolved->addAnnotation('usedby', $element->getPrettyName() . ' ' . $description);
						}
					}
				}
			}
		}

		$template->package = NULL;
		$template->namespace = NULL;
		$template->classes = $this->classes;
		$template->interfaces = $this->interfaces;
		$template->traits = $this->traits;
		$template->exceptions = $this->exceptions;
		$template->constants = $this->constants;
		$template->functions = $this->functions;
		foreach ($this->getElementTypes() as $type) {
			foreach ($this->$type as $element) {
				if (!empty($this->namespaces)) {
					$template->namespace = $namespaceName = $element->getPseudoNamespaceName();
					$template->classes = $this->namespaces[$namespaceName]['classes'];
					$template->interfaces = $this->namespaces[$namespaceName]['interfaces'];
					$template->traits = $this->namespaces[$namespaceName]['traits'];
					$template->exceptions = $this->namespaces[$namespaceName]['exceptions'];
					$template->constants = $this->namespaces[$namespaceName]['constants'];
					$template->functions = $this->namespaces[$namespaceName]['functions'];

				} elseif (!empty($this->packages)) {
					$template->package = $packageName = $element->getPseudoPackageName();
					$template->classes = $this->packages[$packageName]['classes'];
					$template->interfaces = $this->packages[$packageName]['interfaces'];
					$template->traits = $this->packages[$packageName]['traits'];
					$template->exceptions = $this->packages[$packageName]['exceptions'];
					$template->constants = $this->packages[$packageName]['constants'];
					$template->functions = $this->packages[$packageName]['functions'];
				}

				$template->class = NULL;
				$template->constant = NULL;
				$template->function = NULL;
				if ($element instanceof Reflection\ReflectionClass) {
					// Class
					$template->tree = array_merge(array_reverse($element->getParentClasses()), array($element));

					$template->directSubClasses = $element->getDirectSubClasses();
					uksort($template->directSubClasses, 'strcasecmp');
					$template->indirectSubClasses = $element->getIndirectSubClasses();
					uksort($template->indirectSubClasses, 'strcasecmp');

					$template->directImplementers = $element->getDirectImplementers();
					uksort($template->directImplementers, 'strcasecmp');
					$template->indirectImplementers = $element->getIndirectImplementers();
					uksort($template->indirectImplementers, 'strcasecmp');

					$template->directUsers = $element->getDirectUsers();
					uksort($template->directUsers, 'strcasecmp');
					$template->indirectUsers = $element->getIndirectUsers();
					uksort($template->indirectUsers, 'strcasecmp');

					$template->class = $element;

					$template
						->setFile($this->getTemplatePath('class'))
						->save($this->config->destination . DIRECTORY_SEPARATOR . $template->getClassUrl($element));

				} elseif ($element instanceof Reflection\ReflectionConstant) {
					// Constant
					$template->constant = $element;

					$template
						->setFile($this->getTemplatePath('constant'))
						->save($this->config->destination . DIRECTORY_SEPARATOR . $template->getConstantUrl($element));

				} elseif ($element instanceof Reflection\ReflectionFunction) {
					// Function
					$template->function = $element;

					$template
						->setFile($this->getTemplatePath('function'))
						->save($this->config->destination . DIRECTORY_SEPARATOR . $template->getFunctionUrl($element));
				}

				$this->onGenerateProgress(1);

				// Generate source codes
				if ($element->isTokenized()) {
					$template->fileName = $this->getRelativePath($element->getFileName());
					$content = $this->charsetConvertor->convertFile($element->getFileName());
					$template->source = $this->sourceCodeHighlighter->highlight($content);
					$template->setFile($this->getTemplatePath('source'))
						->save($this->config->destination . DIRECTORY_SEPARATOR . $template->getSourceUrl($element, FALSE));

					$this->onGenerateProgress(1);
				}
			}
		}
	}


	/**
	 * Creates ZIP archive.
	 * @throws \RuntimeException If something went wrong.
	 */
	private function generateArchive()
	{
		if ( ! extension_loaded('zip')) {
			throw new RuntimeException('Extension zip is not loaded');
		}

		$archive = new \ZipArchive;
		if (TRUE !== $archive->open($this->getArchivePath(), \ZipArchive::CREATE)) {
			throw new RuntimeException('Could not open ZIP archive');
		}

		$archive->setArchiveComment(trim(sprintf('%s API documentation generated by %s %s on %s', $this->config->title, self::NAME, self::VERSION, date('Y-m-d H:i:s'))));

		$directory = Nette\Utils\Strings::webalize(trim(sprintf('%s API documentation', $this->config->title)), NULL, FALSE);
		$destinationLength = strlen($this->config->destination);
		foreach ($this->getGeneratedFiles() as $file) {
			if (is_file($file)) {
				$archive->addFile($file, $directory . DIRECTORY_SEPARATOR . substr($file, $destinationLength + 1));
			}
		}

		if (FALSE === $archive->close()) {
			throw new RuntimeException('Could not save ZIP archive');
		}

		$this->onGenerateProgress(1);
	}


	/**
	 * Tries to resolve string as class, interface or exception name.
	 * @param string $className
	 * @param string $namespace
	 * @return \ApiGen\Reflection\ReflectionClass
	 */
	public function getClass($className, $namespace = '')
	{
		if (isset($this->parsedClasses[$namespace . '\\' . $className])) {
			$class = $this->parsedClasses[$namespace . '\\' . $className];

		} elseif (isset($this->parsedClasses[ltrim($className, '\\')])) {
			$class = $this->parsedClasses[ltrim($className, '\\')];

		} else {
			return NULL;
		}

		// Class is not "documented"
		if ( ! $class->isDocumented()) {
			return NULL;
		}

		return $class;
	}


	/**
	 * Tries to resolve type as constant name.
	 * @param string $constantName
	 * @param string $namespace
	 * @return \ApiGen\Reflection\ReflectionConstant
	 */
	public function getConstant($constantName, $namespace = '')
	{
		if (isset($this->parsedConstants[$namespace . '\\' . $constantName])) {
			$constant = $this->parsedConstants[$namespace . '\\' . $constantName];

		} elseif (isset($this->parsedConstants[ltrim($constantName, '\\')])) {
			$constant = $this->parsedConstants[ltrim($constantName, '\\')];

		} else {
			return NULL;
		}

		// Constant is not "documented"
		if ( ! $constant->isDocumented()) {
			return NULL;
		}

		return $constant;
	}


	/**
	 * Tries to resolve type as function name.
	 * @param string $functionName
	 * @param string $namespace
	 * @return \ApiGen\Reflection\ReflectionFunction
	 */
	public function getFunction($functionName, $namespace = '')
	{
		if (isset($this->parsedFunctions[$namespace . '\\' . $functionName])) {
			$function = $this->parsedFunctions[$namespace . '\\' . $functionName];

		} elseif (isset($this->parsedFunctions[ltrim($functionName, '\\')])) {
			$function = $this->parsedFunctions[ltrim($functionName, '\\')];

		} else {
			return NULL;
		}

		// Function is not "documented"
		if ( ! $function->isDocumented()) {
			return NULL;
		}

		return $function;
	}


	/**
	 * Tries to parse a definition of a class/method/property/constant/function and returns the appropriate instance if successful.
	 * @param string $definition Definition
	 * @param \ApiGen\Reflection\ReflectionElement|\ApiGen\Reflection\ReflectionParameter $context Link context
	 * @param string $expectedName
	 * @return \ApiGen\Reflection\ReflectionElement|NULL
	 */
	public function resolveElement($definition, $context, &$expectedName = NULL)
	{
		// No simple type resolving
		static $types = array(
			'boolean' => 1, 'integer' => 1, 'float' => 1, 'string' => 1,
			'array' => 1, 'object' => 1, 'resource' => 1, 'callback' => 1,
			'callable' => 1, 'NULL' => 1, 'false' => 1, 'true' => 1, 'mixed' => 1
		);

		if (empty($definition) || isset($types[$definition])) {
			return NULL;
		}

		$originalContext = $context;

		if ($context instanceof Reflection\ReflectionParameter && NULL === $context->getDeclaringClassName()) {
			// Parameter of function in namespace or global space
			$context = $this->getFunction($context->getDeclaringFunctionName());

		} elseif ($context instanceof Reflection\ReflectionMethod || $context instanceof Reflection\ReflectionParameter
			|| ($context instanceof Reflection\ReflectionConstant && NULL !== $context->getDeclaringClassName())
			|| $context instanceof Reflection\ReflectionProperty
		) {
			// Member of a class
			$context = $this->getClass($context->getDeclaringClassName());
		}

		if (NULL === $context) {
			return NULL;
		}

		// self, $this references
		if ('self' === $definition || '$this' === $definition) {
			return $context instanceof Reflection\ReflectionClass ? $context : NULL;
		}

		$definitionBase = substr($definition, 0, strcspn($definition, '\\:'));
		$namespaceAliases = $context->getNamespaceAliases();
		if (!empty($definitionBase) && isset($namespaceAliases[$definitionBase]) && $definition !== ($className = \TokenReflection\Resolver::resolveClassFQN($definition, $namespaceAliases, $context->getNamespaceName()))) {
			// Aliased class
			$expectedName = $className;

			if (FALSE === strpos($className, ':')) {
				return $this->getClass($className, $context->getNamespaceName());

			} else {
				$definition = $className;
			}

		} elseif ($class = $this->getClass($definition, $context->getNamespaceName())) {
			// Class
			return $class;

		} elseif ($constant = $this->getConstant($definition, $context->getNamespaceName())) {
			// Constant
			return $constant;

		} elseif (($function = $this->getFunction($definition, $context->getNamespaceName()))
			|| ('()' === substr($definition, -2) && ($function = $this->getFunction(substr($definition, 0, -2), $context->getNamespaceName())))
		) {
			// Function
			return $function;
		}

		if (($pos = strpos($definition, '::')) || ($pos = strpos($definition, '->'))) {
			// Class::something or Class->something
			if (0 === strpos($definition, 'parent::') && ($parentClassName = $context->getParentClassName())) {
				$context = $this->getClass($parentClassName);

			} elseif (0 !== strpos($definition, 'self::')) {
				$class = $this->getClass(substr($definition, 0, $pos), $context->getNamespaceName());

				if (NULL === $class) {
					$class = $this->getClass(\TokenReflection\Resolver::resolveClassFQN(substr($definition, 0, $pos), $context->getNamespaceAliases(), $context->getNamespaceName()));
				}

				$context = $class;
			}

			$definition = substr($definition, $pos + 2);

		} elseif ($originalContext instanceof Reflection\ReflectionParameter) {
			return NULL;
		}

		// No usable context
		if (NULL === $context || $context instanceof Reflection\ReflectionConstant || $context instanceof Reflection\ReflectionFunction) {
			return NULL;
		}

		if ($context->hasProperty($definition)) {
			// Class property
			return $context->getProperty($definition);

		} elseif ('$' === $definition{0} && $context->hasProperty(substr($definition, 1))) {
			// Class $property
			return $context->getProperty(substr($definition, 1));

		} elseif ($context->hasMethod($definition)) {
			// Class method
			return $context->getMethod($definition);

		} elseif ('()' === substr($definition, -2) && $context->hasMethod(substr($definition, 0, -2))) {
			// Class method()
			return $context->getMethod(substr($definition, 0, -2));

		} elseif ($context->hasConstant($definition)) {
			// Class constant
			return $context->getConstant($definition);
		}

		return NULL;
	}


	/**
	 * Checks if sitemap.xml is enabled.
	 * @return boolean
	 */
	private function isSitemapEnabled()
	{
		return !empty($this->config->baseUrl) && $this->templateExists('sitemap', 'optional');
	}


	/**
	 * Checks if opensearch.xml is enabled.
	 * @return boolean
	 */
	private function isOpensearchEnabled()
	{
		return !empty($this->config->googleCseId) && !empty($this->config->baseUrl) && $this->templateExists('opensearch', 'optional');
	}


	/**
	 * Checks if robots.txt is enabled.
	 * @return boolean
	 */
	private function isRobotsEnabled()
	{
		return !empty($this->config->baseUrl) && $this->templateExists('robots', 'optional');
	}


	/**
	 * Sorts methods by FQN.
	 * @return integer
	 */
	private function sortMethods(Reflection\ReflectionMethod $one, Reflection\ReflectionMethod $two)
	{
		return strcasecmp($one->getDeclaringClassName() . '::' . $one->getName(), $two->getDeclaringClassName() . '::' . $two->getName());
	}


	/**
	 * Sorts constants by FQN.
	 * @return integer
	 */
	private function sortConstants(Reflection\ReflectionConstant $one, Reflection\ReflectionConstant $two)
	{
		return strcasecmp(($one->getDeclaringClassName() ?: $one->getNamespaceName()) . '\\' . $one->getName(), ($two->getDeclaringClassName() ?: $two->getNamespaceName()) . '\\' . $two->getName());
	}


	/**
	 * Sorts functions by FQN.
	 * @return integer
	 */
	private function sortFunctions(Reflection\ReflectionFunction $one, Reflection\ReflectionFunction $two)
	{
		return strcasecmp($one->getNamespaceName() . '\\' . $one->getName(), $two->getNamespaceName() . '\\' . $two->getName());
	}


	/**
	 * Sorts functions by FQN.
	 * @return integer
	 */
	private function sortProperties(Reflection\ReflectionProperty $one, Reflection\ReflectionProperty $two)
	{
		return strcasecmp($one->getDeclaringClassName() . '::' . $one->getName(), $two->getDeclaringClassName() . '::' . $two->getName());
	}


	/**
	 * Returns list of element types.
	 * @return array
	 */
	private function getElementTypes()
	{
		static $types = array('classes', 'interfaces', 'traits', 'exceptions', 'constants', 'functions');
		return $types;
	}


	/**
	 * Returns main filter.
	 * @return \Closure
	 */
	private function getMainFilter()
	{
		return function ($element) {
			return $element->isMain();
		};
	}


	/**
	 * Returns ZIP archive path.
	 * @return string
	 */
	private function getArchivePath()
	{
		$name = trim(sprintf('%s API documentation', $this->config->title));
		return $this->config->destination . DIRECTORY_SEPARATOR . Nette\Utils\Strings::webalize($name) . '.zip';
	}


	/**
	 * Returns filename relative path to the source directory.
	 * @param string $fileName
	 * @return string
	 * @throws \InvalidArgumentException If relative path could not be determined.
	 */
	public function getRelativePath($fileName)
	{
		if (isset($this->symlinks[$fileName])) {
			$fileName = $this->symlinks[$fileName];
		}
		foreach ($this->config->source as $source) {
			if (FileSystem::isPhar($source)) {
				$source = FileSystem::pharPath($source);
			}
			if (0 === strpos($fileName, $source)) {
				return is_dir($source) ? str_replace('\\', '/', substr($fileName, strlen($source) + 1)) : basename($fileName);
			}
		}

		throw new InvalidArgumentException(sprintf('Could not determine "%s" relative path', $fileName));
	}


	/**
	 * @return string
	 */
	private function getTemplateDir()
	{
		return dirname($this->config->templateConfig);
	}


	/**
	 * @param string $name
	 * @param string $type
	 * @return string
	 */
	private function getTemplatePath($name, $type = 'main')
	{
		return $this->getTemplateDir() . DIRECTORY_SEPARATOR . $this->config->template['templates'][$type][$name]['template'];
	}


	/**
	 * @param string $name
	 * @param string $type
	 * @return string
	 */
	private function getTemplateFileName($name, $type = 'main')
	{
		return $this->config->destination . DIRECTORY_SEPARATOR . $this->config->template['templates'][$type][$name]['filename'];
	}


	/**
	 * @param string $name
	 * @param string $type
	 * @return string
	 */
	private function templateExists($name, $type = 'main')
	{
		return isset($this->config->template['templates'][$type][$name]);
	}


	/**
	 * Checks if template exists and creates dir.
	 * @param string $name
	 * @throws \RuntimeException If template is not set.
	 */
	private function prepareTemplate($name)
	{
		if (!$this->templateExists($name)) {
			throw new RuntimeException(sprintf('Template for "%s" is not set', $name));
		}

		$this->forceDir($this->getTemplateFileName($name));
	}


	/**
	 * Returns list of all generated files.
	 * @return array
	 */
	private function getGeneratedFiles()
	{
		$files = array();

		// Resources
		foreach ($this->config->template['resources'] as $item) {
			$path = $this->getTemplateDir() . DIRECTORY_SEPARATOR . $item;
			if (is_dir($path)) {
				$iterator = Nette\Utils\Finder::findFiles('*')->from($path)->getIterator();
				foreach ($iterator as $innerItem) {
					$files[] = $this->config->destination . DIRECTORY_SEPARATOR . $item . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
				}

			} else {
				$files[] = $this->config->destination . DIRECTORY_SEPARATOR . $item;
			}
		}

		// Common files
		foreach ($this->config->template['templates']['common'] as $item) {
			$files[] = $this->config->destination . DIRECTORY_SEPARATOR . $item;
		}

		// Optional files
		foreach ($this->config->template['templates']['optional'] as $optional) {
			$files[] = $this->config->destination . DIRECTORY_SEPARATOR . $optional['filename'];
		}

		// Main files
		$masks = array_map(function ($config) {
			return preg_replace('~%[^%]*?s~', '*', $config['filename']);
		}, (array)$this->config->template['templates']['main']);
		$filter = function ($item) use ($masks) {
			foreach ($masks as $mask) {
				if (fnmatch($mask, $item->getFilename())) {
					return TRUE;
				}
			}
			return FALSE;
		};

		/** @var \SplFileInfo $item */
		foreach (Nette\Utils\Finder::findFiles('*')->filter($filter)->from($this->config->destination) as $item) {
			$files[] = $item->getPathName();
		}

		return $files;
	}


	/**
	 * Ensures a directory is created.
	 * @param string $path
	 * @return string
	 */
	private function forceDir($path)
	{
		@mkdir(dirname($path), 0755, TRUE);
		return $path;
	}


	/**
	 * @param string $path
	 * @return boolean
	 */
	private function deleteDir($path)
	{
		if (!is_dir($path)) {
			return TRUE;
		}

		foreach (Nette\Utils\Finder::find('*')->from($path)->childFirst() as $item) {
			/** @var \SplFileInfo $item */
			if ($item->isDir()) {
				if (!@rmdir($item)) {
					return FALSE;
				}

			} elseif ($item->isFile()) {
				if (!@unlink($item)) {
					return FALSE;
				}
			}
		}

		if (!@rmdir($path)) {
			return FALSE;
		}

		return TRUE;
	}


	/**
	 * @return Generator
	 */
	public function getConfig()
	{
		return $this->config;
	}

}
