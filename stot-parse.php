<?php
ini_set('display_errors',1);
ini_set("max_execution_time", 300000000);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
use Bitrix\Main\Application as App;
use Bitrix\Main\Diag\Debug;

$connection = App::getConnection();


/**
 * Получение картинки для товара
 */
function getPic($url, $id) {

    $pic = '';
    $filename = '/home/bitrix/ext_www/omess.ru/upload/stot-new/' . $id . '.jpg';

    if (!file_exists($filename)) { // если файла еще нет на сервере
        $headers = get_headers($url);
        $answer = $headers[0];
        if (strpos($answer, '200') != false) {
            $img = $filename;
            file_put_contents($img, file_get_contents($url));
            $pic = '/upload/stot-new/' . $id . '.jpg';
        }
    } else {
        $pic = '/upload/stot-new/' . $id . '.jpg';
    }

    return $pic;
}


/**
 * Получение всех продуктов из базы данных
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
WHERE b_iblock_element.IBLOCK_ID = 59 AND b_iblock_element_property.VALUE LIKE "%stot%"';


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
 * Обновление товара в базе
 */
function updateProduct($id, $article, $product) {

    echo '<pre>';
    var_dump($product);
    echo '</pre>';

    die();

    // обновляем закупочную цену у товара
    $type = 2;
    updatePrice($type, $product['price'], $id);

    // обнуляем розничную цену у товара
    $type = 1;
//    updatePrice($type, 0, $id);


    $el = new CIBlockElement;

    $PROP = array();
    $PROP[10653] = "Y"; // MADE_CRON
    $PROP[10631] = $product['volume'];
    $PROP[10632] = 'https://stot.ru';
    $PROP[10633] = $product['siteParsLink'];
    $PROP[10634] = $product['brand'];
    $PROP[10637] = $article;
    $PROP[10643] = $product['pic'];
    $PROP[10644] = $product['cat1']; // CAT_MAN1
    $PROP[10654] = $product['cat2']; // CAT_MAN2
    $PROP[10655] = $article; // CODE_JDE

    $height = (!empty($product['height'])) ? $product['height'] : $product['heightDim'];
    $width = (!empty($product['width'])) ? $product['width'] : $product['widthDim'];
    $depth = (!empty($product['depth'])) ? $product['depth'] : $product['depthDim'];

    $arLoadProductArray = Array(
        "MODIFIED_BY"    => 1, // элемент изменен текущим пользователем
        "PROPERTY_VALUES"=> $PROP,
        "ACTIVE"         => "Y",
        "DETAIL_TEXT"    => $product['bodyOldXml'],
        "PREVIEW_TEXT"    => $product['body'],
        "DETAIL_PICTURE"    => CFile::MakeFileArray($_SERVER["DOCUMENT_ROOT"].$pic),
        "PREVIEW_PICTURE"    => CFile::MakeFileArray($_SERVER["DOCUMENT_ROOT"].$pic)
    );

    $el->Update($id, $arLoadProductArray);

    $arFields = array(
        "VAT_ID" => 1,
        "VAT_INCLUDED" => "Y",
        "WIDTH" => $width,
        "LENGTH" => $depth,
        "HEIGHT" => $height,
        "QUANTITY" => $product['quantity'],
        "WEIGHT" => $product['weight']
    );
    \Bitrix\Catalog\Model\Product::Update($id, $arFields);

}


/**
 * Обнуление остатков товара в базе
 */
