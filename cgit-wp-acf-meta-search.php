<?php

/*

Plugin Name: Castlegate IT WP ACF Custom Field Search
Plugin URI: http://github.com/castlegateit/cgit-wp-dradis
Description: Allows searching of custom fields and meta values.
Version: 2.0
Author: Castlegate IT
Author URI: http://www.castlegateit.co.uk/
License: MIT

*/

use Cgit\AcfMetaSearch;

require __DIR__ . '/src/autoload.php';

// Load plugin
add_action('init', function () {
    AcfMetaSearch::getInstance();
});
