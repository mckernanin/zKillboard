<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$guzzler = new Guzzler(5, 1);

$esi = new RedisTimeQueue('tqCorpApiESI', 3600);
if (date('i') == 22 || $esi->size() == 0) {
    $esis = $mdb->find("scopes", ['scope' => 'esi-killmails.read_corporation_killmails.v1']);
    foreach ($esis as $row) {
        $charID = $row['characterID'];
        $esi->add($charID);
    }
}

$minute = date('Hi');
while ($minute == date('Hi')) {
    Status::checkStatus($guzzler, 'esi');
    $charID = (int) $esi->next();
    if ($charID > 0) {
        $corpID = Info::getInfoField('characterID', $charID, 'corporationID');
        $alliID = Info::getInfoField('characterID', $charID, 'allianceID');
        if (in_array($corpID, $ignoreEntities) || in_array($alliID, $ignoreEntities)) continue;

        $row = $mdb->findDoc("scopes", ['characterID' => $charID, 'scope' => "esi-killmails.read_corporation_killmails.v1"], ['lastFetch' => 1]);
        if ($row != null) {
            $params = ['row' => $row, 'esi' => $esi];
            $refreshToken = $row['refreshToken'];

            CrestSSO::getAccessTokenCallback($guzzler, $refreshToken, "accessTokenDone", "accessTokenFail", $params);
        } else {
            $esi->remove($charID);
        }
    }
    $guzzler->tick();
}
$guzzler->finish();


function accessTokenDone(&$guzzler, &$params, $content)
{
    global $ccpClientID, $ccpSecret;

    $response = json_decode($content, true);
    $accessToken = $response['access_token'];
    $params['content'] = $content;
    $row = $params['row'];

    $headers = [];
    $headers[] = 'Content-Type: application/json';

    $charID = $row['characterID'];
    $corpID = Info::getInfoField("characterID", $charID, 'corporationID');
    $fields = ['datasource' => 'tranquility', 'token' => $accessToken];
    if (isset($params['max_kill_id'])) {
        $fields['max_kill_id'] = $params['max_kill_id'];
    }
    $fields = ESI::buildparams($fields);
    $url = "https://esi.tech.ccp.is/v1/corporations/$corpID/killmails/recent/?$fields";

    $guzzler->call($url, "success", "fail", $params, $headers, 'GET');
}

function success($guzzler, $params, $content) 
{
    global $mdb, $redis;

    $newKills = (int) @$params['newKills'];
    $maxKillID = (int) @$params['maxKillID'];
    $row = $params['row'];
    $prevMaxKillID = (int) @$row['maxKillID'];
    $minKillID = isset($params['max_kill_id']) ? $params['max_kill_id'] : 9999999999;

    $kills = json_decode($content, true);
    foreach ($kills as $kill) {
        $killID = $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $minKillID = min($killID, $minKillID);
        $maxKillID = max($killID, $maxKillID);

        $newKills += addMail($killID, $hash);
    }

    if (sizeof($kills) && $minKillID > $prevMaxKillID) {
        $params['newKills'] = $newKills;
        $params['max_kill_id'] = $minKillID;
        $params['maxKillID'] = $maxKillID;

        accessTokenDone($guzzler, $params, $params['content']);
    } else {
        $charID = $row['characterID'];

        $corpID = (int) Info::getInfoField("characterID", $charID, 'corporationID');
        $mdb->set("scopes", $row, ['maxKillID' => $maxKillID, 'corporationID' => $corpID, 'lastFetch' => $mdb->now()]);

        $name = Info::getInfoField('characterID', $charID, 'name');
        $corpName = Info::getInfoField('corporationID', $corpID, 'name');
        $corpVerified = $redis->get("apiVerified:$corpID") != null;
        if (!$corpVerified) {
            ZLog::add("$corpName ($name) is now verified.", $charID);
        }
        $redis->setex("apiVerified:$corpID", 86400, time());

        if ($newKills > 0) {
            if ($name === null) $name = $charID;
            while (strlen("$newKills") < 3) $newKills = " " . $newKills;
            ZLog::add("$newKills kills added by corp $corpName", $charID);
            if ($newKills >= 10) User::sendMessage("$newKills kills added for corp $corpName", $charID);
        }
    }
}

function addMail($killID, $hash) 
{
    global $mdb;

    $exists = $mdb->exists('crestmails', ['killID' => $killID, 'hash' => $hash]);
    if (!$exists) {
        try {
            $mdb->getCollection('crestmails')->save(['killID' => (int) $killID, 'hash' => $hash, 'processed' => false, 'source' => 'esi', 'added' => $mdb->now()]);
            return 1;
        } catch (MongoDuplicateKeyException $ex) {
            // ignore it *sigh*
        }
    }
    return 0;
}

function fail($guzzer, $params, $ex) 
{
    global $mdb;

    $row = $params['row'];
    $esi = $params['esi'];
    $charID = $row['characterID'];
    $code = $ex->getCode();

    switch ($code) {
        case 403: // No permission
        case 404:
            $mdb->remove("scopes", $row);
            $esi->remove($charID);
            break;
        case 500:
        case 502: // Server error, try again in 5 minutes
        case 503:
            $esi->setTime($charID, time() + 300);
            break;
        default:
            echo "killmail: " . $ex->getMessage() . "\n";
    }
}

function accessTokenFail(&$guzzler, &$params, $ex)
{
    global $mdb;

    $row = $params['row'];
    $esi = $params['esi'];
    $charID = $row['characterID'];
    $code = $ex->getCode();

    switch ($code) {
        case 400:
        case 403: // No permission
        case 404:
            $mdb->remove("scopes", $row);
            $esi->remove($charID);
            break;
        case 500:
        case 502: // Server error, try again in 5 minutes
            $esi->setTime($charID, time() + 300);
            break;
        default:
            echo "token: " . $ex->getMessage() . "\n";
    }
}