function nullQuantity($arrResults, $name, $connection) {

    foreach($arrResults[$name] as $name => $product) {
        $id = $product['ID'];

        // обнуляем закупочную цену у товара
        $arFields4 = array(
            "PRODUCT_ID" => $arrResults[$name]['ID'],
            "CATALOG_GROUP_ID" => 2,
            "PRICE" => 0,
            "CURRENCY" => "RUB"
        );

        // обнуляем розничную цену у товара
        $arFields5 = array(
            "PRODUCT_ID" => $arrResults[$name]['ID'],
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


/**
 * Скачиваем файл по фтп
 */
function getFileFromFTP() {

    $path = '';
    $ftp_server = '178.209.104.26';
    $conn_id = ftp_connect($ftp_server) or die("Не удалось установить соединение с $ftp_server");
    $login_result = ftp_login($conn_id, 'ost', 'ost');
    ftp_pasv($conn_id, true);

// Получить содержимое директории
    $contents = ftp_nlist($conn_id, '');
    $file = $contents[0];
    $handle = fopen(__DIR__ . '/' . $file, 'w');

    if (ftp_fget($conn_id, $handle, $file, FTP_ASCII, 0)) {
        $path = '/home/bitrix/ext_www/mbbo.ru/' . $file;
    } else {
        echo 'Ошибка';
    }

// Закрытие файла и соединения
    fclose($handle);
    ftp_close($conn_id);


    return $path;
}


/**
 * Получаем продукты из XML-файла
 */
function getProductsFromXML($path) {

    $reader = new XMLReader();
    $reader->open($path);
    $products = [];

    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT) {
            if ($reader->localName == 'Товар') {
                $value = $reader->expand(new DOMDocument());
                $sx = simplexml_import_dom($value);
                $data = (array)$sx;
                $attributes = $data['@attributes'];
                $code = $attributes["Код"];
                $products[$code]['name'] = $attributes["Наименование"];
                $products[$code]['quantity'] = $attributes["Свободно"];
                $products[$code]['price'] = $attributes["Прайс"];
                $products[$code]['weight'] = $attributes["Вес"];
                $products[$code]['volume'] = $attributes["Объём"];
            }
        }
    }

    return $products;
}


/**
 * Получаем продукты из старого XML-файла
 */
function getProductsFromOldXML($path) {

    $priceResult = [];
    $reader = new XMLReader();
    $reader->open($path);
    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT) {
            if ($reader->localName == 'product') {
                $value = $reader->expand(new DOMDocument());
                $sx = simplexml_import_dom($value);
                $data = (array)$sx;
                $name = $data["model"];
                $name = trim(str_replace('"', '', $name));
                $article = $data["product_id"];
                if(strlen($article) == 4) {
                    $article = '00' . $article;
                } elseif(strlen($article) == 5) {
                    $article = '0' . $article;
                }
                $picMan = $data["image"];
                $category = $data["category"];
                $body = htmlentities($data["description"]);
                $material = '';
                $volume = '';
                $brand = '';
                $diametr = '';
                $shortDesc = '';
                $status = '';
                $garanty = '';
                $width = '';
                $depth = '';
                $height = '';

                $arr = (array)$data["all_attributes"];

                // аттрибуты все разные у товаров
                foreach ($arr["attribute"] as $key => $attribute) {
                    if ($attribute['name'] == 'Материал') {
                        $material = $attribute;
                    }
                    if ($attribute['name'] == 'Объем упаковки (дм. куб.)') {
                        $volume = $attribute;
                    }
                    if ($attribute['name'] == 'Бренд') {
                        $brand = $attribute;
                    }
                    if ($attribute['name'] == 'Диаметр') {
                        $diametr = $attribute;
                    }
                    if ($attribute['name'] == 'Краткое наименование') {
                        $shortDesc = strip_tags($attribute);
                    }
                    if ($attribute['name'] == 'Статус (акции, новинки...)') {
                        $status = $attribute;
                    }
                    if ($attribute['name'] == 'Гарантия') {
                        $garanty = $attribute;
                    }
                    if ($attribute['name'] == 'Ширина (см.)') {
                        $width = $attribute;
                    }
                    if ($attribute['name'] == 'Длина (см.)') {
                        $depth = $attribute;
                    }
                    if ($attribute['name'] == 'Высота (см.)') {
                        $height = $attribute;
                    }
                }

                $priceResult[$article]['material'] = html_entity_decode($material);
                $priceResult[$article]['volume'] = html_entity_decode($volume);
                $priceResult[$article]['brand'] = html_entity_decode($brand);
                $priceResult[$article]['diametr'] = html_entity_decode($diametr);
                $priceResult[$article]['shortDesc'] = html_entity_decode($shortDesc);
                $priceResult[$article]['status'] = html_entity_decode($status);
                $priceResult[$article]['garanty'] = html_entity_decode($garanty);
                $priceResult[$article]['width'] = html_entity_decode($width);
                $priceResult[$article]['depth'] = html_entity_decode($depth);
                $priceResult[$article]['height'] = html_entity_decode($height);
                $priceResult[$article]['picMan'] = $picMan;
                $priceResult[$article]['bodyOldXml'] = $body;
                $priceResult[$article]['categoryOldXml'] = $category;

                $i++;
            }
        }
    }

    return $priceResult;
}


/**
 * Получаем продукты из спаршенного CSV-файла
 */
