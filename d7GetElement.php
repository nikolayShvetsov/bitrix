<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

\Bitrix\Main\Loader::IncludeModule("iblock");

$product = \Bitrix\Iblock\Elements\ElementCatalogmbboTable::getByPrimary(1397003, [
    'select' => ['ID', 'NAME'],
])->fetch();
