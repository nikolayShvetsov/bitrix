<?php

namespace My\Parser;

ini_set('display_errors',1);
ini_set("max_execution_time", 300000000);

use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Application as App;

$connection = App::getConnection();


class Stk extends Parser
{

    /**
     * Парсинг страницы сайта, указанной для товара в xml-прайсе
     */
    public static function parseSite($url, $productId) {

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
     * получить из базы товары с одинаковой ценой закупки и розничной ценой
     */
    public static function getProductsWithTwoPrices($connection) {

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
     * сравнение закупочной и розничной цен у товара
     */
    public static function comparePricesOfProduct($products) {
        $needDeactivateProducts = [];
        foreach ($products as $productId => $product) {

            if (!is_null($product['OUR_PRICE']) && $product['PRICE'] <= $product['OUR_PRICE']) {
                $needDeactivateProducts[] = $productId;
            }
        }

        return $needDeactivateProducts;
    }


    /**
     * Получение всех продуктов из xml-файла
     */
    public static function getProductsFromXml($path, $categories, $quantityProducts) {

        $products = [];
        $reader2 = new \XMLReader();
        $reader2->open($path);
        while ($reader2->read()) {
            if ($reader2->nodeType == \XMLReader::ELEMENT) {
                if ($reader2->localName == 'offer') {
                    $value = $reader2->expand(new \DOMDocument());
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
//                    $products[$id]['pic'] = parent::getPic($products[$id]['picMan'], $id);


                    if ($products[$id]['unit'] == "") {
                        $products[$id]['unit'] = $data['param'][4];
                    }

                    // если товар продается не штучно, а метрами и пр., переделываем в штуки и ставим кратность 1
                    if (($products[$id]['kratnost'] != 1) && (stripos($products[$id]['unit'], 'шт') == false) && (array_key_exists($products[$id]['code'] , $quantityProducts))) {
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
     * Создание товара на основе предоставленных данных
     */
    public static function createProduct ($product, $productId, $parseContent) {

        if(parent::checkCodeJDE($product['code'])) {

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

            $sectionId = parent::getSectionId($category);

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
     * объединяем данные с XML и CSV
     */
    public static function getActualProducts($productsXML, $productsCSV, $productFromMatrixCsv) {

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


    /**
     * Получаем ID нужных товаров из базы
     */
    function getIdsFromDB($quantityProducts, $connection) {

        $query = 'SELECT
    b_iblock_element.ID,
    b_iblock_element_property.VALUE as SITE_PARS,
    (SELECT VALUE FROM b_iblock_element_property WHERE b_iblock_element_property.IBLOCK_ELEMENT_ID = b_iblock_element.ID AND b_iblock_element_property.IBLOCK_PROPERTY_ID = 10655) as CODE_JDE
FROM
    `b_iblock_element`
LEFT JOIN b_iblock_element_property ON ((b_iblock_element.ID = b_iblock_element_property.IBLOCK_ELEMENT_ID) AND b_iblock_element_property.IBLOCK_PROPERTY_ID = 10632)
WHERE b_iblock_element.IBLOCK_ID = 59 AND b_iblock_element_property.VALUE LIKE "%santech%"';


        $result = $connection->query($query);
        $arrResults = [];
        $arrIds = [];

        while($arrResult = $result->fetch()) {
            $arrResults[$arrResult['CODE_JDE']]['ID'] = $arrResult['ID'];
        }

        foreach($arrResults as $code => $product) {
            if (array_key_exists($code, $quantityProducts)) {
                $arrIds[$code]['ID'] = $product['ID'];
            }
        }

        return $arrIds;

    }

}
