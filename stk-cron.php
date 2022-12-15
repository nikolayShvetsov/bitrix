<?php
ini_set('display_errors',1);
ini_set("max_execution_time", 300000000);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Application as App;

$connection = App::getConnection();


/**
 * Обновление цены у товара
 */
function updatePrice($type, $price, $id) {

    $arFields = array(
        "PRODUCT_ID" => $id,
        "CATALOG_GROUP_ID" => $type,
        "PRICE" => $price,
        "CURRENCY" => "RUB"
    );

    $dbPrice = \Bitrix\Catalog\Model\Price::getList([
        "filter" => array(
            "PRODUCT_ID" => $id,
            "CATALOG_GROUP_ID" => $type
        )
    ]);

    if ($arPrice = $dbPrice->fetch()) {
        \Bitrix\Catalog\Model\Price::delete($arPrice['ID']);
        \Bitrix\Catalog\Model\Price::add($arFields, true);

    } else {
        \Bitrix\Catalog\Model\Price::add($arFields, true);
    }

}



/**
 * Обновление продукта, который уже есть в базе данных
 */
function updateProduct($id, $article, $product) {

    $price = (float)$product['price'];
    $zakup = (float)$product['zakup'];
    $rrc = (int)str_replace(',', '.', trim($product['rrc']));
    $extraCharge = $zakup/$price;
    $extraCharge = number_format($extraCharge, 2, '.', '');

    CIBlockElement::SetPropertyValuesEx($id,59,['10656' => $extraCharge]);

    $arFields = array(
        "VAT_ID" => 1,
        "VAT_INCLUDED" => "Y",
        "QUANTITY" => $product['quantity'],
        "WEIGHT" => $product['weight'],
    );
    \Bitrix\Catalog\Model\Product::Update($id, $arFields);

    // обновляем закупочную цену у товара
    $type = 2;
    updatePrice($type, $zakup, $id);

    // обновляем розничную цену у товара
    $type = 1;
    updatePrice($type, $rrc, $id);
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
    b_iblock_element_property.VALUE as SITE_PARS,
    (SELECT VALUE FROM b_iblock_element_property WHERE b_iblock_element_property.IBLOCK_ELEMENT_ID = b_iblock_element.ID AND b_iblock_element_property.IBLOCK_PROPERTY_ID = 10655) as CODE_JDE
FROM
    `b_iblock_element`
LEFT JOIN b_catalog_price ON b_catalog_price.PRODUCT_ID = b_iblock_element.ID
LEFT JOIN b_catalog_product ON b_catalog_product.ID = b_iblock_element.ID
LEFT JOIN b_iblock_element_property ON ((b_iblock_element.ID = b_iblock_element_property.IBLOCK_ELEMENT_ID) AND b_iblock_element_property.IBLOCK_PROPERTY_ID = 10632)
WHERE b_iblock_element.IBLOCK_ID = 59 AND b_iblock_element_property.VALUE LIKE "%santech%"';


    $result = $connection->query($query);
    $arrResults = [];

    while($arrResult = $result->fetch()) {
        $arrResults[$arrResult['CODE_JDE']]['ID'] = $arrResult['ID'];
        $arrResults[$arrResult['CODE_JDE']]['PRICE'] = $arrResult['PRICE'];
        $arrResults[$arrResult['CODE_JDE']]['QUANTITY'] = $arrResult['QUANTITY'];
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
function getProductsFromXml($path, $categories) {

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

                if ((strpos($name, '"') != false) || (strpos($name, "'") != false)){
                    $name = str_replace(array('"', "'"), '', $name);
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
                $products[$id]['vendorCode'] = $data["vendorCode"];
                $products[$id]['code'] = $data['param'][0];
                $products[$id]['weight'] = $data["weight"];
                $stringParams = $data['param'][8];

                foreach ($data['param'] as $keyParam => $param) {
                    if(strpos($param, ' В=') !== false) {
                        $stringParams = $data['param'][$keyParam];
                        break;
                    }
                }

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
function parseSite($url, $productId) {

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
function getPic($url, $id) {

    $pic = '';
    $filename = '/home/bitrix/ext_www/omess.ru/upload/stk-new/' . $id . '.jpg';

    if (!file_exists($filename)) { // если файла еще нет на сервере
        $headers = get_headers($url);
        $answer = $headers[0];
        if (strpos($answer, '200') != false) {
            $img = $filename;
            file_put_contents($img, file_get_contents($url));
            $pic = '/upload/stk-new/' . $id . '.jpg';
        }
    } else {
        $pic = '/upload/stk-new/' . $id . '.jpg';
    }

    return $pic;
}


/**
 * Получение ID категории по ее названию
 */
function getSectionId($name) {

    // определяем ID категории элемента по названию
    $sectionId = false;
    $arFilter = array('IBLOCK_ID' => 59, 'NAME' => $name);
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

    return $sectionId;
}



/**
 * Создание товара на основе предоставленных данных
 */
function createProduct ($product, $productId, $parseContent, $connection) {

    if(checkCodeJDE($product['code'])) {

        $bigName = $parseContent[$productId]['bigName'];
        if ((strpos($bigName, '"') != false) || (strpos($bigName, "'") != false)){
            $bigName = str_replace(array('"', "'"), '', $bigName);
        }

        $body = $parseContent[$productId]['body'];

        $el = new CIBlockElement;

        // формируем массив с доп полями элемента
        $PROP = array();
        $PROP[10653] = "Y";
        $PROP[10630] = $product['vendorCode'];
        $PROP[10631] = $product['volume'];
        $PROP[10632] = 'https://santech.ru';
        $PROP[10633] = $product['siteParsLink'];
        $PROP[10634] = $product['brand'];
        $PROP[10635] = $product['country'];
        $PROP[10636] = $product['barCode'];
        $PROP[10637] = $productId;
        $PROP[10643] = $product['picMan'];
        $PROP[10644] = $product['parentName']; // CAT_MAN1
        $PROP[10654] = $product['catName']; // CAT_MAN2
        $PROP[10650] = $product['kratnost'];
        $PROP[10651] = $product['unit'];
        $PROP[10652] = $product['kratnost'];
        $PROP[10647] = $bigName;
        $PROP[10655] = $product['code']; // CODE_JDE

        // параметры создания символьного кода элементы
        $params = Array(
            "max_len" => "100", // обрезает символьный код до 100 символов
            "change_case" => "L", // буквы преобразуются к нижнему регистру
            "replace_space" => "_", // меняем пробелы на нижнее подчеркивание
            "replace_other" => "_", // меняем левые символы на нижнее подчеркивание
            "delete_repeat_replace" => "true", // удаляем повторяющиеся нижние подчеркивания
            "use_google" => "false", // отключаем использование google
        );

        $category = '';
        if ($product['CAT3']) {
            $category = $product['CAT3'];
        } else {
            $category = $product['catName'];
        }

        $sectionId = getSectionId($category);

        // проставляем все обязательные для элемента поля
        $arLoadProductArray = Array(
            "MODIFIED_BY"    => 1, // элемент изменен админом
            "IBLOCK_SECTION_ID" => $sectionId,          // элемент лежит в корне раздела
            "IBLOCK_ID"      => 59,
            "PROPERTY_VALUES"=> $PROP,
            "NAME"           => $product['name'],
            "ACTIVE"         => "Y",            // активен
            "PREVIEW_TEXT"   => $body,
            "DETAIL_TEXT"    => $body,
            "DETAIL_PICTURE" => CFile::MakeFileArray($_SERVER["DOCUMENT_ROOT"] . $product['pic']),
            "PREVIEW_PICTURE" => CFile::MakeFileArray($_SERVER["DOCUMENT_ROOT"] . $product['pic']),
            "CODE" => CUtil::translit($product['name'], "ru" , $params)
        );

        // создаем элемент
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
}



/**
 * Обнуление цены и количества у товара, который есть на сайте, но нет в xml-прайсе
 */
function nullQuantity($arrResults, $connection) {

    foreach ($arrResults as $code => $product) { // переобход по всем товарам, которые есть на сайте, но нет в прайсе
        $id = $product['ID'];

        updatePrice(1, 0, $id);
        updatePrice(2, 0, $id);

        $query3 = 'UPDATE `b_catalog_product` SET `QUANTITY` = 0 WHERE `b_catalog_product`.`ID` = ' . $id; // ставим у таких товаров количество 0
        $result = $connection->query($query3);
    }
}



/**
 * проверка нет ли на сайте уже товара с таким CODE_JDE
 */
function checkCodeJDE($code) {

    $arFields = [];
    $arSelect = Array("ID");
    $arFilter = Array("IBLOCK_ID"=>59, "PROPERTY_CODE_JDE" => $code);
    $res = CIBlockElement::GetList(Array(), $arFilter, false, false, $arSelect);
    while($ob = $res->GetNextElement())
    {
        $arFields[] = $ob->GetFields();
    }

    return empty($arFields);
}



/**
 * получить из базы товары с одинаковой ценой закупки и розничной ценой
 */
function getProductsWithTwoPrices($connection) {

    $query = 'SELECT
    b_iblock_element.NAME,
    b_iblock_element.ID,
    b_catalog_price.PRICE,
    (SELECT PRICE FROM b_catalog_price WHERE b_catalog_price.PRODUCT_ID = b_iblock_element.ID AND b_catalog_price.CATALOG_GROUP_ID = 2 AND b_catalog_price.PRICE IS NOT NULL) AS OUR_PRICE,
    b_catalog_product.QUANTITY,
    b_iblock_element_property.VALUE as SITE_PARS
    FROM
        `b_iblock_element`
    LEFT JOIN b_catalog_price ON b_catalog_price.PRODUCT_ID = b_iblock_element.ID
    LEFT JOIN b_catalog_product ON b_catalog_product.ID = b_iblock_element.ID
    LEFT JOIN b_iblock_element_property ON ((b_iblock_element.ID = b_iblock_element_property.IBLOCK_ELEMENT_ID) AND b_iblock_element_property.IBLOCK_PROPERTY_ID = 10632)
    WHERE b_iblock_element.IBLOCK_ID = 59 AND b_iblock_element_property.VALUE LIKE "%santech%" AND b_catalog_price.PRICE IS NOT NULL AND b_catalog_price.PRICE != 0 AND b_catalog_price.CATALOG_GROUP_ID != 2';

    $result = $connection->query($query);
    $products = [];

    while($arrResult = $result->fetch()) {
        $products[$arrResult['ID']]['NAME'] = $arrResult['NAME'];
        $products[$arrResult['ID']]['PRICE'] = (int)$arrResult['PRICE'];
        $products[$arrResult['ID']]['OUR_PRICE'] = (int)$arrResult['OUR_PRICE'];
        $products[$arrResult['ID']]['QUANTITY'] = $arrResult['QUANTITY'];
    }

    return $products;
}



/**
 * деактивация товара на сайте
 */
function deactivateProduct($id) {
    $el = new CIBlockElement;
    $arLoadProductArray = Array("ACTIVE" => "N");
    $el->Update($id, $arLoadProductArray);
}



/**
 * сравнение закупочной и розничной цен у товара
 */
function comparePricesOfProduct($products) {
    $needDeactivateProducts = [];
    foreach ($products as $productId => $product) {

        if (!is_null($product['OUR_PRICE']) && $product['PRICE'] <= $product['OUR_PRICE']) {
            $needDeactivateProducts[] = $productId;
        }
    }

    return $needDeactivateProducts;
}



/**
 * объединяем данные с XML и CSV
 */
function getActualProducts($productsXML, $productsCSV, $productFromMatrixCsv) {

    foreach ($productsXML as $id => $product) {
        if (array_key_exists($product['code'], $productFromMatrixCsv)) {
            $productsXML[$id]['CAT1'] = $productsCSV[$product['code']]['CAT1'];
            $productsXML[$id]['CAT2'] = $productsCSV[$product['code']]['CAT2'];
            $productsXML[$id]['CAT3'] = $productsCSV[$product['code']]['CAT3'];
            $productsXML[$id]['zakup'] = $productFromMatrixCsv[$product['code']]['ZAKUP'];
            $productsXML[$id]['rrc'] = $productFromMatrixCsv[$product['code']]['RRC'];
        } else {
            unset($productsXML[$id]);
        }
    }

    return $productsXML;
}


//$productsWithTwoPrices = getProductsWithTwoPrices($connection);
//$needDeactivateProducts = comparePricesOfProduct($productsWithTwoPrices);
//
//
//foreach ($needDeactivateProducts as $productId) {
//    deactivateProduct($productId);
//}


/**
 * получаем цены из матрицы
 */
function getProductsFromMatrix($urlMatrix) {

    $arrMatrix = file($urlMatrix);
    unset($arrMatrix[0]);
    $arrRes = [];
    foreach ($arrMatrix as $row) {
        $arrLine = explode(';', $row);
        $code = trim($arrLine[0]);
        $arrRes[$code]['ZAKUP'] = $arrLine[1];
        $arrRes[$code]['RRC'] = $arrLine[2];
    }
    return $arrRes;
}


$urlCsv = '/home/bitrix/ext_www/mbbo.ru/santech.csv';
$productsCSV = parseCsv($urlCsv);
$productsDB = getProductsFromDB($connection);
$pathXML = 'https://www.santech.ru/data/custom_yandexmarket/437.xml';
$categoriesXML = getCategoriesFromXml($pathXML);
$productsXML = getProductsFromXml($pathXML, $categoriesXML);
$urlMatrix = '/home/bitrix/ext_www/mbbo.ru/matrix.csv';
$productFromMatrixCsv = getProductsFromMatrix($urlMatrix);
$actualProducts = getActualProducts($productsXML, $productsCSV, $productFromMatrixCsv);


foreach($actualProducts as $article => $product) {

    $siteParsLink = $product['siteParsLink'];
    $parentName = $product['parentName'];
    $catName = $product['catName'];

    if(array_key_exists($product['code'], $productsDB)) { // если товар из прайса есть на сайте
        $productId = $productsDB[$product['code']]['ID'];
        updateProduct($productId, $article, $product);
        unset($productsDB[$product['code']]); // после обновления остатка товара удаляем его из массива всех товаров

    } else { // если товара из прайса нет на сайте

//        $parseContent = parseSite($siteParsLink, $productId);
//        $arrCatNames = [
//            'Трубы стальные бесшовные',
//            'Трубы стальные электросварные',
//            'Трубы стальные ВГП',
//            'Металлопрокат',
//            'Трубы чугунные безраструбные SML и соединительные детали',
//            'Трубы чугунные ЧК и соединительные детали',
//            'Трубы асбестоцементные и соединительные детали',
//            'Трубы чугунные ВЧШГ и соединительные детали',
//        ];

//        if ((in_array($parentName, $arrCatNames)) || (in_array($catName, $arrCatNames))) { // если имя категории есть в массиве, то не создаем товар
//            continue;
//        }

//        createProduct($product, $productId, $parseContent, $connection);
    }
}

nullQuantity($productsDB, $connection);

