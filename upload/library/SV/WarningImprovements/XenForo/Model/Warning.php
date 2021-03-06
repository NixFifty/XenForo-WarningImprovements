<?php
class SV_WarningImprovements_XenForo_Model_Warning extends XFCP_SV_WarningImprovements_XenForo_Model_Warning
{
    public function getEffectiveNextExpiry($userId, $checkBannedStatus)
    {
        $db = $this->_getDb();

        $nextWarningExpiry = $db->fetchOne('
            select min(expiry_date)
            from xf_warning
            WHERE user_id = ? and expiry_date > 0 AND is_expired = 0
        ', $userId);
        if (empty($nextWarningExpiry))
        {
            $nextWarningExpiry = null;
        }

        $warningActionExpiry = $db->fetchOne('
            select min(expiry_date)
            from xf_user_change_temp
            WHERE user_id = ? and expiry_date > 0 AND change_key like \'warning_action_%\';
        ', $userId);
        if (empty($warningActionExpiry))
        {
            $warningActionExpiry = null;
        }

        $banExpiry = null;
        if ($checkBannedStatus)
        {
            $banExpiry = $db->fetchOne('
                SELECT min(end_date)
                FROM xf_user_ban
                WHERE user_id = ? and end_date > 0
            ', $userId);
            if (empty($banExpiry))
            {
                $banExpiry = null;
            }
        }

        $effectiveNextExpiry = null;
        if ($nextWarningExpiry)
        {
            $effectiveNextExpiry = $nextWarningExpiry;
        }
        if ($warningActionExpiry && $warningActionExpiry > $effectiveNextExpiry)
        {
            $effectiveNextExpiry = $warningActionExpiry;
        }
        if ($banExpiry && $banExpiry > $effectiveNextExpiry)
        {
            $effectiveNextExpiry = $banExpiry;
        }

        return $effectiveNextExpiry;
    }

    public function updatePendingExpiryFor($userId, $checkBannedStatus)
    {
        $db = $this->_getDb();

        XenForo_Db::beginTransaction($db);

        $effectiveNextExpiry = $this->getEffectiveNextExpiry($userId, $checkBannedStatus);

        $db->query('
            update xf_user_option
            set sv_pending_warning_expiry = ?
            where user_id = ?
        ', array($effectiveNextExpiry, $userId));

        XenForo_Db::commit($db);

        return $effectiveNextExpiry;
    }

    public function processExpiredWarningsForUser($userId, $checkBannedStatus)
    {
        if (empty($userId))
        {
            return false;
        }

        $db = $this->_getDb();
        $warnings = $db->fetchAll('
            SELECT *
            FROM xf_warning
            WHERE user_id = ? AND expiry_date < ? AND expiry_date > 0 AND is_expired = 0
        ', array($userId, XenForo_Application::$time));
        $expired = !empty($warnings);
        foreach ($warnings AS $warning)
        {
            $dw = XenForo_DataWriter::create('XenForo_DataWriter_Warning', XenForo_DataWriter::ERROR_SILENT);
            $dw->setExistingData($warning, true);
            $dw->set('is_expired', 1);
            $dw->save();
        }

        $warningActionModel = $this->_getWarningActionModel();
        $warningActions = $warningActionModel->getWarningActionsByUser($userId, true, true, true);
        $expired = $expired || !empty($warningActions);
        foreach ($warningActions AS $warningAction)
        {
            $warningActionModel->expireWarningAction($warningAction);
        }

        if ($checkBannedStatus)
        {
            $bans = $db->fetchAll('
                SELECT *
                FROM xf_user_ban
                WHERE user_id = ? AND end_date > 0 AND end_date <= ?
            ', array($userId, XenForo_Application::$time));
            $expired = $expired || !empty($bans);
            foreach ($bans AS $ban)
            {
                $dw = XenForo_DataWriter::create('XenForo_DataWriter_UserBan');
                $dw->setExistingData($ban, true);
                $dw->delete();
            }
        }

        return $expired;
    }

    protected $userWarningCountCache = array();

    protected function getCachedWarningsForUser($userId, $days, $includeExpired)
    {
        if (isset($this->userWarningCountCache[$userId][$days]))
        {
            return $this->userWarningCountCache[$userId][$days];
        }

        $whereSQL = '';
        $args = array($userId, XenForo_Application::$time - 86400 * $days);
        if (!$includeExpired)
        {
            $whereSQL .= ' and is_expired = 0 ';
        }

        $db = $this->_getDb();
        $this->userWarningCountCache[$userId][$days] = $db->fetchRow('
            select sum(points) as total, count(points) as `count`
            from xf_warning
            where user_id = ? and warning_date > ? ' . $whereSQL . '
            group by user_id
        ', $args);

        return $this->userWarningCountCache[$userId][$days];
    }

    public function getWarningPointsInLastXDays($userId, $days, $includeExpired = false)
    {
        $value = $this->getCachedWarningsForUser($userId, $days, $includeExpired);
        if (isset($value['total']))
        {
            return $value['total'];
        }

        return 0;
    }

    public function getWarningCountsInLastXDays($userId, $days, $includeExpired = false)
    {
        $value = $this->getCachedWarningsForUser($userId, $days, $includeExpired);
        if (isset($value['count']))
        {
            return $value['count'];
        }

        return 0;
    }

    /**
     * Cached warning categories array.
     *
     * @var array
     */
    protected $_warningCategories;

    /**
     * Cached user warning points array.
     *
     * @var array
     */
    protected $_userWarningPoints;

    public function isWarningCategory($warningCategory)
    {
        return (
            !(empty($warningCategory)) and
            is_array($warningCategory) and
            array_key_exists('warning_category_id', $warningCategory) and
            array_key_exists('parent_warning_category_id', $warningCategory)
        );
    }

    public function isWarningDefinition($warningDefinition)
    {
        return (
            !(empty($warningDefinition)) and
            is_array($warningDefinition) and
            array_key_exists('warning_definition_id', $warningDefinition) and
            array_key_exists('sv_warning_category_id', $warningDefinition)
        );
    }

    public function isWarningAction($warningAction)
    {
        return (
            !(empty($warningAction)) &&
            is_array($warningAction) &&
            array_key_exists('warning_action_id', $warningAction) &&
            array_key_exists('sv_warning_category_id', $warningAction)
        );
    }

    public function isWarningItemsArray($warningItems)
    {
        if (is_array($warningItems))
        {
            if (count($warningItems) == 0)
            {
                return true;
            }
            else
            {
                return (
                    $this->isWarningCategory(reset($warningItems)) or
                    $this->isWarningDefinition(reset($warningItems)) or
                    $this->isWarningAction(reset($warningItems))
                );
            }
        }
        else
        {
            return false;
        }
    }

    public function getWarningCategoryById($warningCategoryId)
    {
        return $this->_getDb()->fetchRow(
            'SELECT *
                FROM xf_sv_warning_category
                WHERE warning_category_id = ?',
            $warningCategoryId
        );
    }

    public function getWarningCategoryTitlePhraseName($warningCategoryId)
    {
        return 'sv_warning_category_'.$warningCategoryId.'_title';
    }

    public function getWarningCategories($fromCache = false)
    {
        if (!$fromCache || empty($this->_warningCategories))
        {
            $this->_warningCategories = $this->fetchAllKeyed(
                'SELECT *
                    FROM xf_sv_warning_category
                    ORDER BY parent_warning_category_id, display_order',
                'warning_category_id'
            );
        }

        return $this->_warningCategories;
    }

    public function getWarningCategoriesByParentId(
        $parentWarningCategoryId,
        array $warningCategories = null
    ) {
        if ($warningCategories !== null)
        {
            $children = array();

            foreach ($warningCategories as $warningCategoryId => $warningCategory)
            {
                if ($warningCategory['parent_warning_category_id'] === $parentWarningCategoryId)
                {
                    $children[$warningCategoryId] = $warningCategory;
                }
            }

            return $children;
        }

        return $this->fetchAllKeyed(
            'SELECT *
                FROM xf_sv_warning_category
                WHERE parent_warning_category_id = ?
                ORDER BY display_order',
            'warning_category_id',
            $parentWarningCategoryId
        );
    }

    /**
     * @param array      $warningItem
     * @param array|null $warningCategories
     * @return array
     */
    public function getRootWarningCategoryByWarningItem(
        array $warningItem,
        array $warningCategories = null
    ) {
        if ($warningCategories === null)
        {
            $warningCategories = $this->prepareWarningCategories(
                $this->getWarningCategories(true)
            );
        }

        if ($this->isWarningCategory($warningItem))
        {
            $parentWarningCategoryId = $warningItem['parent_warning_category_id'];

            if ($parentWarningCategoryId === 0)
            {
                return $warningItem;
            }
        }
        elseif ($this->isWarningDefinition($warningItem))
        {
            $parentWarningCategoryId = $warningItem['sv_warning_category_id'];
        }
        else
        {
            $parentWarningCategoryId = null;
        }

        if (isset($warningCategories[$parentWarningCategoryId]))
        {
            $parentWarningCategory = $warningCategories[$parentWarningCategoryId];
        }
        else
        {
            $parentWarningCategory = reset($warningCategories);
        }

        return $this->getRootWarningCategoryByWarningItem($parentWarningCategory);
    }

    public function getParentWarningCategoriesByWarningItem(
        $warningItem,
        $warningCategories = null,
        $parentWarningCategories = array()
    )
    {
        if ($warningCategories === null)
        {
            $warningCategories = $this->prepareWarningCategories(
                $this->getWarningCategories(true)
            );
        }

        $parentWarningCategoryId = 0;

        if ($this->isWarningCategory($warningItem))
        {
            $parentWarningCategoryId = $warningItem['parent_warning_category_id'];
        }
        elseif (
            $this->isWarningDefinition($warningItem) ||
            $this->isWarningAction($warningItem)
        )
        {
            $parentWarningCategoryId = $warningItem['sv_warning_category_id'];
        }

        if ($parentWarningCategoryId === 0)
        {
            return $parentWarningCategories;
        }

        $parentWarningCategory = $warningCategories[$parentWarningCategoryId];

        $parentWarningCategories[$parentWarningCategoryId] = $parentWarningCategory;

        return $this->getParentWarningCategoriesByWarningItem(
            $parentWarningCategory,
            $warningCategories,
            $parentWarningCategories
        );
    }

    public function getWarningCategoryOptions($rootOnly = false)
    {
        if (!$rootOnly)
        {
            $categories = $this->getWarningCategories();
            $categories = $this->calculateWarningItemsDepth($categories);
        }
        else
        {
            $categories = $this->getWarningCategoriesByParentId(0);
        }

        $categories = $this->prepareWarningCategories($categories);

        $options = array();

        foreach ($categories as $category)
        {
            $categoryId = $category['warning_category_id'];

            $options[$categoryId] = array(
                'value' => $categoryId,
                'label' => $category['title'],
                'depth' => (!$rootOnly ? $category['depth'] : 0)
            );
        }

        return $options;
    }

    public function canViewWarningCategory(
        array $warningCategory = null,
        array $warningCategories = null,
        array $viewingUser = null
    ) {
        if (empty($warningCategory) || empty($warningCategory['allowed_user_group_ids']))
        {
            return false;
        }

        $this->standardizeViewingUserReference($viewingUser);

        $allowedUserGroupIds = explode(
            ',',
            $warningCategory['allowed_user_group_ids']
        );
        $secondaryUserGroupIds = explode(
            ',',
            $viewingUser['secondary_group_ids']
        );
        $matchingSecondaryUserGroupIds = array_intersect(
            $allowedUserGroupIds,
            $secondaryUserGroupIds
        );

        if (!in_array($viewingUser['user_group_id'], $allowedUserGroupIds) &&
            empty($matchingSecondaryUserGroupIds))
        {
            return false;
        }

        $parentWarningCategoryId = $warningCategory['parent_warning_category_id'];

        if ($parentWarningCategoryId === 0)
        {
            return true;
        }

        if ($warningCategories === null)
        {
            $warningCategories = $this->prepareWarningCategories(
                $this->getWarningCategories(true)
            );
        }

        return $this->canViewWarningCategory(
            $warningCategories[$parentWarningCategoryId],
            $warningCategories,
            $viewingUser
        );
    }

    public function prepareWarningCategory(array $warningCategory)
    {
        if (!empty($warningCategory['warning_category_id']))
        {
            $warningCategory['title'] = new XenForo_Phrase(
                $this->getWarningCategoryTitlePhraseName(
                    $warningCategory['warning_category_id']
                )
            );
        }

        return $warningCategory;
    }

    public function prepareWarningCategories(array $warningCategories)
    {
        return array_map(
            array($this, 'prepareWarningCategory'),
            $warningCategories
        );
    }

    public function getWarningDefinitions()
    {
        $warningDefinitions = parent::getWarningDefinitions();

        uasort($warningDefinitions, function ($first, $second)
        {
            $key = 'sv_display_order';

            if ($first[$key] === $second[$key])
            {
                return 0;
            }

            return ($first[$key] < $second[$key]) ? -1 : 1;
        });

        return $warningDefinitions;
    }

    public function getWarningDefinitionsByCategoryId($warningCategoryId)
    {
        return $this->fetchAllKeyed(
            'SELECT *
                FROM xf_warning_definition
                WHERE sv_warning_category_id = ?
                ORDER BY sv_display_order',
            'warning_definition_id',
            $warningCategoryId
        );
    }

    public function canViewWarningDefinition(
        array $warningDefinition,
        array $warningCategories = null,
        array $viewingUser = null
    ) {
        $this->standardizeViewingUserReference($viewingUser);

        if ($warningCategories === null)
        {
            $warningCategories = $this->prepareWarningCategories(
                $this->getWarningCategories(true)
            );
        }

        if (empty($warningDefinition['sv_warning_category_id']) || empty($warningCategories[$warningDefinition['sv_warning_category_id']]))
        {
            return false;
        }
        $warningCategory = $warningCategories[$warningDefinition['sv_warning_category_id']];

        if (!$this->canViewWarningCategory(
            $warningCategory,
            $warningCategories,
            $viewingUser
        )) {
            return false;
        }

        return true;
    }

    public function getWarningActionsByCategoryId($warningCategoryId)
    {
        return $this->fetchAllKeyed(
            'SELECT *
                FROM xf_warning_action
                WHERE sv_warning_category_id = ?
                ORDER BY points',
            'warning_action_id',
            $warningCategoryId
        );
    }

    public function getWarningItems($filterViewable = false, array $viewingUser = null)
    {
        $this->standardizeViewingUserReference($viewingUser);

        $warningCategories = $this->prepareWarningCategories(
            $this->getWarningCategories()
        );
        $warningDefinitions = $this->prepareWarningDefinitions(
            $this->getWarningDefinitions()
        );

        $warningItems = array_merge($warningCategories, $warningDefinitions);

        if ($filterViewable)
        {
            $warningItems = $this->filterViewableWarningItems(
                $warningItems,
                $warningCategories,
                $viewingUser
            );
        }

        $warningItems = $this->sortWarningItems($warningItems);
        $warningItems = $this->calculateWarningItemsDepth($warningItems);

        return $warningItems;
    }

    public function getWarningItemsByParentId($warningCategoryId)
    {
        $warningCategories = $this->prepareWarningCategories(
            $this->getWarningCategoriesByParentId($warningCategoryId)
        );
        $warningDefinitions = $this->prepareWarningDefinitions(
            $this->getWarningDefinitionsByCategoryId($warningCategoryId)
        );
        $warningActions = $this->getWarningActionsByCategoryId(
            $warningCategoryId
        );

        return array_merge(
            $warningCategories,
            $warningDefinitions,
            $warningActions
        );
    }

    public function sortWarningItems(array $warningItems)
    {
        uasort($warningItems, function ($first, $second)
        {
            $keys = array('parent_warning_category_id', 'sv_warning_category_id');
            $firstOrder = $secondOrder = null; // null allows isset() to return false

            foreach ($keys as $key)
            {
                if (!isset($firstOrder) && isset($first[$key]))
                {
                    $firstOrder = $first[$key];
                }

                if (!isset($secondOrder) && isset($second[$key]))
                {
                    $secondOrder = $second[$key];
                }
            }

            if ($firstOrder === $secondOrder)
            {
                return 0;
            }

            return ($firstOrder < $secondOrder) ? -1 : 1;
        });

        uasort($warningItems, function ($first, $second)
        {
            $keys = array('display_order', 'sv_display_order');
            $firstOrder = $secondOrder = null; // null allows isset() to return false

            foreach ($keys as $key)
            {
                if (!isset($firstOrder) && isset($first[$key]))
                {
                    $firstOrder = $first[$key];
                }

                if (!isset($secondOrder) && isset($second[$key]))
                {
                    $secondOrder = $second[$key];
                }
            }

            if ($firstOrder === $secondOrder)
            {
                return 0;
            }

            return ($firstOrder < $secondOrder) ? -1 : 1;
        });

        return $warningItems;
    }

    public function calculateWarningItemsDepth(
        array &$warningItems,
        $parentId = 0,
        $depth = 0
    ) {
        $calculatedItems = array();
        $itemParentId = null;

        foreach ($warningItems as $warningItemId => $warningItem)
        {
            if ($this->isWarningCategory($warningItem))
            {
                $itemParentId = $warningItem['parent_warning_category_id'];
            }
            elseif ($this->isWarningDefinition($warningItem))
            {
                $itemParentId = $warningItem['sv_warning_category_id'];
            }

            if ($itemParentId === $parentId)
            {
                $warningItem['depth'] = $depth;
                $calculatedItems[] = $warningItem;

                if ($this->isWarningCategory($warningItem))
                {
                    $calculatedItems = array_merge(
                        $calculatedItems,
                        $this->calculateWarningItemsDepth(
                            $warningItems,
                            $warningItem['warning_category_id'],
                            $depth + 1
                        )
                    );
                }

                unset($warningItems[$warningItemId]);
            }
        }

        return $calculatedItems;
    }

    public function filterViewableWarningItems(
        array $warningItems,
        array $warningCategories = null,
        array $viewingUser = null
    ) {
        $this->standardizeViewingUserReference($viewingUser);

        if ($warningCategories === null)
        {
            $warningCategories = $this->prepareWarningCategories(
                $this->getWarningCategories(true)
            );
        }

        foreach ($warningItems as $warningItemId => $warningItem)
        {
            if ($this->isWarningCategory($warningItem))
            {
                if (!$this->canViewWarningCategory(
                    $warningItem,
                    $warningCategories,
                    $viewingUser
                )) {
                    unset($warningItems[$warningItemId]);
                }
            }
            elseif ($this->isWarningDefinition($warningItem))
            {
                if (!$this->canViewWarningDefinition(
                    $warningItem,
                    $warningCategories,
                    $viewingUser
                )) {
                    unset($warningItems[$warningItemId]);
                }
            }
        }

        return $warningItems;
    }

    public function getWarningItemTree(array $warningItems = null)
    {
        if (!$this->isWarningItemsArray($warningItems))
        {
            $warningItems = $this->getWarningItems();
        }

        $tree = array();

        foreach ($warningItems as $warningItem)
        {
            $node = array();

            if ($this->isWarningCategory($warningItem))
            {
                $node['id'] = 'c'.$warningItem['warning_category_id'];
                $node['type'] = 'category';

                if ($warningItem['parent_warning_category_id'] !== 0)
                {
                    $node['parent'] = 'c'.$warningItem['parent_warning_category_id'];
                }
                else
                {
                    $node['parent'] = '#';
                }
                $node['state']['opened'] = 1;
                $node['a_attr']['href'] = XenForo_Link::buildAdminLink(
                    'warnings/category-edit',
                    array(),
                    array('warning_category_id' => $warningItem['warning_category_id'])
                );
            }
            elseif ($this->isWarningDefinition($warningItem))
            {
                $node['id'] = 'd'.$warningItem['warning_definition_id'];
                $node['type'] = 'definition';
                $node['parent'] = 'c'.$warningItem['sv_warning_category_id'];
                $node['a_attr']['href'] = XenForo_Link::buildAdminLink(
                    'warnings/edit',
                    $warningItem
                );
            }

            $node['text'] = $warningItem['title'];

            $tree[] = $node;
        }

        return $tree;
    }

    public function processWarningItemTreeItem(array $node)
    {
        return array(
            'type' => $node['type'],
            'id'   => (int)substr($node['id'], 1),
            'title' => $node['text']
        );
    }

    public function processWarningItemTree(array &$tree, $parentId = 0)
    {
        $warningItems = array();

        $displayOrder = 0;
        foreach ($tree as $branchId => $branch)
        {
            if (!is_int($branch['id']))
            {
                $branch['id'] = (int)substr($branch['id'], 1);
            }

            if (!is_int($branch['parent']))
            {
                if ($branch['parent'] != '#')
                {
                    $branch['parent'] = (int)substr($branch['parent'], 1);
                }
                else
                {
                    $branch['parent'] = 0;
                }
            }

            if ($branch['parent'] === $parentId)
            {
                $item = array(
                    'type'          => $branch['type'],
                    'id'            => $branch['id'],
                    'parent'        => $branch['parent'],
                    'display_order' => $displayOrder
                );
                $warningItems[] = $item;

                if ($branch['type'] === 'category')
                {
                    $warningItems = array_merge(
                        $warningItems,
                        $this->processWarningItemTree($tree, $branch['id'])
                    );
                }

                unset($branch[$branchId]);

                $displayOrder++;
            }
        }

        return $warningItems;
    }

    public function groupWarningItemsByRootWarningCategory(array $warningItems)
    {
        $warningCategories = array();

        foreach ($warningItems as $warningItem)
        {
            if ($this->isWarningCategory($warningItem))
            {
                if ($warningItem['parent_warning_category_id'] === 0)
                {
                    $warningItemId = $warningItem['warning_category_id'];
                    $warningCategories[$warningItemId] = $warningItem;

                    continue;
                }
            }

            $rootWarningCategory = $this->getRootWarningCategoryByWarningItem(
                $warningItem
            );
            $rootWarningCategoryId = $rootWarningCategory['warning_category_id'];
            $warningCategories[$rootWarningCategoryId]['children'][] = $warningItem;
        }

        foreach ($warningCategories as $warningCategoryId => $warningCategory)
        {
            if (empty($warningCategory['children']))
            {
                unset($warningCategories[$warningCategoryId]);
            }
        }

        return $warningCategories;
    }

    public function groupWarningItemsByWarningCategory(array $warningItems)
    {
        $warningCategories = array(0 => array());

        foreach ($warningItems as $warningItemId => $warningItem)
        {
            if ($this->isWarningCategory($warningItem))
            {
                $categoryId = $warningItem['warning_category_id'];
                $warningCategories[$categoryId] = $warningItem;
            }
            elseif ($this->isWarningDefinition($warningItem))
            {
                $definitionId = $warningItem['warning_definition_id'];
                $categoryId = $warningItem['sv_warning_category_id'];
                $warningCategories[$categoryId]['warnings'][$definitionId] = $warningItem;
            }
            elseif ($this->isWarningAction($warningItem))
            {
                $actionId= $warningItem['warning_action_id'];
                $categoryId = $warningItem['sv_warning_category_id'];
                $warningCategories[$categoryId]['actions'][$actionId] = $warningItem;
            }
        }

        foreach ($warningCategories as $warningCategoryId => $warningCategory)
        {
            if (empty($warningCategory['warnings']) &&
                empty($warningCategory['actions'])
            ) {
                unset($warningCategories[$warningCategoryId]);
            }
        }

        return $warningCategories;
    }

    public function getWarningByIds($warningIds)
    {
        if (empty($warningIds))
        {
            return array();
        }

        return $this->fetchAllKeyed('
            SELECT warning.*, user.*, warn_user.username AS warn_username
            FROM xf_warning AS warning
            LEFT JOIN xf_user AS user ON (user.user_id = warning.user_id)
            LEFT JOIN xf_user AS warn_user ON (warn_user.user_id = warning.warning_user_id)
            WHERE warning.warning_id IN (' . $this->_getDb()->quote($warningIds) . ')
        ', 'warning_id');
    }

    public function getWarningDefaultById($id)
    {
        return $this->_getDb()->fetchRow('
            SELECT *
            FROM xf_sv_warning_default
            WHERE warning_default_id = ?
        ', $id);
    }

    public function getLastWarningDefault()
    {
        return $this->_getDb()->fetchOne('
            SELECT max(threshold_points)
            FROM xf_sv_warning_default
        ');
    }

    public function getWarningDefaultExtentions()
    {
        return $this->fetchAllKeyed('
            SELECT *
            FROM xf_sv_warning_default
            order by threshold_points
        ', 'warning_default_id');
    }

    public function getWarningDefaultExtention(/** @noinspection PhpUnusedParameterInspection */ $warningCount, $warningTotals)
    {
        return $this->_getDb()->fetchRow('
            SELECT warning_default.*
            FROM xf_sv_warning_default AS warning_default
            WHERE ? >= warning_default.threshold_points AND
                  warning_default.active = 1
            order by threshold_points desc
            limit 1
        ', $warningTotals);
    }

    public function _getWarningTotals($userId)
    {
        return $this->_getDb()->fetchRow('
            SELECT count(points) AS `count`, sum(points) AS `total`
            FROM xf_warning
            WHERE user_id = ?
        ', $userId);
    }

    protected $_warningTotalsCache = array();

    public function prepareWarningDefinition(array $warning, $includeConversationInfo = false)
    {
        $warning = parent::prepareWarningDefinition($warning, $includeConversationInfo);

        if ($warning['expiry_type'] != 'never' &&
            SV_WarningImprovements_Globals::$scaleWarningExpiry &&
            SV_WarningImprovements_Globals::$warning_user_id)
        {
            $warning_user_id = SV_WarningImprovements_Globals::$warning_user_id;
            if (empty($this->_warningTotalsCache[$warning_user_id]))
            {
                $this->_warningTotalsCache[$warning_user_id] = $this->_getWarningTotals($warning_user_id);
            }
            $totals = $this->_warningTotalsCache[$warning_user_id];

            $row = $this->getWarningDefaultExtention($totals['count'], $totals['total']);

            if (!empty($row['expiry_extension']))
            {
                if ($row['expiry_type'] === 'never')
                {
                    $warning['expiry_type'] = $row['expiry_type'];
                    $warning['expiry_default'] = $row['expiry_extension'];
                }
                else if ($warning['expiry_type'] == $row['expiry_type'])
                {
                    $warning['expiry_default'] = $warning['expiry_default'] + $row['expiry_extension'];
                }
                else if ($warning['expiry_type'] === 'months' && $row['expiry_type'] === 'years')
                {
                    $warning['expiry_default'] = $warning['expiry_default'] + $row['expiry_extension'] * 12;
                }
                else if ($warning['expiry_type'] === 'years' && $row['expiry_type'] === 'months')
                {
                    $warning['expiry_default'] = $warning['expiry_default'] * 12 + $row['expiry_extension'];
                    $warning['expiry_type'] = 'months';
                }
                else
                {
                    $expiry_duration = $this->convertToDays($warning['expiry_type'], $warning['expiry_default']) +
                                                             $this->convertToDays($row['expiry_type'], $row['expiry_extension']);

                    $expiry_parts = $this->convertDaysToLargestType($expiry_duration);

                    $warning['expiry_type'] = $expiry_parts[0];
                    $warning['expiry_default'] = $expiry_parts[1];
                }
            }
        }
        return $warning;
    }

    protected function convertToDays($expiry_type, $expiry_duration)
    {
        switch($expiry_type)
        {
            case 'days':
                return $expiry_duration;
            case 'weeks':
                return $expiry_duration * 7;
            case 'months':
                return $expiry_duration * 30;
            case 'years':
                return $expiry_duration * 365;
        }
        XenForo_Error::logException(new Exception("Unknown expiry type: " . $expiry_type), false);
        return $expiry_duration;
    }

    protected function convertDaysToLargestType($expiry_duration)
    {
        if (($expiry_duration % 365) == 0)
            return array('years', $expiry_duration / 365);
        else if (($expiry_duration % 30) == 0)
            return array('months', $expiry_duration / 30);
        else if (($expiry_duration % 7) == 0)
            return array('weeks', $expiry_duration / 7);
        else
            return array('days', $expiry_duration);
    }

    protected $lastWarningAction = null;

    public function getCategoryWarningPointsByUser($userId, $removePoints = false)
    {
        if (!empty($this->_userWarningPoints[$userId]))
        {
            return $this->_userWarningPoints[$userId];
        }

        $warningCategories = $this->getWarningCategories(true);
        $warningDefinitions = $this->getWarningDefinitions();
        $warnings = $this->getWarningsByUser($userId);

        $oldWarning = null;
        $newWarning = null;
        if (SV_WarningImprovements_Globals::$warningObj !== null)
        {
            if($removePoints)
            {
                $oldWarning = SV_WarningImprovements_Globals::$warningObj;
            }
            else
            {
                $newWarning = SV_WarningImprovements_Globals::$warningObj;
            }
        }

        $warningPoints = array();
        $warningPointsCumulative = array(
            0 => array(
                'old' => 0,
                'new' => 0
            )
        );

        foreach ($warnings as $warning)
        {
            if ($warning['is_expired'])
            {
                continue;
            }

            $warningDefinitionId = $warning['warning_definition_id'];

            if (empty($warningDefinitions[$warningDefinitionId]))
            {
                $warningCategoryId = false;
            }
            else
            {
                $warningDefinition = $warningDefinitions[$warningDefinitionId];
                $warningCategoryId = $warningDefinition['sv_warning_category_id'];

                if (empty($warningPoints[$warningCategoryId]))
                {
                    $warningPoints[$warningCategoryId]['old'] = 0;
                    $warningPoints[$warningCategoryId]['new'] = 0;
                }
            }

            if ($newWarning === null ||
                $warning['warning_id'] != $newWarning['warning_id'])
            {
                $warningPointsCumulative[0]['old'] += $warning['points'];

                if ($warningCategoryId)
                {
                    $warningPoints[$warningCategoryId]['old'] += $warning['points'];
                }
            }

            if ($oldWarning === null ||
                $warning['warning_id'] != $oldWarning['warning_id'])
            {
                $warningPointsCumulative[0]['new'] += $warning['points'];

                if ($warningCategoryId)
                {
                    $warningPoints[$warningCategoryId]['new'] += $warning['points'];
                }
            }
        }

        foreach ($warningCategories as $warningCategoryId => $warningCategory)
        {
            if (empty($warningPoints[$warningCategoryId]))
            {
                $warningPoints[$warningCategoryId]['old'] = 0;
                $warningPoints[$warningCategoryId]['new'] = 0;
            }

            $oldPoints = $warningPoints[$warningCategoryId]['old'];
            $newPoints = $warningPoints[$warningCategoryId]['new'];

            $children = $this->getWarningCategoriesByParentId(
                $warningCategoryId,
                $warningCategories
            );

            foreach ($children as $childCategoryId => $child)
            {
                if (!empty($warningPoints[$childCategoryId]))
                {
                    $oldPoints += $warningPoints[$childCategoryId]['old'];
                    $newPoints += $warningPoints[$childCategoryId]['new'];
                }
            }

            $warningPointsCumulative[$warningCategoryId]['old'] = $oldPoints;
            $warningPointsCumulative[$warningCategoryId]['new'] = $newPoints;
        }

        $this->_userWarningPoints[$userId] = $warningPointsCumulative;

        return $warningPointsCumulative;
    }

    protected function _userWarningPointsIncreased(
        $userId,
        $newPoints,
        $oldPoints
    ) {
        parent::_userWarningPointsIncreased($userId, $newPoints, $oldPoints);

        // only do the last post action
        if ($this->lastWarningAction)
        {
            $posterUserId = empty($this->lastWarningAction['sv_post_as_user_id'])
                          ? null
                          : $this->lastWarningAction['sv_post_as_user_id'];

            $options = XenForo_Application::getOptions();
            $dateStr = date($options->sv_warning_date_format);
            // post a new thread
            if (!empty($this->lastWarningAction['sv_post_node_id']))
            {
                $this->postThread($this->lastWarningAction, $userId, $this->lastWarningAction['sv_post_node_id'], $posterUserId, SV_WarningImprovements_Globals::$warningObj, SV_WarningImprovements_Globals::$reportObj, $dateStr);
            }
            // post a reply
            else if (!empty($this->lastWarningAction['sv_post_thread_id']))
            {
                $this->postReply($this->lastWarningAction, $userId, $this->lastWarningAction['sv_post_thread_id'], $posterUserId, SV_WarningImprovements_Globals::$warningObj, SV_WarningImprovements_Globals::$reportObj, $dateStr);
            }
        }
    }

    public function triggerWarningAction($userId, array $action)
    {
        $triggerId = parent::triggerWarningAction($userId, $action);

        if (SV_WarningImprovements_Globals::$NotifyOnWarningAction &&
            (empty($this->lastWarningAction) || $action['points'] > $this->lastWarningAction['points']) &&
            (!empty($action['sv_post_node_id']) || !empty($action['sv_post_thread_id'])))
        {
            $this->lastWarningAction = $action;
        }

        return $triggerId;
    }

    protected function getWarningCategoryForWarning($warning)
    {
        if (isset($warning['sv_warning_category_id']))
        {
            return (new XenForo_Phrase($this->getWarningCategoryTitlePhraseName($warning['sv_warning_category_id'])))->render(false);
        }
        if (isset($warning['warning_category_id']))
        {
            return (new XenForo_Phrase($this->getWarningCategoryTitlePhraseName($warning['warning_category_id'])))->render(false);
        }
        return  '';
    }

    protected function postReply(array $action, $userId, $threadId, $posterUserId, $warning, $report, $dateStr)
    {
        $thread = $this->_getThreadModel()->getThreadById($threadId);
        if (empty($thread))
        {
            return;
        }
        $forum = $this->_getForumModel()->getForumById($thread['node_id']);
        if (empty($forum))
        {
            return;
        }
        $user = $this->_getUserModel()->getUserById($userId);
        if (empty($user))
        {
            return;
        }
        if (empty($posterUserId))
        {
            $poster = XenForo_Visitor::getInstance()->toArray();
            $permissions = $poster['permissions'];
        }
        else
        {
            $poster = $this->_getUserModel()->getUserById($posterUserId,array(
                'join' => XenForo_Model_User::FETCH_USER_PERMISSIONS
            ));
            if (empty($poster))
            {
                return;
            }
            $permissions = XenForo_Permission::unserializePermissions($poster['global_permission_cache']);
        }
        $input = array(
            'username' => $user['username'],
            'points' => $user['warning_points'],
            'report' => empty($report) ? 'N/A' : XenForo_Link::buildPublicLink('full:reports', $report),
            'date' => $dateStr,
            'warning_title' =>  empty($warning['title']) ? new XenForo_Phrase('n_a') : $warning['title'],
            'warning_points' => empty($warning) ? '0' : $warning['points'],
            'warning_category' =>  $this->getWarningCategoryForWarning($warning),
            'threshold' => $action['points'],
        );

        $message = new XenForo_Phrase('Warning_Thread_Message', $input, false);
        $message = XenForo_Helper_String::autoLinkBbCode($message->render());

        $writer = XenForo_DataWriter::create('XenForo_DataWriter_DiscussionMessage_Post');
        $writer->set('user_id', $poster['user_id']);
        $writer->set('username', $poster['username']);
        $writer->set('message', $message);
        $writer->set('message_state', $this->_getPostModel()->getPostInsertMessageState($thread, $forum));
        $writer->set('thread_id', $threadId);
        $writer->setExtraData(XenForo_DataWriter_DiscussionMessage_Post::DATA_FORUM, $forum);
        $writer->setOption(XenForo_DataWriter_DiscussionMessage_Post::OPTION_MAX_TAGGED_USERS, XenForo_Permission::hasPermission($permissions, 'general', 'maxTaggedUsers'));
        if (!empty($posterUserId))
        {
            $writer->setOption(XenForo_DataWriter_DiscussionMessage_Post::OPTION_IS_AUTOMATED, true);
        }
        $writer->save();
    }

    protected function postThread(array $action, $userId, $nodeId, $posterUserId, $warning, $report, $dateStr)
    {
        $forum = $this->_getForumModel()->getForumById($nodeId);
        if (empty($forum))
        {
            return;
        }
        $user = $this->_getUserModel()->getUserById($userId);
        if (empty($user))
        {
            return;
        }
        if (empty($posterUserId))
        {
            $poster = XenForo_Visitor::getInstance()->toArray();
            $permissions = $poster['permissions'];
        }
        else
        {
            $poster = $this->_getUserModel()->getUserById($posterUserId,array(
                'join' => XenForo_Model_User::FETCH_USER_PERMISSIONS
            ));
            if (empty($poster))
            {
                return;
            }
            $permissions = XenForo_Permission::unserializePermissions($poster['global_permission_cache']);
        }
        $input = array(
            'username' => $user['username'],
            'points' => $user['warning_points'],
            'report' => empty($report) ? new XenForo_Phrase('n_a') : XenForo_Link::buildPublicLink('full:reports', $report),
            'date' => $dateStr,
            'warning_title' =>  empty($warning['title']) ? new XenForo_Phrase('n_a') : $warning['title'],
            'warning_points' => empty($warning) ? '0' : $warning['points'],
            'warning_category' => $this->getWarningCategoryForWarning($warning),
            'threshold' => $action['points'],
        );

        $title = new XenForo_Phrase('Warning_Thread_Title', $input, false);
        $message = new XenForo_Phrase('Warning_Thread_Message', $input, false);
        $message = XenForo_Helper_String::autoLinkBbCode($message->render());

        /** @var XenForo_DataWriter_Discussion_Thread $threadDw */
        $threadDw = XenForo_DataWriter::create('XenForo_DataWriter_Discussion_Thread', XenForo_DataWriter::ERROR_SILENT);
        $threadDw->setOption(XenForo_DataWriter_Discussion::OPTION_TRIM_TITLE, true);
        $threadDw->setExtraData(XenForo_DataWriter_Discussion_Thread::DATA_FORUM, $forum);
        $threadDw->bulkSet(array(
            'user_id' => $poster['user_id'],
            'username' => $poster['username'],
            'node_id' => $forum['node_id'],
            'discussion_state' => 'visible',
            'prefix_id' => $forum['default_prefix_id'],
            'title' => $title->render(),
        ));

        $postWriter = $threadDw->getFirstMessageDw();
        $postWriter->set('message', $message);
        $postWriter->setExtraData(XenForo_DataWriter_DiscussionMessage_Post::DATA_FORUM, $forum);
        $postWriter->setOption(XenForo_DataWriter_DiscussionMessage_Post::OPTION_MAX_TAGGED_USERS, XenForo_Permission::hasPermission($permissions, 'general', 'maxTaggedUsers'));
        if (!empty($posterUserId))
        {
            $postWriter->setOption(XenForo_DataWriter_DiscussionMessage_Post::OPTION_IS_AUTOMATED, true);
        }
        $threadDw->save();
    }

    protected $warning_user = null;
    protected $viewer = null;

    public function prepareWarning(array $warning)
    {
        $warning = parent::prepareWarning($warning);

        if ($this->viewer === null)
        {
            $this->viewer = XenForo_Visitor::getInstance()->toArray();
        }
        $viewer = $this->viewer;

        if(!XenForo_Permission::hasPermission($viewer['permissions'], 'general', 'viewWarning'))
        {
            if (!empty($warning['content_title']))
            {
                $warning['content_title'] = XenForo_Helper_String::censorString($warning['content_title']);
            }
            $warning['notes'] = '';
            if (!empty($warning['expiry_date']))
            {
                $warning['expiry_date'] = $warning['expiry_date'] - ($warning['expiry_date'] % 3600) + 3600;
            }
        }

        if (!XenForo_Permission::hasPermission($viewer['permissions'], 'general', 'viewWarning_issuer') && !XenForo_Permission::hasPermission($viewer['permissions'], 'general', 'viewWarning'))
        {
            $anonymisedWarning = false;
            $options = XenForo_Application::getOptions();
            if ($options->sv_warningimprovements_warning_user)
            {
                if ($this->warning_user === null)
                {
                    $this->warning_user = $this->_getUserModel()->getUserByName($options->sv_warningimprovements_warning_user);
                    if (empty($this->warning_user))
                    {
                        $this->warning_user = array();
                    }
                }
                if (isset($this->warning_user['user_id']))
                {
                    $warning['warn_user_id'] = $this->warning_user['user_id'];
                    $warning['warn_username'] = $this->warning_user['username'];
                    $anonymisedWarning = true;
                }
            }
            if (!$anonymisedWarning)
            {
                $warning['warn_user_id'] = 0;
                $warning['warn_username'] = new XenForo_Phrase('WarningStaff');
            }
        }
        return $warning;
    }

    /**
     * @return XenForo_Model|SV_WarningImprovements_XenForo_Model_User
     */
    protected function _getUserModel()
    {
        return $this->getModelFromCache('XenForo_Model_User');
    }

    /**
     * @return XenForo_Model|XenForo_Model_Forum
     */
    protected function _getForumModel()
    {
        return $this->getModelFromCache('XenForo_Model_Forum');
    }

    /**
     * @return XenForo_Model|XenForo_Model_Thread
     */
    protected function _getThreadModel()
    {
        return $this->getModelFromCache('XenForo_Model_Thread');
    }

    /**
     * @return XenForo_Model|XenForo_Model_Post
     */
    protected function _getPostModel()
    {
        return $this->getModelFromCache('XenForo_Model_Post');
    }

    /**
     * @return XenForo_Model|SV_WarningImprovements_XenForo_Model_UserChangeTemp
     */
    protected function _getWarningActionModel()
    {
        return $this->getModelFromCache('XenForo_Model_UserChangeTemp');
    }
}