function getProductsFromCSV($file) {

    $arrMatrix = file($file);
    unset($arrMatrix[0]);
    $arrResult = [];
    foreach ($arrMatrix as $row) {
        $arrLine = explode(';', $row);
        $code = trim($arrLine[3]);
        if (strlen($code) == 4) {
            $code = '00' . $code;
        } elseif (strlen($code) == 5) {
            $code = '0' . $code;
        }
        $arrResult[$code]['NAME'] = $arrLine[2];
        $arrResult[$code]['PIC'] = $arrLine[0];
        $arrResult[$code]['MORE_FOTOS'] = $arrLine[1];
        $arrResult[$code]['CAT1'] = $arrLine[4];
        $arrResult[$code]['CAT2'] = $arrLine[5];
        $arrResult[$code]['SITE_PARS_LINK'] = $arrLine[6];
        $arrResult[$code]['BODY'] = $arrLine[7];
    }
     return $arrResult;
}


/**
 * Объединяем товары в наличии с данными из файла-матрицы и спаршенного csv-файла
 */
function getActualProducts($productsXML, $productsOldXML, $productsFromCsv) {

    $actualProducts = [];
    foreach ($productsXML as $productId => $product) {

        if ($productsOldXML[$productId] && $productsFromCsv[$productId]) {
            $actualProducts[$productId]['name'] = $product['name'];
            $actualProducts[$productId]['quantity'] = $product['quantity'];
            $actualProducts[$productId]['price'] = $product['price'];
            $actualProducts[$productId]['weight'] = $product['weight'];
            $actualProducts[$productId]['volume'] = $product['volume'];
            $actualProducts[$productId]['material'] = $productsOldXML[$productId]['material'];
            $actualProducts[$productId]['volume'] = $productsOldXML[$productId]['volume'];
            $actualProducts[$productId]['brand'] = $productsOldXML[$productId]['brand'];
            $actualProducts[$productId]['diametr'] = $productsOldXML[$productId]['diametr'];
            $actualProducts[$productId]['shortDesc'] = $productsOldXML[$productId]['shortDesc'];
            $actualProducts[$productId]['status'] = $productsOldXML[$productId]['status'];
            $actualProducts[$productId]['garanty'] = $productsOldXML[$productId]['garanty'];
            $actualProducts[$productId]['width'] = $productsOldXML[$productId]['width'];
            $actualProducts[$productId]['depth'] = $productsOldXML[$productId]['depth'];
            $actualProducts[$productId]['height'] = $productsOldXML[$productId]['height'];
            $actualProducts[$productId]['picMan'] = $productsOldXML[$productId]['picMan'];
            $actualProducts[$productId]['bodyOldXml'] = $productsOldXML[$productId]['bodyOldXml'];
            $actualProducts[$productId]['categoryOldXml'] = $productsOldXML[$productId]['categoryOldXml'];
            $actualProducts[$productId]['pic'] = getPic($productsFromCsv[$productId]['PIC'], $productId);
            $actualProducts[$productId]['moreFotos'] = $productsFromCsv[$productId]['MORE_FOTOS'];
            $actualProducts[$productId]['cat1'] = $productsFromCsv[$productId]['CAT1'];
            $actualProducts[$productId]['cat2'] = $productsFromCsv[$productId]['CAT2'];
            $actualProducts[$productId]['siteParseLink'] = $productsFromCsv[$productId]['SITE_PARS_LINK'];
            $actualProducts[$productId]['body'] = $productsFromCsv[$productId]['BODY'];

        }
    }

    return $actualProducts;
}


/**
 * Создание нового товара в базе
 */
function createProduct($product) {

}


/**
 * Обновление цены у товара
 */
function updatePrice($type, $price, $id) {
    $arFields = array(
        "CATALOG_GROUP_ID" => $type,
        "PRICE" => $price,
        "CURRENCY" => "RUB"
    );
    \Bitrix\Catalog\Model\Price::Update($id, $arFields);
}



$productsDB = getProductsFromDB($connection);
//$pathXml = getFileFromFTP();
$pathXml = '/home/bitrix/ext_www/mbbo.ru/OstSTOT.xml';
$productsXML = getProductsFromXML($pathXml);
$pathOldXML = '/home/bitrix/ext_www/mbbo.ru/priceStot.xml';
$productsOldXML = getProductsFromOldXML($pathOldXML);
$fileCsv = '/home/bitrix/ext_www/mbbo.ru/stot.ru.csv';
$productsFromCsv = getProductsFromCSV($fileCsv);
$actualProducts = getActualProducts($productsXML, $productsOldXML, $productsFromCsv);



foreach ($actualProducts as $article => $product) {

    if (array_key_exists($product['name'], $productsDB)) {

        $id = $productsDB[$product['name']]['ID'];
        updateProduct($id, $article, $product);
        unset($productsDB[$product['name']]);

    } else {
//        createProduct($product);
    }
}

//nullQuantity($productsDB, $name, $connection);
