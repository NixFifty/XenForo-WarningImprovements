<?php

class SV_WarningImprovements_Criteria
{
    /** @var SV_WarningImprovements_XenForo_Model_Warning $warningModel */
    protected static $warningModel =  null;

    /**
     * @return SV_WarningImprovements_XenForo_Model_Warning
     */
    protected static function getWarningModel()
    {
        if (self::$warningModel === null)
        {
            self::$warningModel = XenForo_Model::create('XenForo_Model_Warning');
        }
        return self::$warningModel;
    }

    public static function criteriaUser($rule, array $data, array $user, &$returnValue)
    {
        switch ($rule)
        {
            case 'warning_points_l':
                $days = empty($data['days']) ? 0 : intval($data['days']);
                $expired = empty($data['expired']) ? false : true;
                $points = $days ? self::getWarningModel()->getWarningPointsInLastXDays($user['user_id'], $days, $expired) : $user['warning_points'];
                if ($points >= $data['points'])
                {
                    $returnValue = true;
                }
                break;

            case 'warning_points_m':
                $days = empty($data['days']) ? 0 : intval($data['days']);
                $expired = empty($data['expired']) ? false : true;
                $points = $days ? self::getWarningModel()->getWarningPointsInLastXDays($user['user_id'], $days, $expired) : $user['warning_points'];
                if ($points <= $data['points'])
                {
                    $returnValue = true;
                }
                break;

            case 'sv_warning_minimum':
                $days = empty($data['days']) ? 0 : intval($data['days']);
                $expired = empty($data['expired']) ? false : true;
                $points = $days ? self::getWarningModel()->getWarningCountsInLastXDays($user['user_id'], $days, $expired) : $user['warning_points'];
                if ($points >= $data['count'])
                {
                    $returnValue = true;
                }
                break;

            case 'sv_warning_maximum':
                $days = empty($data['days']) ? 0 : intval($data['days']);
                $expired = empty($data['expired']) ? false : true;
                $points = $days ? self::getWarningModel()->getWarningCountsInLastXDays($user['user_id'], $days, $expired) : $user['warning_points'];
                if ($points <= $data['count'])
                {
                    $returnValue = true;
                }
                break;
        }
    }
}