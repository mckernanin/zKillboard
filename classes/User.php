<?php

class User
{
    public static function setLogin($username, $password, $autoLogin)
    {
        return true;
    }

    public static function checkLogin($username, $password)
    {
        return false;
    }

    public static function checkLoginHashed($userID)
    {
        return;
    }

    public static function autoLogin()
    {
        return false;
    }

    public static function isLoggedIn()
    {
        return (int) @$_SESSION['characterID'] != null;
    }

    /**
     * @return array|null
     */
    public static function getUserInfo()
    {
        global $redis, $mdb, $adminCharacter;

        $id = self::getUserID();
        if ($id == 0) return [];

        $i = $mdb->findDoc("users", ['userID' => "user:$id"]);
        $i['username'] = Info::getInfoField('characterID', $id, 'name');

        if ($adminCharacter == $id) {
            $i['moderator'] = true;
            $i['admin'] = true;
        }


        return $i;
    }

    /**
     * @return int
     */
    public static function getUserID()
    {
        return (int) @$_SESSION['characterID'];
    }

    /**
     * @return bool
     */
    public static function isModerator()
    {
        $info = self::getUserInfo();
        return @$info['moderator'];
    }

    /**
     * @return bool
     */
    public static function isAdmin()
    {
        return false;
    }

    /**
     * @param int $userID
     *
     * @return string
     */
    public static function getUsername($userID)
    {
        return;
    }

    /**
     * @param int $userID
     *
     * @return array|null
     */
    public static function getSessions($userID)
    {
        return;
    }

    public static function getBalance($userID)
    {
        return 0;
    }

    public static function getPaymentHistory($userID)
    {
        global $mdb;

        $history = $mdb->find('payments', ['ownerID1' => (int) $userID], ['date' => -1]);

        return $history;
    }

    public static function getUserTrackerData()
    {
        return [];
    }

    public static function sendMessage($message, $userID = null)
    {
        global $redis;

        if ($userID == null) {
            $userID = self::getUserID();
        }

        $redisKey = "message:$userID";
        $redis->rpush($redisKey, $message);
        $redis->expire($redisKey, 86400 * 30);
    }

    public static function getMessage($userID = null)
    {
        global $redis;

        if ($userID == null) {
            $userID = self::getUserID();
        }

        $redisKey = "message:$userID";
        $message = $redis->lpop($redisKey);

        return $message;
    }
}
