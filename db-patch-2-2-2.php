<?php

namespace TaxJarExpansion;
defined( 'ABSPATH' ) || exit;

add_action('admin_init', function() {
    // Check if the current page is wp-admin/admin.php?page=wc-settings&tab=taxjar-integration&patch=2-2-1
    if( ! isset($_GET['page']) || $_GET['page'] !== 'wc-settings' || ! isset($_GET['tab']) || $_GET['tab'] !== 'taxjar-integration' || ! isset($_GET['patch']) || $_GET['patch'] !== '2-2-2'){
        return;
    }
    UserProfile::get_instance()->patch_2_2_2();
});