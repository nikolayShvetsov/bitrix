<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
use Bitrix\Main\Application;
use Bitrix\Main\Diag\Debug;

$connection = Application::getConnection();
$result = $connection->query("
...
",10);

// $result = $connection->query('SELECT * FROM table_name ORDER BY id DESC',10); // с указанием LIMIT
// $result = $connection->query('SELECT * FROM table_name ORDER BY id DESC',0,10); // с указанием LIMIT и смещением от начала выборки
echo '<pre>';
while($ar=$result->fetch())

{

    print_r($ar);

}
echo '</pre>';
