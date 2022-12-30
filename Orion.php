<?php

namespace My\Parser;

ini_set('display_errors',1);
ini_set("max_execution_time", 300000000);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");


class Orion extends Parser
{
    const URL  = 'http://optvideo.com/market/api/api.php';
    const CKEY ='';
    CONST CAMPAIGNID = '';


    /**
     * Получаем характеристики продукта из API
     */
    public static function getProductFromAPI($id) {

        $campaignid = self::CAMPAIGNID;
        $ckey = self::CKEY;
        $url = self::URL;

        $post = [
            "PacketType" => "GetProductCharacteristics",
            "ClientId"   => $campaignid,
            "auth_token" => $ckey,
            "Products"   => [
                ["ProductId" => $id],
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
        $json = curl_exec($ch);
        curl_close($ch);

        $array = json_decode($json, true);
        $arr = $array['Products'][0]['Properties'];
        $arrResult = [];

        $i = 0;
        foreach ($arr as $property) {
            if(!empty($property["PropertiesGroup"])) {
                $arrResult[$property["PropertiesGroup"]][$i][$property["PropertiesName"]] = $property["PropertiesValue"];
            } else {
                $arrResult[0][$i][$property["PropertiesName"]] = $property["PropertiesValue"];
            }

            $i++;

        }

        return $arrResult;
    }


    /**
     * Проверка строки на точку с запятой
     */
    public static function checkRow($string) {

        $string = str_replace(array('&quot;', '&amp;'), '', $string);
        $pattern = '/(;\")(.*)(;)(.*)(\";)([0-9]{5,7});/m';
        preg_match($pattern, $string, $matches);

        if(!empty($matches)) {
            $replacement = "$1$2$4$5$6;";
            $row2 = preg_replace($pattern, $replacement, $string);

            $row = self::checkRow($row2);
        } else {
            $row = $string;
        }

        return $row;
    }


    /**
     * Парсинг csv-файла
     */
    public static function parseCsv($file, $idCode) {
        $arrMatrix = file($file);
        $arrHeaders = explode(';', $arrMatrix[0]);

        unset($arrMatrix[0]);
        $arrRes = [];
        foreach ($arrMatrix as $row) {

            $row = self::checkRow($row);

            $arrLine = explode(';', $row);
            $code = trim($arrLine[$idCode]);

            if (ctype_digit($code)) {
                foreach ($arrHeaders as $key => $header) {
                    $arrRes[$code][trim($header)] = trim($arrLine[$key]);
                }
            }
        }
        return $arrRes;
    }


    /**
     * Получение всех продуктов из xml-файла
     */
    public static function getProductsFromXml($path, $categories) {

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
                    $name = trim($data["model"]);
                    $name = str_replace(array("\r\n", "\n", "<br>"), "", $name);

                    if ((strpos($name, '"') != false) || (strpos($name, "'") != false)){
                        $name = str_replace(array('"', "'"), '', $name);
                    }

                    $products[$id]['name'] = $name;
                    $products[$id]['price'] = (float)$data["price"];
                    $products[$id]['val'] = $data["currencyId"];
                    $products[$id]['catId'] = $data['categoryId'];
                    $products[$id]['parentCatId'] = $categories[$products[$id]['catId']]['parent'];
                    $products[$id]['parentName'] = $categories[$products[$id]['parentCatId']]['name']; // cat1
                    $products[$id]['catName'] = $categories[$products[$id]['catId']]['name']; // cat2
                    $products[$id]['available'] = $data["@attributes"]["available"];
                    $products[$id]['brand'] = $data["vendor"];
                    $products[$id]['picMan'] = $data["picture"];
                    $products[$id]['barCode'] = $data["barcode"];
                    $products[$id]['weight'] = ((array)$sx->xpath('param[@name="ProductWeight"]')[0])[0];
                    $products[$id]['retail'] = ((array)$sx->xpath('param[@name="MinRetailPrice"]')[0])[0];
                    $products[$id]['multiplicity'] = ((array)$sx->xpath('param[@name="Multiplicity"]')[0])[0];
                    $products[$id]['brandId'] = ((array)$sx->xpath('param[@name="BrandId"]')[0])[0];
                    $products[$id]['volume'] = ((array)$sx->xpath('param[@name="ProductVolume"]')[0])[0];
                    $products[$id]['codeMan'] = ((array)$sx->xpath('param[@name="ManufacturerArticle"]')[0])[0];

                }
            }

        }

        return $products;
    }


    /**
     * Получение продуктов, которые есть в xml-файле и в csv-файле
     */
    public static function getActualProducts($xmlProducts,$productsCsv) {

        $products = [];
        $products = self::parsePropertiesProduct($xmlProducts, $productsCsv, $products);
        $products = self::parsePropertiesProduct($productsCsv, $xmlProducts, $products);

        return $products;
    }


    /**
     * Создание товара в базе
     */
    public static function createProduct($product, $resultProperties) {

        if(parent::checkCodeJDE($product['code'])) { // если товара с таким кодом нет в базе

            $el = new CIBlockElement;

            // формируем массив с доп полями элемента
            $PROP = array();
            $PROP[10653] = "Y";
            $PROP[10630] = $product['vendorCode'];
            $PROP[10631] = $product['volume'];
            $PROP[10632] = 'http://optvideo.com/';
            $PROP[10633] = $product['siteParsLink'];
            $PROP[10634] = $product['brand'];
            $PROP[10635] = $product['country'];
            $PROP[10636] = $product['barCode'];
            $PROP[10643] = $product['picMan'];
            $PROP[10644] = $product['parentName']; // CAT_MAN1
            $PROP[10654] = $product['catName']; // CAT_MAN2
            $PROP[10650] = $product['kratnost'];
            $PROP[10651] = $product['unit'];
            $PROP[10652] = $product['kratnost'];
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
     * Изменение товара в базе
     */
    public static function updateProduct($productId, $product, $resultProperties) {

    }


    /**
     * Парсинг всех полей товара
     */
    public static function parsePropertiesProduct($arr, $secondArr, $products) {

        foreach ($arr as $key => $arrProduct) {
            if (array_key_exists($key, $secondArr)) {
                foreach ($arrProduct as $keyPr => $property) {
                    $search = array_search($property,$products[$key]);

                    if(empty($search)) {
                        $products[$key][$keyPr] = $property;
                    }

                }
            }
        }

        return $products;
    }

}
