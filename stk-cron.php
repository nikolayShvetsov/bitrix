<?php
ini_set('display_errors',1);
ini_set("max_execution_time", 300000000);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

use My\Parser\Stk;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Application as App;

$connection = App::getConnection();


if (\Bitrix\Main\Loader::includeModule("my.parser")) {

    Stk::cron();

}
