<?php
/**
 * WController.php
 */

defined('WITYCMS_VERSION') or die('Access denied');

/**
 * WController is the base class that will be inherited by all the applications.
 *
 * @package System\WCore
 * @author Johan Dufau <johan.dufau@creatiwity.net>
 * @author Julien Blatecky <julien.blatecky@creatiwity.net>
 * @version 0.5.0-11-02-2016
 */
abstract class WController {
	/**
	 * @var array Context of the application describing app's name, app's directory and app's main class
	 */
	private $context = array();

	/**
	 * @var array Manifest of the application
	 */
	private $manifest;

	/**
	 * @var mixed Model class to retrieve application's data from the database
	 */
	protected $model;

	/**
	 * @var WView The view object linked to the application
	 */
	protected $view;

	 /**
	 * @var string Action that was performed in this application
	 */
	protected $action = '';

	/**
	 * @var array List of headers for this view
	 */
	private $headers = array();

	/**
	 * Application initialization
	 *
	 * @param array $context Context of the application describing app's name, app's directory and app's main class
	 */
	public function init(array $context) {
		$this->context = $context;

		// Initialize view if the app's constructor did not do it
		if (is_null($this->view)) {
			$this->setView(new WView());
		}

		// Forward the context to the View
		$this->view->setContext($this->context);

		// Parse the manifest
		$this->manifest = $this->loadManifest($this->getAppName());
		if (empty($this->manifest)) {
			WNote::error('app_no_manifest', WLang::get('error_app_no_manifest', $this->getAppName()));
		}
	}

	/**
	 * Default Launch method
	 *
	 * Launch method determines the method that must be executed in the application.
	 * Most of the time, the given action in URL is used.
	 */
	public function launch($action, array $params = array()) {
		// Execute the corresponding method for the given action in the URL
		return $this->forward($action, $params);
	}

	/**
	 * Calls the application's method which is associated to the $action value
	 *
	 * @param string $action Action to execute
	 * @param array  $params Parameters to forward to the app's controller (given in URL or retriever)
	 * @return array The model generated by the action
	 */
	public final function forward($action, array $params = array()) {
		if (!empty($action)) {
			$tpl = WSystem::getTemplate();

			// Assigns action in template
			$this->action = $action;

			if (!$this->context['parent']) {
				$tpl->assign('wity_action', $this->action, true);
			}

			$access_result = $this->hasAccess(($this->getAdminContext() ? 'admin/' : '').$this->getAppName().'/'.$action);

			if ($access_result !== true) {
				if (is_array($access_result)) {
					return $access_result; // $this->hasAccess() returned a note
				}

				if ($this->getAdminContext() && empty($_SESSION['access'])) {
					return WNote::error('not_an_admin', WLang::get('error_not_an_admin'));
				}

				return WNote::error('app_no_access', WLang::get('error_app_no_access', $action, $this->getAppName()));
			}

			// Theme configuration for admin
			if ($this->getAdminContext() && !$this->hasParent()) {
				$manifest = $this->getManifest();

				$tpl->assign(array(
					'wity_admin_has_submenu' => $manifest['admin_has_submenu'],
					'wity_admin_actions'     => $manifest['admin']
				));

				$tpl->assign('wity_page_title', sprintf('Admin &raquo; %s%s',
					ucwords($manifest['name']),
					isset($manifest['admin'][$this->action]) ? ' &raquo; '.WLang::get($manifest['admin'][$this->action]['description']) : ''
				));
			}

			// Declare the language directory
			WLang::declareLangDir($this->context['directory'].'lang');

			// Execute action
			$executable_action = preg_replace('#[^a-z_]#', '', $action);
			if (method_exists($this, $executable_action)) {
				return $this->$executable_action($params);
			} else {
				WNote::error('app_no_method', WLang::get('error_app_no_method', $executable_action, $this->getAppName()), 'debug');
				return array();
			}
		} else {
			return WNote::error('app_no_suitable_action', WLang::get('error_app_no_suitable_action', $this->getAppName()));
		}
	}

