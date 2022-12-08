<?php
ini_set('display_errors',1);
ini_set("max_execution_time", 300000000);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Application as App;

$connection = App::getConnection();



/**
 * Обновление продукта, который уже есть в базе данных
 */
function updateProduct ($arrResults, $product, $connection) {

    $name = $product['name'];
    $price = $product['price'];
    $kratnost = $product['kratnost'];
    $quantity = $product['quantity'];

    // обновляем закупочную цену у товара
    $arFields3 = array(
        "CATALOG_GROUP_ID" => 2,
        "PRICE" => $price,
        "CURRENCY" => "RUB"
    );
    \Bitrix\Catalog\Model\Price::Update($arrResults[$name]['ID'], $arFields3);


    // обнуляем розничную цену у товара
    $arFields4 = array(
        "CATALOG_GROUP_ID" => 1,
        "PRICE" => false,
        "CURRENCY" => "RUB"
    );
    \Bitrix\Catalog\Model\Price::Update($arrResults[$name]['ID'], $arFields4);


    $query3 = 'UPDATE `b_iblock_element_property` SET `VALUE` = ' . $kratnost . ' WHERE IBLOCK_ELEMENT_ID = ' . $arrResults[$name]['ID'] . ' AND IBLOCK_PROPERTY_ID = 10650'; // меняем кратность
    $query4 = 'UPDATE `b_iblock_element_property` SET `VALUE` = "шт" WHERE IBLOCK_ELEMENT_ID = ' . $arrResults[$name]['ID'] . ' AND IBLOCK_PROPERTY_ID = 10651'; // меняем unit
    $query2 = 'UPDATE `b_catalog_product` SET `QUANTITY` = ' . $quantity . ' WHERE `b_catalog_product`.`ID` = ' . $arrResults[$name]['ID'];

    $result2 = $connection->query($query2);
    $result3 = $connection->query($query3);
    $result4 = $connection->query($query4);
    unset($arrResults[$name]); // после обновления остатка товара удаляем его из массива всех товаров

    return $arrResults;
}



/**
 * Парсинг csv-файла с последующей передачей данных в продукт
 */
function parseCsv($file) {
    $arrMatrix = file($file);
    unset($arrMatrix[0]);
    $arrRes = [];
    foreach ($arrMatrix as $row) {
        $arrLine = explode(';', $row);
        $code = trim($arrLine[6]);
        $arrRes[$code]['NAME'] = $arrLine[0];
        $arrRes[$code]['BRAND'] = $arrLine[2];
        $arrRes[$code]['CAT1'] = $arrLine[3];
        $arrRes[$code]['CAT2'] = $arrLine[4];
        $arrRes[$code]['CAT3'] = $arrLine[5];
    }
    return $arrRes;
}



/**
 * Получение всех продуктов из базы
 */
function getProductsFromDB($connection) {

    $query = 'SELECT
    b_iblock_element.NAME,
    b_iblock_element.ID,
    b_catalog_price.PRICE,
    b_catalog_product.QUANTITY,
    b_iblock_element_property.VALUE as SITE_PARS
FROM
    `b_iblock_element`
LEFT JOIN b_catalog_price ON b_catalog_price.PRODUCT_ID = b_iblock_element.ID
LEFT JOIN b_catalog_product ON b_catalog_product.ID = b_iblock_element.ID
LEFT JOIN b_iblock_element_property ON ((b_iblock_element.ID = b_iblock_element_property.IBLOCK_ELEMENT_ID) AND b_iblock_element_property.IBLOCK_PROPERTY_ID = 10632)
WHERE b_iblock_element.IBLOCK_ID = 59 AND b_iblock_element_property.VALUE LIKE "%santech%"';


    $result = $connection->query($query);
    $arrResults = [];

    while($arrResult = $result->fetch()) {
        $arrResults[$arrResult['NAME']]['ID'] = $arrResult['ID'];
        $arrResults[$arrResult['NAME']]['PRICE'] = $arrResult['PRICE'];
        $arrResults[$arrResult['NAME']]['QUANTITY'] = $arrResult['QUANTITY'];
    }

    return $arrResults;
}



/**
 * Получение списка всех категорий из xml-файла
 */
