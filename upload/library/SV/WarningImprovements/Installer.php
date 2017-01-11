<?php

class SV_WarningImprovements_Installer
{
    const AddonNameSpace = 'SV_WarningImprovements';

    public static function install($existingAddOn, $addOnData)
    {
        $version = isset($existingAddOn['version_id']) ? $existingAddOn['version_id'] : 0;

        $db = XenForo_Application::getDb();

        $addonsToUninstall = array('SV_AlertOnWarning' => array(),
                                   'SVViewOwnWarnings' => array());
        SV_Utils_Install::removeOldAddons($addonsToUninstall);


        $db->query("
            CREATE TABLE IF NOT EXISTS xf_sv_warning_default
            (
                `warning_default_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `threshold_points` SMALLINT NOT NULL DEFAULT '0',
                `expiry_type` ENUM('never','days','weeks','months','years') NOT NULL,
                `expiry_extension` SMALLINT UNSIGNED NOT NULL,
                `active` tinyint(3) unsigned NOT NULL DEFAULT '1',
                PRIMARY KEY (`warning_default_id`),
                KEY (`threshold_points`, `active`)
            ) ENGINE=InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci
        ");

        $db->query(
            'CREATE TABLE IF NOT EXISTS xf_sv_warning_category (
                warning_category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                parent_warning_category_id INT UNSIGNED NOT NULL DEFAULT 0,
                display_order INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (warning_category_id)
            ) ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci'
        );
        SV_Utils_Install::addColumn(
            'xf_sv_warning_category',
            'allowed_user_group_ids',
            "VARBINARY(255) NOT NULL DEFAULT '2'"
        );

        SV_Utils_Install::addColumn(
            'xf_warning_definition',
            'sv_warning_category_id',
            'INT UNSIGNED NOT NULL DEFAULT 0'
        );
        SV_Utils_Install::addColumn(
            'xf_warning_definition',
            'sv_display_order',
            'INT UNSIGNED NOT NULL DEFAULT 0'
        );

        SV_Utils_Install::addColumn(
            'xf_warning_action',
            'sv_warning_category_id',
            'INT UNSIGNED NOT NULL DEFAULT 0'
        );

        if ($version == 0)
        {
            // insert the defaults for the custom warning. This can't be normally inserted so fiddle with the sql_mode
            $db->query("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO'");
            $db->query("insert ignore into xf_warning_definition
                    (warning_definition_id,points_default,expiry_type,expiry_default,extra_user_group_ids,is_editable)
                values
                    (0,1, 'months',1,'',1);
            ");
            $db->query("SET SESSION sql_mode='STRICT_ALL_TABLES'");
        }

        if ($version < 1040000)
        {
            // create default warning category
            $categoryDw = XenForo_DataWriter::create(
                'SV_WarningImprovements_DataWriter_WarningCategory'
            );
            $categoryDw->bulkSet(array(
                'parent_warning_category_id' => 0,
                'display_order'              => 0
            ));
            $categoryDw->setExtraData(
                SV_WarningImprovements_DataWriter_WarningCategory::DATA_TITLE,
                'Warnings'
            );
            $categoryDw->save();

            $defaultCategory = $categoryDw->getMergedData();

            // set all warning definitions to be in default warning category
            $db->update('xf_warning_definition', array(
                'sv_warning_category_id' => $defaultCategory['warning_category_id'],
                'sv_display_order'       => 0
            ));
        }

        $db->query("
            INSERT IGNORE INTO xf_content_type
                (content_type, addon_id, fields)
            VALUES
                ('".SV_WarningImprovements_AlertHandler_Warning::ContentType."', '".self::AddonNameSpace."', '')
        ");

        $db->query("
            INSERT IGNORE INTO xf_content_type_field
                (content_type, field_name, field_value)
            VALUES
                ('".SV_WarningImprovements_AlertHandler_Warning::ContentType."', 'alert_handler_class', '".self::AddonNameSpace."_AlertHandler_Warning')
        ");

        XenForo_Model::create('XenForo_Model_ContentType')->rebuildContentTypeCache();


        SV_Utils_Install::addColumn("xf_warning_action", "sv_post_node_id", "INT NOT NULL DEFAULT 0");
        SV_Utils_Install::addColumn("xf_warning_action", "sv_post_thread_id", "INT NOT NULL DEFAULT 0");
        SV_Utils_Install::addColumn("xf_warning_action", "sv_post_as_user_id", "INT");
/*
        SV_Utils_Install::addColumn("xf_warning", "sv_PauseExpireOnSuspended", "TINYINT NOT NULL DEFAULT 1");
        SV_Utils_Install::addColumn("xf_warning_definition", "sv_PauseExpireOnSuspended", "TINYINT NOT NULL DEFAULT 1");
        SV_Utils_Install::addColumn("xf_user_group", "sv_suspends", "TINYINT NOT NULL DEFAULT 0");


        if ($version < 1)
        {
            $db->query("update xf_user_group set sv_suspends = 1 where title like '%suspended%' or title like '%XF Ban%' or title like '%XF Ban%' or title = 'Banned';");
        }
*/

        return true;
    }

    public static function uninstall()
    {
        $db = XenForo_Application::get('db');

        $db->query("
            DELETE FROM xf_content_type_field
            WHERE xf_content_type_field.field_value = '".self::AddonNameSpace."_AlertHandler_Warning'
        ");

        $db->query("
            DELETE FROM xf_content_type
            WHERE xf_content_type.addon_id = '".self::AddonNameSpace."'
        ");

        $db->query("
            DELETE FROM xf_warning_definition
            WHERE warning_definition_id = 0
        ");

        $db->query("
            DROP TABLE IF EXISTS `xf_sv_warning_default`
        ");

        $db->query('DROP TABLE IF EXISTS xf_sv_warning_category');

        SV_Utils_Install::dropColumn(
            'xf_warning_definition',
            'sv_warning_category_id'
        );
        SV_Utils_Install::dropColumn(
            'xf_warning_definition',
            'sv_display_order'
        );

        $db->query("
            DELETE FROM xf_permission_entry
            WHERE permission_group_id = 'general' and permission_id = 'sv_editWarningActions'
        ");
        $db->query("
            DELETE FROM xf_permission_entry
            WHERE permission_group_id = 'general' and permission_id = 'sv_showAllWarningActions'
        ");
        $db->query("
            DELETE FROM xf_permission_entry
            WHERE permission_group_id = 'general' and permission_id = 'sv_viewWarningActions'
        ");
        $db->query("
            DELETE FROM xf_permission_entry
            WHERE permission_group_id = 'general' and permission_id = 'viewWarning_issuer'
        ");

        XenForo_Model::create('XenForo_Model_ContentType')->rebuildContentTypeCache();

        SV_Utils_Install::dropColumn("xf_warning_action", "sv_post_node_id");
        SV_Utils_Install::dropColumn("xf_warning_action", "sv_post_thread_id");
        SV_Utils_Install::dropColumn("xf_warning_action", "sv_post_as_user_id");
/*
        SV_Utils_Install::dropColumn("xf_warning", "sv_PauseExpireOnSuspended");
        SV_Utils_Install::dropColumn("xf_warning_definition", "sv_PauseExpireOnSuspended");
        SV_Utils_Install::dropColumn("xf_user_group", "sv_suspends");
*/


        return true;
    }
}