	/**
	 * Returns the application's name
	 *
	 * @return string application's name
	 */
	public function getAppName() {
		return $this->context['app'];
	}

	/**
	 * Returns the application's context
	 *
	 * @return array Application's context
	 */
	public function getContext($field = '') {
		if (!empty($field)) {
			return (isset($this->context[$field])) ? $this->context[$field] : '';
		}

		return $this->context;
	}

	/**
	 * Defines a new application's context
	 *
	 * @param array $context
	 */
	public function setContext($context) {
		$this->context = $context;

		if (!empty($this->view)) {
			$this->view->setContext($context);
		}
	}

	/**
	 * Returns true if there is a parent in the context, false otherwise
	 *
	 * @return bool true if there is a parent in the context, false otherwise
	 */
	public function hasParent() {
		return $this->context['parent'];
	}

	/**
	 * Returns if the application is in admin mode or not
	 *
	 * @return bool true if admin mode defined in context, false otherwise
	 */
	public function getAdminContext() {
		return $this->context['admin'];
	}

	/**
	 * Sets the private view property to $view
	 *
	 * @param WView $view the view that will be associated to this instance of the controller
	 */
	public function setView(WView $view) {
		unset($this->view);
		$this->view = $view;
	}

	/**
	 * Returns the current view
	 *
	 * @return WView the current view
	 */
	public function getView() {
		return $this->view;
	}

	/**
	 * Defines a new model for this controller
	 */
	public function setModel($model) {
		unset($this->model);
		$this->model = $model;
	}

	/**
	 * Get the model defined for this application
	 *
	 * @return Object
	 */
	public function getModel() {
		return $this->model;
	}

	/**
	 * Returns the action to be executed for the given action in the URL.
	 *
	 * @param array Action asked in the URL
	 * @return string Executable action
	 */
	public function getExecutableAction($action) {
		if ($this->getAdminContext()) {
			$actions_key = 'admin';
			$alias_prefix = 'admin-';
			$default = 'default_admin';
		} else {
			$actions_key = 'actions';
			$alias_prefix = '';
			$default = 'default';
		}

		// $action exists ? Otherwise, check alias and finally, use default action if exists?
		if (!empty($action) && !isset($this->manifest[$actions_key][$action])) {
			// Check alias
			if (isset($this->manifest['alias'][$alias_prefix.$action])) {
				$action = $this->manifest['alias'][$alias_prefix.$action];
			} else {
				$action = '';
			}
		}

		if (empty($action) && isset($this->manifest[$default])) {
			$action = $this->manifest[$default];
		}

		return $action;
	}

	 /**
	 * Returns the real executed action
	 *
	 * @return string real executed action name
	 */
	public function getExecutedAction() {
		return $this->action;
	}

	/**
	 * Loads the manifest file of a given application
	 *
	 * @param string $app_name name of the application owning the manifest
	 * @return array manifest asked
	 */
	public static function loadManifest($app_name) {
		$manifest = WConfig::get('manifest.'.$app_name);
		if (is_null($manifest)) {
			$manifest_file = APPS_DIR.$app_name.DS.'manifest.php';
			if (!file_exists($manifest_file)) {
				return null;
			}

			// Checks cache directory
			if (!is_dir(CACHE_DIR.'manifests')) {
				@mkdir(CACHE_DIR.'manifests', 0777);
			}

			// Is there a manifest parsed in cache?
			$cache_file = CACHE_DIR.'manifests'.DS.$app_name.'.php';
			if (file_exists($cache_file) && @filemtime($cache_file) > @filemtime($manifest_file)) {
				include $cache_file;
			}

			if (!isset($manifest)) { // cache failed
				$manifest = $this->parseManifest($manifest_file);

				if (is_writable(CACHE_DIR.'manifests')) {
					// Opening
					if (!($handler = fopen($cache_file, 'w'))) {
						WNote::error('cache_manifest_failed', WLang::get('error_cache_manifest_failed', $cache_file), 'debug');
					}

					// Writing
					fwrite($handler, "<?php\n\n\$manifest = ".var_export($manifest, true).";\n\n?>");
					fclose($handler);
				}
			}

			WConfig::set('manifest.'.$app_name, $manifest);
		}

		return $manifest;
	}