function getCategoriesFromXml($path) {

    $reader = new XMLReader();
    $reader->open($path);
    $i = 0;
    $categories = [];

    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT) {
            if ($reader->localName == 'category') {
                $value = $reader->expand(new DOMDocument());
                $sx = simplexml_import_dom($value);
                $data = (array)$sx;
                $id = $data["@attributes"]['id'];
                $parentId = $data["@attributes"]['parentId'];
                $nameCategory = $data[0];
                $categories[$id]['name'] = $nameCategory;
                $categories[$id]['parent'] = $parentId;
            }
        }
    }

    return $categories;
}



/**
 * Получение всех продуктов из xml-файла
 */
function getProductsFromXml ($path, $categories) {

    $products = [];
    $reader2 = new XMLReader();
    $reader2->open($path);
    while ($reader2->read()) {
        if ($reader2->nodeType == XMLReader::ELEMENT) {
            if ($reader2->localName == 'offer') {
                $value = $reader2->expand(new DOMDocument());
                $sx = simplexml_import_dom($value);
                $data = (array)$sx;
                $id = $data["@attributes"]['id'];
                $name = trim($data["name"]);
                $name = str_replace(array("\r\n", "\n", "<br>"), "", $name);

                if(strpos($name, '"') != false) {
                    $name = str_replace('"', '""', $name);
                    $name = '"' . $name . '"';
                }

                $products[$id]['name'] = $name;
                $products[$id]['quantity'] = $data["@attributes"]['count'];
                $products[$id]['price'] = (float)$data["price"];
                $products[$id]['val'] = $data["currencyId"];
                $products[$id]['catId'] = $data['categoryId'];
                $products[$id]['parentCatId'] = $categories[$products[$id]['catId']]['parent'];
                $products[$id]['parentName'] = $categories[$products[$id]['parentCatId']]['name']; // cat1
                $products[$id]['catName'] = $categories[$products[$id]['catId']]['name']; // cat2
                $products[$id]['kratnost'] = (float)$data['param'][5];
                $products[$id]['available'] = $data["@attributes"]["available"];
                $products[$id]['siteParsLink'] = $data["url"];
                $products[$id]['picMan'] = $data["picture"];
                $products[$id]['brand'] = $data["vendor"];
                $products[$id]['country'] = $data["country_of_origin"];
                $products[$id]['barCode'] = $data["barcode"];
                $products[$id]['dimensions'] = $data['dimensions'];
                $arrDimentions = explode('/', $products[$id]['dimensions']);
                $products[$id]['heightDim'] = $arrDimentions[0];
                $products[$id]['widthDim'] = $arrDimentions[1];
                $products[$id]['depthDim'] = $arrDimentions[2];
                $products[$id]['code'] = $data["vendorCode"];
                $products[$id]['weight'] = $data["weight"];
                $stringParams = $data['param'][8];
                $arrParams = explode(' ', $stringParams);
                $products[$id]['height'] = str_replace(array('В=','СМ'), '', $arrParams[1]);
                $products[$id]['width'] = str_replace(array('Ш=','СМ'), '', $arrParams[2]);
                $products[$id]['depth'] = str_replace(array('Г=','СМ'), '', $arrParams[3]);
                $products[$id]['volume'] = str_replace(array('ОБЪЁМ=','М3'), '', $arrParams[5]);
                $products[$id]['unit'] = $arrParams[0];
                $products[$id]['pic'] = getPic($products[$id]['picMan'], $id);

                if ($products[$id]['unit'] == "") {
                    $products[$id]['unit'] = $data['param'][4];
                }

                // если товар продается не штучно, а метрами и пр., переделываем в штуки и ставим кратность 1
                if (($products[$id]['kratnost'] != 1) && (stripos($products[$id]['unit'], 'шт') == false)) {
                    $products[$id]['price'] = $products[$id]['price'] * $products[$id]['kratnost'];
                    $products[$id]['unit'] = 'шт';
                    $products[$id]['kratnost'] = 1;
                }
            }
        }

    }
    return $products;
}



/**
 * Парсинг страницы сайта, указанной для товара в xml-прайсе
 */
