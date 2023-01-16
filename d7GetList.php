<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

$elements = ElementCatalogTable::getList([
    'select' => ['ID', 'NAME', 'DETAIL_PICTURE'],
    'filter' => [
        'ID' => $elementId,
    ],
])->fetchCollection();
foreach ($elements as $element) {
    var_dump($element->getName());
    // string(42) "Штаны Цветочная Поляна"
}
