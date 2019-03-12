<?php
/**
 * Plugin Name: woprsk/WpDebug
 * Description: Simply write to the debug log.
 */

namespace woprsk {

    class WpDebug
    {
        private static $instance;
        private static $enabled = false;
        private static $includeStackTrace = false;
        private static $logPrefix = "WP DEBUG";
        private static $actionLogPrefix = "";
        private static $defaultErrorHandler = null;

        /**
         * Return the instance.
         * @return object woprsk\WpDebug instance
         */
        public static function instance()
        {
            if (is_null(self::$instance)) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        public function __construct()
        {
            self::$enabled = (defined('WP_DEBUG') && WP_DEBUG === true && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true);

            if (self::$enabled === true) {
                self::enable();
            }
        }

        private static function enable()
        {
            self::setActionLogPrefix();
            self::setErrorHandler();
            self::$includeStackTrace = (defined('WP_DEBUG_STACKTRACE') && WP_DEBUG_STACKTRACE === true);

            if (defined('WP_DEBUG_LOG_LOCATION') && is_string(WP_DEBUG_LOG_LOCATION)) {
                self::setLogDirectory(WP_DEBUG_LOG_LOCATION);
            }

            if (defined('WP_DEBUG_CLEAR_LOG') && WP_DEBUG_CLEAR_LOG === true) {
                self::clearDebugLog();
            }

            if (defined('SAVEQUERIES') && SAVEQUERIES === true) {
                self::saveQueries();
            }

            if (defined('WP_DEBUG_SCRATCH') && WP_DEBUG_SCRATCH === true) {
                self::loadScratch();
            }
        }

        /**
         * output to debug log.
         * @param  string|array|object  $log something to send to the log.
         * @return void       just writes to the debug log.
         */
        public static function log()
        {
            if (self::$enabled === true) {
                $args = func_get_args();
                if (count($args) > 0) {
                    foreach ($args as $arg) {
                        error_log(sprintf("%s%s: %s", self::$logPrefix, self::$actionLogPrefix, print_r($arg, true)));
                    }
                }
            }
        }

        public static function errorHandler($errno, $errstr, $errfile, $errline)
        {
            if (!($errno & error_reporting())) {
                return true;
            }

            switch ($errno) {
                case E_NOTICE:
                case E_USER_NOTICE:
                    $errors = "NOTICE";
                    break;
                case E_WARNING:
                case E_USER_WARNING:
                    $errors = "WARNING";
                    break;
                case E_ERROR:
                case E_USER_ERROR:
                    $errors = "ERROR";
                    break;
                default:
                    $errors = "ERROR";
                    break;
            }

            error_log(sprintf("PHP %s%s: %s in %s on line %d", $errors, self::$actionLogPrefix, $errstr, $errfile, $errline));

            if (self::$includeStackTrace) {
                error_log(sprintf("PHP STACKTRACE: %s", print_r(debug_backtrace(2), true)));
            }

            return self::$defaultErrorHandler;
        }

        private static function setErrorHandler()
        {
            self::$defaultErrorHandler = set_error_handler([__class__, "errorHandler"], error_reporting());
        }

        private static function setActionLogPrefix()
        {
            self::$actionLogPrefix = wp_doing_ajax() ? " (WP_AJAX)" : (wp_doing_cron() ? " (WP_CRON)" : "");
        }

        private static function setLogDirectory($logFileLocation = "")
        {
            $fileExists = true;

            if (empty($logFileLocation) || !is_string($logFileLocation)) {
                return false;
            }

            if (substr_compare($logFileLocation, ".log", strlen($logFileLocation) - strlen(".log"), strlen(".log")) !== 0) {
                $logFileLocation = \trailingslashit($logFileLocation) . "debug.log";
            }

            if (!file_exists($logFileLocation)) {
                $fileExists = false;
                $f = @fopen($logFileLocation, 'w');
                if ($f !== false) {
                    fclose($f);
                    $fileExists = true;
                }
            }

            if (is_writeable($logFileLocation)) {
                $set = ini_set("error_log", $logFileLocation);
                return $logFileLocation;
            }
        }

        public static function clearDebugLog()
        {
            if (!wp_doing_ajax() && !wp_doing_cron()) {
                $debugLogPath = ini_get('error_log');
                $f = @fopen($debugLogPath, "r+");
                if ($f !== false) {
                    ftruncate($f, 0);
                    fclose($f);
                }
            }
        }

        private static function loadScratch()
        {
            if (file_exists(dirname(ABSPATH) . '/../scratch.debug.php')) {
                include_once(dirname(ABSPATH) . '/../scratch.debug.php');
            }
        }

        public static function saveQueries()
        {
            add_action('shutdown', [__class__, 'saveQueriesLog']);
        }

        public static function saveQueriesLog()
        {
            global $wpdb;

            $saveQueries = array_reduce($wpdb->queries, function ($data, $query) {
                $data['count']++;
                $data['time'] += $query[1];
                return $data;
            }, ['count' => 0, 'time' => 0]);

            if (defined('WP_DEBUG_QUERIES') && WP_DEBUG_QUERIES === true) {
                $saveQueries['queries'] = $wpdb->queries;
            }

            self::log("SAVEQUERIES => ", apply_filters("woprsk_wp_debug_savequeries", $saveQueries));
        }
    }
}

namespace {
    if (!function_exists('debug')) {
        /**
         * a global debug function
         * @return void
         */
        function debug()
        {
            call_user_func_array([\woprsk\WpDebug::instance(), 'log'], func_get_args());
        }
    }

    \woprsk\WpDebug::instance();
}