function parseSite ($url, $productId) {

    $parseContent = [];
    $content = file_get_contents($url);
    $stringBodyWithoutHeader = explode('<table class="property__table js-table-property">', $content, 2)[1];
    $stringBody = explode('<div class="property__button buttons">', $stringBodyWithoutHeader, 2)[0];
    $arrTr = explode('</tr>', $stringBody);
    unset($arrTr[count($arrTr) - 1]);
    $parseContent[$productId]['body'] = '<ul>';

    foreach ($arrTr as $tr) {
        $nameTr1 = explode('<td class="property__table-name', $tr, 2)[1];
        $nameTr2 = explode('</td>', $nameTr1, 2)[0];
        $nameTr = trim(strip_tags($nameTr2));
        $nameTr = str_replace(array('item-specs-col">', '">'), '', $nameTr);
        $valueTr1 = explode('<td class="property__table-value', $tr, 2)[1];
        $valueTr2 = explode('</td>', $valueTr1, 2)[0];
        $valueTr = strip_tags($valueTr2);
        $valueTr = str_replace(array('item-specs-col" itemprop="gtin">', 'item-specs-col">', '">'), '', $valueTr);
        $parseContent[$productId]['body'] .= '<li>' . $nameTr . ': ' . $valueTr . '</li>';
    }

    $stringH1WithoutHeader = explode('<h1 class="product__title" itemprop="name">', $content, 2)[1];
    $stringH1 = explode('</h1>', $stringH1WithoutHeader, 2)[0];
    $parseContent[$productId]['body'] .= '</ul>';
    $parseContent[$productId]['bigName'] = trim($stringH1);


    return $parseContent;
}



/**
 * Получение картинки для товара
 */
function getPic ($url, $id) {

    $headers = get_headers($url);
    $answer = $headers[0];
    $pic = '';
    if ((strpos($answer, '200') != false) || (strpos($answer, '301') != false)) {
        $img = '/home/bitrix/ext_www/omess.ru/upload/stk-new/' . $id . '.jpg';
        file_put_contents($img, file_get_contents($url));
        $pic = '/upload/stk-new/' . $id . '.jpg';
    }

    return $pic;
}



/**
 * Создание товара на основе предоставленных данных
 */
function createProduct ($product, $productId) {

        $el = new CIBlockElement;

        // формируем массив с доп полями элемента
        $PROP = array();
        $PROP[10653] = "Y";
        $PROP[10630] = $product['code'];
        $PROP[10631] = $product['volume'];
        $PROP[10632] = 'https://santech.ru';
        $PROP[10633] = $product['siteParsLink'];
        $PROP[10634] = $product['brand'];
        $PROP[10635] = $product['country'];
        $PROP[10636] = $product['barCode'];
        $PROP[10637] = $productId;
        $PROP[10643] = $product['picMan'];
        $PROP[10644] = $product['parentName']; // cat1
        $PROP[10654] = $product['catName']; // cat2
        $PROP[10650] = $product['kratnost'];
        $PROP[10651] = $product['unit'];
        $PROP[10652] = $product['kratnost'];
        $PROP[10647] = $product['bigName'];

        // параметры создания символьного кода элементы
        $params = Array(
            "max_len" => "100", // обрезает символьный код до 100 символов
            "change_case" => "L", // буквы преобразуются к нижнему регистру
            "replace_space" => "_", // меняем пробелы на нижнее подчеркивание
            "replace_other" => "_", // меняем левые символы на нижнее подчеркивание
            "delete_repeat_replace" => "true", // удаляем повторяющиеся нижние подчеркивания
            "use_google" => "false", // отключаем использование google
        );

        // определяем ID категории элемента по названию
        $sectionId = false;
        $arFilter = array('IBLOCK_ID' => 59, 'NAME' => $product['catName']);
        $arSelect = array('ID');
        $rsSect = CIBlockSection::GetList(
            Array("SORT"=>"ASC"), //сортировка
            $arFilter, //фильтр (выше объявили)
            false, //выводить количество элементов - нет
            $arSelect //выборка вывода, нам нужно только название, описание, картинка
        );
        while ($arSect = $rsSect->GetNext()) {
            $sectionId = $arSect['ID'];
        }

        // проставляем все обязательные для элемента поля
        $arLoadProductArray = Array(
            "MODIFIED_BY"    => 1, // элемент изменен админом
            "IBLOCK_SECTION_ID" => $sectionId,          // элемент лежит в корне раздела
            "IBLOCK_ID"      => 59,
            "PROPERTY_VALUES"=> $PROP,
            "NAME"           => $product['name'],
            "ACTIVE"         => "Y",            // активен
            "PREVIEW_TEXT"   => $product['body'],
            "DETAIL_TEXT"    => $product['body'],
            "DETAIL_PICTURE" => CFile::MakeFileArray($_SERVER["DOCUMENT_ROOT"] . $product['pic']),
            "PREVIEW_PICTURE" => CFile::MakeFileArray($_SERVER["DOCUMENT_ROOT"] . $product['pic']),
            "CODE" => CUtil::translit($product['name'], "ru" , $params)
        );

        // создаем элемент или выводим ошибку
        $PRODUCT_ID = $el->Add($arLoadProductArray);

        // переделываем его в товар
        $arFields = array(
            "ID" => $PRODUCT_ID,
            "VAT_ID" => 1,
            "VAT_INCLUDED" => "Y",
            "WIDTH" => $product['width'],
            "LENGTH" => $product['depth'],
            "HEIGHT" => $product['height'],
            "QUANTITY" => $product['quantity'],
            "WEIGHT" => $product['weight']
        );
        \Bitrix\Catalog\Model\Product::add($arFields);

        // добавляем цену у товара
        $arFields2 = array(
            "PRODUCT_ID" => $PRODUCT_ID,
            "CATALOG_GROUP_ID" => 2,
            "PRICE" => $product['price'],
            "CURRENCY" => "RUB"
        );
        \Bitrix\Catalog\Model\Price::Add($arFields2);

}