	/**
	 * Retrieves the manifest of the application running
	 *
	 * @return array manifest
	 */
	public function getManifest() {
		return $this->manifest;
	}

	/**
	 * Parses a manifest file.
	 *
	 * @param string $manifest_href Href of the manifest file desired
	 * @return array manifest parsed into an array representation
	 */
	private static function parseManifest($manifest_href) {
		if (!file_exists($manifest_href)) {
			return null;
		}

		$manifest_string = file_get_contents($manifest_href);
		$manifest_string = trim(preg_replace('#<\?php.+\?>#U', '', $manifest_string));

		$xml = simplexml_load_string($manifest_string);
		$manifest = array();

		// Nodes to look for
		$nodes = array('name', 'version', 'date', 'icone', 'action', 'admin', 'permission');
		foreach ($nodes as $node) {
			switch ($node) {
				case 'action':
					$manifest['actions'] = array();

					if (property_exists($xml, 'action')) {
						foreach ($xml->action as $action) {
							$key = strtolower((string) $action);

							if (!empty($key)) {
								$attributes = $action->attributes();

								if (!isset($manifest['actions'][$key])) {
									$manifest['actions'][$key] = array(
										'description' => isset($attributes['description']) ? (string) $attributes['description'] : $key,
										'requires'    => isset($attributes['requires']) ? array_map('trim', explode(',', $attributes['requires'])) : array()
									);
								}

								if (isset($attributes['default']) && empty($manifest['default'])) {
									$manifest['default'] = $key;
								}

								if (isset($attributes['alias']) && !empty($attributes['alias'])) {
									$alias = explode(',', $attributes['alias']);
									foreach ($alias as $al) {
										$al = strtolower(trim($al));
										if (!empty($al)) {
											$manifest['alias'][$al] = $key;
										}
									}
								}
							}
						}
					}
					break;

				case 'admin':
					$manifest['admin'] = array();
					$manifest['admin_has_submenu'] = false;

					if (property_exists($xml, 'admin') && property_exists($xml->admin, 'action')) {
						foreach ($xml->admin->action as $action) {
							$key = strtolower((string) $action);

							if (!empty($key)) {
								$attributes = $action->attributes();

								if (!isset($manifest['admin'][$key])) {
									$menuState = isset($attributes['menu']) ? (string) $attributes['menu'] == 'true' : true;
									$manifest['admin_has_submenu'] = $manifest['admin_has_submenu'] || $menuState;

									$manifest['admin'][$key] = array(
										'description' => isset($attributes['description']) ? (string) $attributes['description'] : $key,
										'menu'        => isset($attributes['menu']) ? (string) $attributes['menu'] == 'true' : true,
										'requires'    => isset($attributes['requires']) ? array_map('trim', explode(',', $attributes['requires'])) : array()
									);
								}

								if (isset($attributes['default']) && empty($manifest['default_admin'])) {
									$manifest['default_admin'] = $key;
								}

								if (isset($attributes['alias']) && !empty($attributes['alias'])) {
									$alias = explode(',', $attributes['alias']);
									foreach ($alias as $al) {
										$al = strtolower(trim($al));
										if (!empty($al)) {
											$manifest['alias']['admin-'.$al] = $key;
										}
									}
								}
							}
						}
					}
					break;

				case 'permission':
					$manifest['permissions'] = !empty($manifest['admin']) ? array('admin') : array();
					if (property_exists($xml, 'permission')) {
						foreach ($xml->permission as $permission) {
							$attributes = $permission->attributes();
							$key = (string) $attributes['name'];

							if (!empty($key)) {
								$manifest['permissions'][] = $key;
							}
						}
					}
					break;

				case 'name':
					$manifest['name'] = property_exists($xml, 'name') ? (string) $xml->name : basename(dirname($manifest_href));
					break;

				default:
					$manifest[$node] = property_exists($xml, $node) ? (string) $xml->$node : '';
					break;
			}
		}

		return $manifest;
	}

