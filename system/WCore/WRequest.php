<?php
/**
 * WRequest.php
 */

defined('IN_WITY') or die('Access denied');

/**
 * WRequest manages all input variables.
 *
 * @package System\WCore
 * @author Johan Dufau <johan.dufau@creatiwity.net>
 * @version 0.5.0-dev-29-12-2011
 */
class WRequest {
	 /**
	 * @var array Contains all checked variables to avoid infinite loop
	 */
	private static $checked = array();

	/**
	 * @var bool Variable to lock all read/write actions on the input values. Default values will be sent.
	 */
	private static $lock = false;

	/**
	 * Returns the values of all variables with name in $names sent by $hash method
	 *
	 * You can use the following hashes:
	 * - "GET"
	 * - "POST"
	 * - "FILES"
	 * - "COOKIE"
	 * - "REQUEST" (default)
	 *
	 * The following syntax is allowed:
	 * <code>list($v1, ...) = WRequest::get(array('v1', 'v2'));</code>
	 *
	 * @param string|array  $names      variable names
	 * @param mixed         $default    optional default values
	 * @param string        $hash       name of the method used to send
	 * @return mixed    array of values or the value
	 */
	public static function get($names, $default = null, $hash = 'REQUEST') {
		// Data hash
		switch (strtoupper($hash)) {
			case 'GET':
				$data = &$_GET;
				break;
			case 'POST':
				$data = &$_POST;
				break;
			case 'FILES':
				$data = &$_FILES;
				break;
			case 'COOKIE':
				$data = &$_COOKIE;
				break;
			default:
				$data = &$_REQUEST;
				$hash = 'REQUEST';
				break;
		}

		if (is_array($names)) {
			// Going through the asked values in order to returns the array
			$result = array();
			foreach ($names as $name) {
				$value = self::getValue($data, $name, isset($default[$name]) ? $default[$name] : null, $hash);
				$result[] = $value;
				$result[$name] = $value;
			}

			return $result;
		} else {
			return self::getValue($data, $names, $default, $hash);
		}
	}

	/**
	 * Returns an associative array of values in which keys are the $names
	 *
	 * @see WRequest::get()
	 * @param array  $names   variable names
	 * @param mixed  $default optional default values
	 * @param string $hash    name of the method used to send
	 * @return array array of values in which keys are the $names
	 */
	public static function getAssoc(array $names, $default = null, $hash = 'REQUEST') {
		// Data hash
		switch (strtoupper($hash)) {
			case 'GET':
				$data = &$_GET;
				break;
			case 'POST':
				$data = &$_POST;
				break;
			case 'FILES':
				$data = &$_FILES;
				break;
			case 'COOKIE':
				$data = &$_COOKIE;
				break;
			default:
				$data = &$_REQUEST;
				$hash = 'REQUEST';
				break;
		}

		// Going through the asked values in order to returns the array
		$result = array();
		foreach ($names as $name) {
			$value = self::getValue($data, $name, isset($default[$name]) ? $default[$name] : null, $hash);
			$result[$name] = $value;
		}

		return $result;
	}

	/**
	 * Returns the checked value associated to $name
	 *
	 * @param &array $data       request array
	 * @param string $name       variable name
	 * @param string $default    optional default value
	 * @param string $hash       name of the method used to send
	 * @return mixed the checked value associated to $name or null if not exists
	 */
	public static function getValue(&$data, $name, $default, $hash) {
		// Stop read action
		if (self::$lock) {
			return $default;
		}

		if (isset(self::$checked[$hash.$name])) {
			// Directly get the verifed variable in data
			return $data[$name];
		} else {
			if (isset($data[$name]) && !is_null($data[$name])) {
				// Filter the variable value
				$data[$name] = self::filter($data[$name]);
			} else if (!is_null($default)) {
				// Use default
				$data[$name] = self::filter($default);
			}

			// Variable is verified
			if (isset($data[$name])) {
				self::$checked[$hash.$name] = true;
				return $data[$name];
			} else {
				return null;
			}
		}
	}

	/**
	 * Sets a request value
	 *
	 * @param string    $name       variable name
	 * @param mixed     $value      the value that will be set
	 * @param string    $hash       name of the method used to initially send
	 * @param boolean   $overwrite  optional overwrite command, true by default
	 * @return mixed previous value, may be null
	 */
	public static function set($name, $value, $hash = 'REQUEST', $overwrite = true) {
		// Stop write action
		if (self::$lock) {
			return null;
		}

		// Check if overwriting is allowed
		if (!$overwrite && array_key_exists($name, $_REQUEST)) {
			return $_REQUEST[$name];
		}

		// Stores previous value
		$previous = array_key_exists($name, $_REQUEST) ? $_REQUEST[$name] : null;

		switch (strtoupper($hash)) {
			case 'GET':
				$_GET[$name] = $value;
				$_REQUEST[$name] = $value;
				break;
			case 'POST':
				$_POST[$name] = $value;
				$_REQUEST[$name] = $value;
				break;
			case 'COOKIE':
				$_COOKIE[$name] = $value;
				$_REQUEST[$name] = $value;
				break;
			case 'FILES':
				$_FILES[$name] = $value;
				break;
			default:
				$_REQUEST[$name] = $value;
				break;
		}

		self::$checked[$hash.$name] = true;

		return $previous;
	}

	/**
	 * Returns the filtered variable after a tiny security check
	 *
	 * @param mixed $variable variable that we want to filter
	 * @return mixed the filtered variable
	 */
	public static function filter($variable) {
		if (is_array($variable)) {
			foreach ($variable as $key => $val) {
				$variable[$key] = self::filter($val);
			}
		} else {
			// Prevent XSS abuse
			$variable = preg_replace_callback('#</?([a-z]+)(\s.*)?/?>#', function($matches) {
				// Allowed tags
				if (in_array($matches[1], array(
					'b', 'strong', 'small', 'i', 'em', 'u', 's', 'sub', 'sup', 'a', 'img', 'br', 
					'font', 'span', 'blockquote', 'q', 'abbr', 'address', 'code', 
					'audio', 'video', 'source', 'iframe', 
					'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 
					'ul', 'ol', 'li', 'dl', 'dt', 'dd', 
					'div', 'p', 'var', 
					'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'colgroup', 'col', 
					'section', 'article', 'aside'))) {
					return $matches[0];
				} else if (in_array($matches[1], array('script', 'link'))) {
					return '';
				} else {
					return htmlentities($matches[0]);
				}
			}, $variable);
		}

		return $variable;
	}

	/**
	 * Stops all read/write actions on the Request variables.
	 */
	public static function lock() {
		self::$lock = true;
	}

	/**
	 * Allows all read/write actions on the Request variables.
	 */
	public static function unlock() {
		self::$lock = false;
	}

	/**
	 * Tells the user if data is available.
	 *
	 * @return bool true if data available
	 */
	public static function hasData() {
		return !empty($_REQUEST) && !in_array(null, $_REQUEST, true) && !self::$lock;
	}
	
	/**
	 * Retrieves the HTTP Method used by the client.
	 * 
	 * @return string Either GET|POST|PUT|DEL...
	 */
	public static function getMethod() {
		return $_SERVER['REQUEST_METHOD'];
	}
}

?>
