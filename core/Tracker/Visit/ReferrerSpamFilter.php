<?php

namespace Piwik\Tracker\Visit;

use Piwik\Cache;
use Piwik\Common;
use Piwik\Option;
use Piwik\Tracker\Request;

/**
 * Filters out tracking requests issued by spammers.
 */
class ReferrerSpamFilter
{
    const OPTION_STORAGE_NAME = 'referrer_spam_blacklist';
    /**
     * @var string[]
     */
    private $spammerList;

    /**
     * Check if the request is from a known spammer host.
     *
     * @param Request $request
     * @return bool
     */
    public function isSpam(Request $request)
    {
        $spammers = $this->getSpammerListFromCache();

        $referrerUrl = $request->getParam('urlref');

        foreach ($spammers as $spammerHost) {
            if (stripos($referrerUrl, $spammerHost) !== false) {
                Common::printDebug('Referrer URL is a known spam: ' . $spammerHost);
                return true;
            }
        }

        return false;
    }

    private function getSpammerListFromCache()
    {
        $cache = Cache::getEagerCache();
        $cacheId = 'ReferrerSpamFilter-' . self::OPTION_STORAGE_NAME;

        if ($cache->contains($cacheId)) {
            $list = $cache->fetch($cacheId);
        } else {
            $list = $this->loadSpammerList();
            $cache->save($cacheId, $list);
        }

        return $list;
    }

    private function loadSpammerList()
    {
        if ($this->spammerList !== null) {
            return $this->spammerList;
        }

        // Read first from the auto-updated list in database
        $list = Option::get(self::OPTION_STORAGE_NAME);

        if ($list) {
            $this->spammerList = unserialize($list);
        } else {
            // Fallback to reading the bundled list
            if (file_exists(PIWIK_INCLUDE_PATH . '/vendor/piwik/referrer-spam-blacklist/spammers.txt')) {
                $file = PIWIK_INCLUDE_PATH . '/vendor/piwik/referrer-spam-blacklist/spammers.txt'; // Piwik is the main project
            } else {
                $file = PIWIK_INCLUDE_PATH . '/../../piwik/referrer-spam-blacklist/spammers.txt'; // Piwik is installed as a dependency
            }
            $this->spammerList = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }

        return $this->spammerList;
    }
}