	/**
	 * Checks whether the user has access to an application, or a precise action of an application
	 * hasAccess('news') = does the user have access to news app?
	 * hasAccess('news/detail') = access to action detail in news app?
	 *
	 * @param string $url
	 * @return bool|array
	 */
	public static function hasAccess($url) {
		$route = WRoute::parseURL($url);

		if (empty($route['app'])) {
			return false;
		}

		// Check manifest
		$manifest = self::loadManifest($route['app']);
		if (is_null($manifest)) {
			return false;
		}

		if ($route['admin']) { // Admin mode ON
			if (empty($_SESSION['access'])) {
				return false;
			}

			if ($_SESSION['access'] == 'all') {
				return true;
			}

			if (isset($_SESSION['access'][$route['app']]) && is_array($_SESSION['access'][$route['app']]) && in_array('admin', $_SESSION['access'][$route['app']])) {
				if (empty($route['action'])) {
					// Asking for application access
					return true;
				} else if (isset($manifest['admin'][$route['action']])) {
					// Check that the current user possesses all the required permissions
					foreach ($manifest['admin'][$route['action']]['requires'] as $req) {
						switch ($req) {
							case 'connected':
							case 'admin':
								break;

							default:
								return in_array($req, $_SESSION['access'][$route['app']]);
						}
					}

					return true;
				}
			}
		} else { // Admin mode OFF
			// Assign default value for $action
			$action = $route['action'];

			if (empty($action)) {
				if (!empty($manifest['default'])) {
					$action = $manifest['default'];
				} else {
					return false;
				}
			}

			// Supreme Admin -> access granted
			if (isset($_SESSION['access']) && $_SESSION['access'] == 'all') {
				if (isset($manifest['actions'][$action]) && in_array('not-connected', $manifest['actions'][$action]['requires'])) {
					return WNote::error('app_logout_required', WLang::get('error_app_logout_required', $action, $route['app']));
				}

				return true;
			}

			if (isset($manifest['actions'][$action])) {
				// Check permissions
				foreach ($manifest['actions'][$action]['requires'] as $req) {
					switch ($req) {
						case 'not-connected':
							if (WSession::isConnected()) {
								return WNote::error('app_logout_required', WLang::get('error_app_logout_required', $action, $route['app']));
							}
							break;

						case 'connected':
							if (!WSession::isConnected()) {
								return false;
							}
							break;

						default:
							if (!WSession::isConnected() || !isset($_SESSION['access'][$route['app']]) || !in_array($req, $_SESSION['access'][$route['app']])) {
								return false;
							}
							break;
					}
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the list of apps according to the user's access.
	 */
	public static function getApps($admin = null) {
		$apps_manifests = array();

		$apps_list = WRetriever::getAppsList($admin);

		foreach ($apps_list as $app) {
			if (self::hasAccess(($admin ? 'admin/' : '').$app)) {
				$apps_manifests[$app] = self::loadManifest($app);
			}
		}

		return $apps_manifests;
	}

	/**
	 * Set a new header for the response
	 * Will be assigned in WResponse::render()
	 *
	 * @param string $name Header's name
	 * @param string $value
	 */
	public function setHeader($name, $value) {
		$this->headers[strtolower($name)] = $value;
	}

	/**
	 * Get the headers for this app
	 *
	 * @return array
	 */
	public function getHeaders() {
		$headers = $this->headers;
		$this->headers = array();
		return $headers;
	}
}

?>
