<?php

namespace Claroline\CoreBundle\Badge;

use Claroline\CoreBundle\Entity\Badge\Badge;
use Claroline\CoreBundle\Entity\Badge\BadgeRule;
use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Entity\Log\Log;
use Claroline\CoreBundle\Repository\Log\LogRepository;

class BadgeRuleChecker
{
    /**
     * @var LogRepository
     */
    private $logRepository;

    public function __construct(LogRepository $logRepository)
    {
        $this->logRepository = $logRepository;
    }

    /**
     * @param BadgeRule $badgeRule
     * @param User      $user
     *
     * @return bool|Log[]
     */
    public function checkRule(BadgeRule $badgeRule, User $user)
    {
        $associatedLogs = $this->logRepository->findByBadgeRuleAndUser($badgeRule, $user);

        $checkRule = false;

        if (0 < count($associatedLogs) && count($associatedLogs) >= $badgeRule->getOccurrence()) {
            $checkRule = $associatedLogs;
        }

        return $checkRule;
    }

    /**
     * @param Badge $badge
     * @param User  $user
     *
     * @return array
     */
    public function checkBadge(Badge $badge, User $user)
    {
        $return    = array();
        $isChecked = true;

        $badgeRules = $badge->getBadgeRules();

        if (0 < count($badgeRules)) {
            foreach ($badgeRules as $badgeRule) {

                $checkedLogs = $this->checkRule($badgeRule, $user);

                if (false === $checkedLogs) {
                    $isChecked = false;
                }
                else {
                    foreach ($checkedLogs as $checkedLog) {
                        $return[] = $checkedLog;
                    }
                }
            }
        }
        else {
            $isChecked = false;
        }

        return (false === $isChecked) ? false : $return;
    }
}