/**
 * Обнуление цены и количества у товара, который есть на сайте, но нет в xml-прайсе
 */
function nullQuantity($arrResults, $connection) {

    foreach ($arrResults as $name => $product) { // переобход по всем товарам, которые есть на сайте, но нет в прайсе
        $id = $product['ID'];
        // обновляем закупочную цену у товара
        $arFields4 = array(
            "PRODUCT_ID" => $id,
            "CATALOG_GROUP_ID" => 2,
            "PRICE" => 0,
            "CURRENCY" => "RUB"
        );
        $arFields5 = array(
            "PRODUCT_ID" => $id,
            "CATALOG_GROUP_ID" => 1,
            "PRICE" => 0,
            "CURRENCY" => "RUB"
        );
        \Bitrix\Catalog\Model\Price::Add($arFields4);
        \Bitrix\Catalog\Model\Price::Add($arFields5);

        $query3 = 'UPDATE `b_catalog_product` SET `QUANTITY` = 0 WHERE `b_catalog_product`.`ID` = ' . $id; // ставим у таких товаров количество 0
        $result = $connection->query($query3);
    }
}



$urlCsv = '/home/bitrix/ext_www/mbbo.ru/santech.csv';
$arrRes = parseCsv($urlCsv);
$arrResults = getProductsFromDB($connection);
$path = 'https://www.santech.ru/data/custom_yandexmarket/437.xml';
$categories = getCategoriesFromXml($path);
$products = getProductsFromXml($path, $categories);

foreach($products as $productId => $product) {

    $siteParsLink = $product['siteParsLink'];
    $parentName = $product['parentName'];
    $catName = $product['catName'];

    if (array_key_exists($product['name'], $arrResults)) { // если товар из прайса есть на сайте

        $arrResults = updateProduct($arrResults, $product, $connection);

    } else { // если товара из прайса нет на сайте

        $parseContent = parseSite($siteParsLink, $productId);
        $arrCatNames = [
            'Трубы стальные бесшовные',
            'Трубы стальные электросварные',
            'Трубы стальные ВГП',
            'Металлопрокат',
            'Трубы чугунные безраструбные SML и соединительные детали',
            'Трубы чугунные ЧК и соединительные детали',
            'Трубы асбестоцементные и соединительные детали',
            'Трубы чугунные ВЧШГ и соединительные детали',
        ];

        if ((in_array($parentName, $arrCatNames)) || (in_array($catName, $arrCatNames))) { // если имя категории есть в массиве, то не создаем товар
            continue;
        }

        createProduct($product, $productId);
    }
}

nullQuantity($arrResults, $connection);

